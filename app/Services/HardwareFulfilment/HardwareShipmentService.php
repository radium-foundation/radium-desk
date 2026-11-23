<?php

namespace App\Services\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentState;
use App\Enums\ShipmentStatus;
use App\Models\HardwareFulfilment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareAwbReconcileOutcome;
use App\Services\HardwareFulfilment\Data\HardwareCourierReassignmentReconcileOutcome;
use App\Services\HardwareFulfilment\Data\HardwareProviderCourierReassignOutcome;
use App\Services\Shipping\Data\ShiprocketAwbResult;
use App\Services\Shipping\Data\ShiprocketCourierOptionsRequest;
use App\Services\Shipping\Data\ShiprocketCreateOrderResult;
use App\Services\Shipping\Data\ShiprocketSearchResult;
use App\Services\Shipping\Data\ShiprocketTrackResult;
use App\Services\Shipping\NullShiprocketGateway;
use App\Services\Shipping\ShiprocketApiAuditContext;
use App\Services\Shipping\ShiprocketApiAuditScope;
use App\Services\Shipping\ShiprocketAwbAssignmentRejection;
use App\Services\Shipping\ShiprocketDisabledException;
use App\Services\Shipping\ShiprocketNonRetryableException;
use App\Services\Shipping\ShiprocketRetryableException;
use App\Services\Shipping\ShiprocketTrackingNormalizer;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class HardwareShipmentService
{
    public function __construct(
        private readonly HardwareShipmentEligibility $eligibility,
        private readonly HardwareShipmentMapper $mapper,
        private readonly HardwareFulfilmentWorkflowService $workflow,
        private readonly ShiprocketGateway $gateway,
        private readonly HardwareFulfilmentParcelSnapshotService $snapshots,
        private readonly HardwareShipmentCourierOptionsService $couriers,
        private readonly HardwareShipmentDocumentsService $documents,
    ) {}

    public function createShipment(HardwareFulfilment $fulfilment, ?User $actor = null): Shipment
    {
        $this->assertNotFrozen($fulfilment);

        try {
            $prepared = DB::transaction(function () use ($fulfilment, $actor): array {
                $locked = HardwareFulfilment::query()
                    ->whereKey($fulfilment->id)
                    ->lockForUpdate()
                    ->with(['commerceOrder.items', 'serials.inventorySerial.product.packaging'])
                    ->firstOrFail();

                $this->assertNotFrozen($locked);

                $existing = $this->existingShipment($locked);
                if ($existing !== null && $existing->isBound()) {
                    $this->syncFulfilment($locked, $existing);

                    return ['done' => $existing];
                }

                $this->snapshots->attachIfEligible($locked, $actor);

                $ready = $this->eligibility->require($locked);
                $this->assertShiprocketShippingMethod($locked);
                $this->assertProviderCallable();

                $courier = $existing === null
                    ? $this->couriers->requireValidSelection($locked)
                    : $this->optionalStoredCourier($locked);

                $shipment = $existing ?? $this->openShipment($locked, $ready, $courier);
                $shipment->attempts = (int) $shipment->attempts + 1;
                $shipment->save();

                return [
                    'fulfilment' => $locked,
                    'shipment' => $shipment->fresh() ?? $shipment,
                    'ready' => $ready,
                ];
            });

            if (isset($prepared['done'])) {
                return $prepared['done'];
            }

            /** @var HardwareFulfilment $locked */
            $locked = $prepared['fulfilment'];
            /** @var Shipment $shipment */
            $shipment = $prepared['shipment'];
            /** @var array<string, mixed> $ready */
            $ready = $prepared['ready'];

            if ($this->shouldReconcileFirst($shipment)) {
                $found = $this->searchForExisting($shipment, $locked);
                if ($found->hasBindableIds()) {
                    return DB::transaction(fn (): Shipment => $this->bindCreated(
                        $locked->fresh() ?? $locked,
                        $shipment->fresh() ?? $shipment,
                        $found,
                        $ready,
                        $actor,
                        reconciled: true,
                    ));
                }

                if ($found->retryable || $found->found) {
                    $this->markAmbiguous(
                        $shipment,
                        $found->error ?? 'Provider search was ambiguous. Create was not retried.',
                    );

                    throw new ShiprocketRetryableException(
                        $found->error ?? 'Shiprocket search was ambiguous. Reconcile before creating another shipment.',
                    );
                }
            }

            try {
                $createRequest = $this->mapper->map(
                    $shipment,
                    $locked->commerceOrder,
                    $ready['invoice'],
                    $ready['serials'],
                    $ready['shipping'],
                    $ready['parcel'],
                    $ready['pickup'],
                );
                $result = ShiprocketApiAuditScope::run(
                    ShiprocketApiAuditContext::fromCreateRequest($createRequest, $shipment),
                    fn () => $this->gateway->createOrder($createRequest),
                );
            } catch (ShiprocketRetryableException $exception) {
                $this->markAmbiguous($shipment, $exception->getMessage());

                throw $exception;
            } catch (ShiprocketNonRetryableException $exception) {
                if ($this->isAuthenticationFailure($exception)) {
                    $this->markAmbiguous($shipment, $exception->getMessage());
                } else {
                    $this->markFailed($shipment, $exception->getMessage());
                }

                throw $exception;
            } catch (ShiprocketDisabledException $exception) {
                throw ValidationException::withMessages([
                    'shipping' => $exception->getMessage(),
                ]);
            }

            if ($result->retryable) {
                $this->markAmbiguous($shipment, $result->error ?? 'Provider returned a retryable create failure.');

                throw new ShiprocketRetryableException($result->error ?? 'Shiprocket create is retryable.');
            }

            if ($result->status !== 'created' || ! filled($result->externalOrderId) || ! filled($result->externalShipmentId)) {
                $this->markFailed($shipment, $result->error ?? 'Provider rejected shipment create.');

                throw new ShiprocketNonRetryableException($result->error ?? 'Shiprocket rejected shipment create.');
            }

            return DB::transaction(fn (): Shipment => $this->bindCreated(
                $locked->fresh() ?? $locked,
                $shipment->fresh() ?? $shipment,
                $result,
                $ready,
                $actor,
                reconciled: false,
            ));
        } catch (ShiprocketRetryableException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage().' Reconcile before creating another shipment.',
            ]);
        } catch (ShiprocketNonRetryableException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage(),
            ]);
        } catch (ShiprocketDisabledException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage(),
            ]);
        }
    }

    public function assignAwb(HardwareFulfilment $fulfilment, ?User $actor = null): Shipment
    {
        $this->assertShiprocketShippingMethod($fulfilment);
        $this->assertNotFrozen($fulfilment);

        $existing = $this->existingShipment($fulfilment);
        if ($existing !== null && filled($existing->awb)) {
            $this->syncFulfilment($fulfilment, $existing);

            return $existing;
        }

        $courier = $this->couriers->resolveCourierForAwb($fulfilment, $actor);
        $outcome = $this->attemptAwbAssignment($fulfilment, $courier, $actor);

        if ($this->shouldRecoverWithAlternateCourier($outcome, $courier['courier_id'])) {
            $alternate = $this->couriers->resolveAlternateCourierAfterRejection(
                $fulfilment->fresh(),
                $courier['courier_id'],
                $actor,
            );
            $outcome = $this->attemptAwbAssignment($fulfilment->fresh(), $alternate, $actor);
        }

        if (isset($outcome['shipment'])) {
            return $outcome['shipment'];
        }

        if (isset($outcome['retryable'])) {
            throw ValidationException::withMessages([
                'shipping' => $outcome['retryable'].' Reconcile before assigning another AWB.',
            ]);
        }

        throw ValidationException::withMessages([
            'shipping' => $outcome['rejected'] ?? 'Shiprocket rejected AWB assignment.',
        ]);
    }

    /**
     * Bind a provider-assigned AWB from verified Shiprocket search without calling
     * assign/awb. Idempotent when the local AWB already matches the provider.
     */
    public function reconcileAwbFromProviderSearch(HardwareFulfilment $fulfilment, ?User $actor = null): HardwareAwbReconcileOutcome
    {
        $this->assertNotFrozen($fulfilment);
        $this->assertProviderCallable();

        try {
            $resolved = DB::transaction(function () use ($fulfilment, $actor): array {
                $locked = HardwareFulfilment::query()
                    ->whereKey($fulfilment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertNotFrozen($locked);

                $shipment = $this->existingShipment($locked);
                if ($shipment === null || ! $shipment->isBound()) {
                    throw ValidationException::withMessages([
                        'shipping' => 'AWB reconciliation requires a created provider shipment.',
                    ]);
                }

                if (filled($shipment->awb)) {
                    $found = $this->searchForExisting($shipment, $locked);
                    $this->assertSearchIdentity($locked, $shipment, $found);

                    if (filled($found->awb) && $found->awb === $shipment->awb) {
                        return [
                            'shipment' => $shipment,
                            'alreadyLocal' => true,
                            'reconciled' => false,
                        ];
                    }

                    throw ValidationException::withMessages([
                        'shipping' => 'AWB is already bound and cannot be overwritten.',
                    ]);
                }

                if ($locked->state !== HardwareFulfilmentState::ShipmentCreated) {
                    throw ValidationException::withMessages([
                        'state' => 'AWB reconciliation requires SHIPMENT_CREATED.',
                    ]);
                }

                $found = $this->searchForExisting($shipment, $locked);
                if ($found->retryable) {
                    throw new ShiprocketRetryableException(
                        $found->error ?? 'Shiprocket search is retryable.',
                    );
                }

                if (! $found->found || ! filled($found->awb)) {
                    throw ValidationException::withMessages([
                        'shipping' => 'Shiprocket search did not return an AWB for this shipment.',
                    ]);
                }

                $this->assertSearchIdentity($locked, $shipment, $found);
                $this->bindAwbFromProviderSearch($locked, $shipment, $found, $actor);

                return [
                    'shipment' => $shipment->fresh() ?? $shipment,
                    'alreadyLocal' => false,
                    'reconciled' => true,
                ];
            });
        } catch (ShiprocketRetryableException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage().' Retry AWB reconciliation after Shiprocket is reachable.',
            ]);
        }

        return new HardwareAwbReconcileOutcome(
            shipment: $resolved['shipment'],
            alreadyLocal: $resolved['alreadyLocal'],
            reconciled: $resolved['reconciled'],
        );
    }

    public function reconcileCourierReassignmentFromProviderSearch(
        HardwareFulfilment $fulfilment,
        ?User $actor = null,
    ): HardwareCourierReassignmentReconcileOutcome {
        $this->assertNotFrozen($fulfilment);
        $this->assertProviderCallable();

        try {
            $resolved = DB::transaction(function () use ($fulfilment, $actor): array {
                $locked = HardwareFulfilment::query()
                    ->whereKey($fulfilment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertNotFrozen($locked);

                $shipment = $this->existingShipment($locked);
                if ($shipment === null || ! $shipment->isBound()) {
                    throw ValidationException::withMessages([
                        'shipping' => 'Courier reassignment reconcile requires a created provider shipment.',
                    ]);
                }

                if (! filled($shipment->awb)) {
                    throw ValidationException::withMessages([
                        'shipping' => 'Courier reassignment reconcile requires a bound AWB. Use AWB reconciliation first.',
                    ]);
                }

                if ($locked->state !== HardwareFulfilmentState::AwbAssigned) {
                    throw ValidationException::withMessages([
                        'state' => 'Courier reassignment reconcile requires AWB_ASSIGNED.',
                    ]);
                }

                if (filled($shipment->manifest_url) || filled($shipment->manifest_id)) {
                    throw ValidationException::withMessages([
                        'shipping' => 'Courier reassignment reconcile is not supported after a manifest has been generated.',
                    ]);
                }

                $found = $this->searchForExisting($shipment, $locked);
                if ($found->retryable) {
                    throw new ShiprocketRetryableException(
                        $found->error ?? 'Shiprocket search is retryable.',
                    );
                }

                if (! $found->found || ! filled($found->awb)) {
                    throw ValidationException::withMessages([
                        'shipping' => 'Shiprocket search did not return an AWB for this shipment.',
                    ]);
                }

                $this->assertSearchIdentity($locked, $shipment, $found);

                $providerAwb = trim((string) $found->awb);
                $providerCourierId = trim((string) ($found->courierId ?? ''));
                $localAwb = trim((string) $shipment->awb);
                $localCourierId = trim((string) ($shipment->courier_id ?? ''));

                if ($providerAwb === $localAwb
                    && ($providerCourierId === '' || $providerCourierId === $localCourierId)) {
                    return [
                        'shipment' => $shipment,
                        'alreadyAligned' => true,
                        'reconciled' => false,
                    ];
                }

                if (Shipment::query()
                    ->where('awb', $providerAwb)
                    ->whereKeyNot($shipment->id)
                    ->exists()) {
                    throw ValidationException::withMessages([
                        'shipping' => 'Shiprocket returned an AWB that is already bound to another Desk shipment.',
                    ]);
                }

                $this->applyCourierReassignmentFromProviderSearch($locked, $shipment, $found, $actor);

                return [
                    'shipment' => $shipment->fresh() ?? $shipment,
                    'alreadyAligned' => false,
                    'reconciled' => true,
                ];
            });
        } catch (ShiprocketRetryableException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage().' Retry courier reassignment reconcile after Shiprocket is reachable.',
            ]);
        }

        return new HardwareCourierReassignmentReconcileOutcome(
            shipment: $resolved['shipment'],
            alreadyAligned: $resolved['alreadyAligned'],
            reconciled: $resolved['reconciled'],
        );
    }

    /**
     * Provider POST /courier/assign/awb with status reassign for an existing bound shipment.
     */
    public function reassignCourierViaProviderApi(
        HardwareFulfilment $fulfilment,
        ?string $courierId = null,
        bool $executeProviderPost = false,
        bool $allowAfterAmbiguousAttempt = false,
        ?User $actor = null,
        array $walletSnapshot = [],
    ): HardwareProviderCourierReassignOutcome {
        $this->assertNotFrozen($fulfilment);
        $this->assertProviderCallable();

        $shipment = $this->existingShipment($fulfilment);
        if ($shipment === null || ! $shipment->isBound() || ! filled($shipment->awb)) {
            throw ValidationException::withMessages([
                'shipping' => 'Courier reassignment requires a bound shipment with an existing AWB.',
            ]);
        }

        if (filled($shipment->manifest_url) || filled($shipment->manifest_id)) {
            throw ValidationException::withMessages([
                'shipping' => 'Courier reassignment is not supported after a manifest has been generated.',
            ]);
        }

        $previousAwb = trim((string) $shipment->awb);
        $preflightTrack = $this->providerTrackForAwb($previousAwb, $shipment);
        $targetCourier = trim((string) ($courierId ?? $shipment->courier_id ?? ''));

        if ($targetCourier === '') {
            throw ValidationException::withMessages([
                'shipping' => 'Courier reassignment requires a target courier id (explicit or on the shipment).',
            ]);
        }

        $blockedReason = $this->providerCourierReassignBlocked($shipment, $allowAfterAmbiguousAttempt, $targetCourier);
        if ($blockedReason !== null) {
            if ($executeProviderPost) {
                throw ValidationException::withMessages([
                    'shipping' => $blockedReason,
                ]);
            }

            return new HardwareProviderCourierReassignOutcome(
                shipment: $shipment,
                previousAwb: $previousAwb,
                newAwb: $shipment->awb,
                providerResult: new ShiprocketAwbResult(
                    provider: $this->gateway->provider(),
                    status: 'rejected',
                    error: $blockedReason,
                    retryable: false,
                ),
                preflightTrack: $preflightTrack,
                postAssignTrack: null,
                postedToProvider: false,
                deskUpdated: false,
                alreadyPosted: true,
                walletSnapshot: $walletSnapshot,
            );
        }

        if (! $executeProviderPost) {
            return new HardwareProviderCourierReassignOutcome(
                shipment: $shipment,
                previousAwb: $previousAwb,
                newAwb: null,
                providerResult: new ShiprocketAwbResult(
                    provider: $this->gateway->provider(),
                    status: 'dry_run',
                    retryable: false,
                ),
                preflightTrack: $preflightTrack,
                postAssignTrack: null,
                postedToProvider: false,
                deskUpdated: false,
                alreadyPosted: false,
                walletSnapshot: $walletSnapshot,
            );
        }

        DB::transaction(function () use ($fulfilment, $targetCourier): void {
            $locked = HardwareFulfilment::query()
                ->whereKey($fulfilment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $this->assertNotFrozen($locked);

            $shipment = $this->existingShipment($locked);
            if ($shipment === null || ! $shipment->isBound()) {
                throw ValidationException::withMessages([
                    'shipping' => 'Courier reassignment requires a created provider shipment.',
                ]);
            }

            $blockedReason = $this->providerCourierReassignBlocked($shipment, false, $targetCourier);
            if ($blockedReason !== null) {
                throw ValidationException::withMessages([
                    'shipping' => $blockedReason,
                ]);
            }
        });

        $result = ShiprocketApiAuditScope::run(
            ShiprocketApiAuditContext::fromShipment($shipment),
            fn () => $this->gateway->reassignCourierAwb(
                (string) $shipment->external_shipment_id,
                $targetCourier,
            ),
        );

        DB::transaction(function () use ($fulfilment, $targetCourier, $previousAwb, $actor, $result): void {
            $locked = HardwareFulfilment::query()
                ->whereKey($fulfilment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $shipment = $this->existingShipment($locked);
            if ($shipment === null || ! $shipment->isBound()) {
                throw ValidationException::withMessages([
                    'shipping' => 'Courier reassignment requires a created provider shipment.',
                ]);
            }

            $this->recordProviderCourierReassignPostEvent($shipment, $result, $targetCourier, $previousAwb, $actor);
        });

        if ($result->retryable) {
            throw ValidationException::withMessages([
                'shipping' => ($result->error ?? 'Shiprocket courier reassignment failed retryably.')
                    .' Stop. Run read-only GET tracking before any further POST.',
            ]);
        }

        if ($result->status === 'ambiguous') {
            throw ValidationException::withMessages([
                'shipping' => ($result->error ?? 'Shiprocket courier reassignment returned an ambiguous response.')
                    .' Stop. Run read-only GET tracking before any further POST.',
            ]);
        }

        if ($result->status !== 'reassigned' || ! filled($result->awb)) {
            throw ValidationException::withMessages([
                'shipping' => $result->error ?? 'Shiprocket rejected courier reassignment.',
            ]);
        }

        $postTrack = $this->providerTrackForAwb((string) $result->awb, $shipment);

        $resolved = DB::transaction(function () use ($fulfilment, $shipment, $result, $previousAwb, $postTrack, $actor): array {
            $locked = HardwareFulfilment::query()
                ->whereKey($fulfilment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $shipment = $this->existingShipment($locked);
            if ($shipment === null || ! $shipment->isBound()) {
                throw ValidationException::withMessages([
                    'shipping' => 'Courier reassignment requires a created provider shipment.',
                ]);
            }

            $deskUpdated = $this->applyProviderCourierReassignResult($locked, $shipment, $result, $previousAwb, $postTrack, $actor);

            return [
                'shipment' => $shipment->fresh() ?? $shipment,
                'deskUpdated' => $deskUpdated,
            ];
        });

        return new HardwareProviderCourierReassignOutcome(
            shipment: $resolved['shipment'],
            previousAwb: $previousAwb,
            newAwb: $result->awb,
            providerResult: $result,
            preflightTrack: $preflightTrack,
            postAssignTrack: $postTrack,
            postedToProvider: true,
            deskUpdated: $resolved['deskUpdated'],
            alreadyPosted: false,
            walletSnapshot: $walletSnapshot,
        );
    }

    /**
     * @param  array{courier_id: string, courier_name: string|null}  $courier
     * @return array{shipment?: Shipment, retryable?: string, rejected?: string, result?: ShiprocketAwbResult}
     */
    private function attemptAwbAssignment(HardwareFulfilment $fulfilment, array $courier, ?User $actor): array
    {
        return DB::transaction(function () use ($fulfilment, $actor, $courier): array {
            $locked = HardwareFulfilment::query()
                ->whereKey($fulfilment->id)
                ->lockForUpdate()
                ->firstOrFail();

            $shipment = $this->existingShipment($locked);
            if ($shipment === null || ! $shipment->isBound()) {
                throw ValidationException::withMessages([
                    'shipping' => 'AWB assignment requires a created provider shipment.',
                ]);
            }

            if (filled($shipment->awb)) {
                $this->syncFulfilment($locked, $shipment);

                return ['shipment' => $shipment];
            }

            if ($locked->state !== HardwareFulfilmentState::ShipmentCreated) {
                throw ValidationException::withMessages([
                    'state' => 'AWB assignment requires SHIPMENT_CREATED.',
                ]);
            }

            $this->assertProviderCallable();

            try {
                $result = ShiprocketApiAuditScope::run(
                    ShiprocketApiAuditContext::fromShipment($shipment),
                    fn () => $this->gateway->assignAwb(
                        (string) $shipment->external_shipment_id,
                        $courier['courier_id'],
                    ),
                );
            } catch (ShiprocketRetryableException $exception) {
                $shipment->forceFill([
                    'failure_class' => 'retryable',
                    'last_error' => $exception->getMessage(),
                ])->save();

                return ['retryable' => $exception->getMessage()];
            } catch (ShiprocketNonRetryableException $exception) {
                $shipment->forceFill([
                    'failure_class' => 'provider_rejected',
                    'last_error' => $exception->getMessage(),
                ])->save();

                return ['rejected' => $exception->getMessage()];
            }

            if ($result->retryable) {
                $message = $result->error ?? 'Shiprocket AWB assignment is retryable.';
                $shipment->forceFill([
                    'failure_class' => 'retryable',
                    'last_error' => $message,
                ])->save();

                return ['retryable' => $message, 'result' => $result];
            }

            if ($result->status !== 'assigned' || ! filled($result->awb)) {
                $message = $result->error ?? 'Shiprocket rejected AWB assignment.';
                $shipment->forceFill([
                    'failure_class' => 'provider_rejected',
                    'last_error' => $message,
                ])->save();

                return ['rejected' => $message, 'result' => $result];
            }

            $this->bindAwb($locked, $shipment, $result->awb, $result->courierId, $result->courierName, $actor);

            return ['shipment' => $shipment->fresh() ?? $shipment];
        });
    }

    /**
     * @param  array{shipment?: Shipment, retryable?: string, rejected?: string, result?: ShiprocketAwbResult}  $outcome
     */
    private function shouldRecoverWithAlternateCourier(array $outcome, string $attemptedCourierId): bool
    {
        if (! isset($outcome['rejected'])) {
            return false;
        }

        $result = $outcome['result'] ?? null;
        if ($result instanceof ShiprocketAwbResult) {
            return $result->isCourierNotServiceableRejection();
        }

        return ShiprocketAwbAssignmentRejection::isCourierNotServiceable($outcome['rejected']);
    }

    /**
     * @param  array<string, mixed>  $ready
     * @param  array{courier_id: string, courier_name: string|null}|null  $courier
     */
    private function openShipment(HardwareFulfilment $fulfilment, array $ready, ?array $courier): Shipment
    {
        $order = $fulfilment->commerceOrder;
        $merchantReference = HardwareShipmentMerchantReference::forNewShipment($fulfilment);

        return Shipment::query()->create([
            'shipment_no' => $merchantReference,
            'commerce_order_id' => $order->id,
            'hardware_fulfilment_id' => $fulfilment->id,
            'provider' => $this->gateway->provider(),
            'status' => ShipmentStatus::PendingCreate,
            'invoice_number' => $ready['invoice']->invoice_number,
            'serial_numbers' => $ready['serials'],
            'pickup_location' => $ready['pickup'],
            'courier_id' => $courier['courier_id'] ?? null,
            'courier_name' => $courier['courier_name'] ?? null,
            'idempotency_key' => 'hardware:shiprocket:create:'.$fulfilment->id,
            'correlation_id' => (string) Str::uuid(),
            'create_snapshot' => [
                'invoice_number' => $ready['invoice']->invoice_number,
                'serial_numbers' => $ready['serials'],
                'pickup_location' => $ready['pickup'],
                'fulfilment_branch_id' => $fulfilment->fulfilment_branch_id,
                'source_id' => $fulfilment->source_id,
                'parcel' => $ready['parcel'],
                'parcel_source' => $ready['parcel_source'] ?? null,
                'shipping_country' => $ready['shipping']['country'] ?? null,
            ],
        ]);
    }

    /**
     * @param  ShiprocketCreateOrderResult|ShiprocketSearchResult  $result
     * @param  array<string, mixed>  $ready
     */
    private function bindCreated(
        HardwareFulfilment $fulfilment,
        Shipment $shipment,
        object $result,
        array $ready,
        ?User $actor,
        bool $reconciled,
    ): Shipment {
        $externalOrderId = $result->externalOrderId;
        $externalShipmentId = $result->externalShipmentId;
        $awb = $result->awb ?? null;

        if ($shipment->external_order_id !== null && $shipment->external_order_id !== $externalOrderId) {
            throw ValidationException::withMessages([
                'shipping' => 'Provider order id is already bound and cannot be overwritten.',
            ]);
        }

        $updates = [
            'status' => filled($awb) ? ShipmentStatus::AwbAssigned : ShipmentStatus::Created,
            'failure_class' => null,
            'last_error' => null,
            'last_reconciled_at' => $reconciled ? now() : $shipment->last_reconciled_at,
        ];
        if ($shipment->external_order_id === null) {
            $updates['external_order_id'] = $externalOrderId;
        }
        if ($shipment->external_shipment_id === null) {
            $updates['external_shipment_id'] = $externalShipmentId;
        }
        if ($shipment->provider_accepted_at === null) {
            $updates['provider_accepted_at'] = now();
        }
        if (filled($awb) && $shipment->awb === null) {
            $updates['awb'] = $awb;
            $updates['awb_assigned_at'] = now();
        }

        $shipment->forceFill($updates)->save();

        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'source' => $reconciled ? 'reconcile' : 'provider',
            'activity' => $reconciled ? 'create_reconciled' : 'created',
            'awb' => $shipment->awb,
            'external_order_id' => $shipment->external_order_id,
            'external_shipment_id' => $shipment->external_shipment_id,
            'payload' => [
                'invoice_number' => $ready['invoice']->invoice_number,
                'serial_numbers' => $ready['serials'],
            ],
        ]);

        $this->syncFulfilment($fulfilment, $shipment);

        if ($fulfilment->state === HardwareFulfilmentState::InvoiceIssued) {
            $this->workflow->transition(
                $fulfilment,
                HardwareFulfilmentState::ShipmentCreated,
                actorType: $actor !== null ? 'user' : 'system',
                actorId: $actor?->id,
                payload: [
                    'reason' => 'hardware_shipment_created',
                    'shipment_id' => $shipment->id,
                    'shipment_no' => $shipment->shipment_no,
                ],
            );
        }

        $fresh = $fulfilment->fresh() ?? $fulfilment;
        if (filled($shipment->awb) && $fresh->state === HardwareFulfilmentState::ShipmentCreated) {
            $this->workflow->transition(
                $fresh,
                HardwareFulfilmentState::AwbAssigned,
                actorType: $actor !== null ? 'user' : 'system',
                actorId: $actor?->id,
                payload: [
                    'reason' => 'hardware_awb_assigned',
                    'awb' => $shipment->awb,
                ],
            );
        }

        return $shipment->fresh() ?? $shipment;
    }

    private function providerCourierReassignBlocked(
        Shipment $shipment,
        bool $allowAfterAmbiguousAttempt,
        string $targetCourierId,
    ): ?string {
        $posts = ShipmentEvent::query()
            ->where('shipment_id', $shipment->id)
            ->where('activity', 'provider_courier_reassign_post')
            ->get();

        if ($posts->isEmpty()) {
            return null;
        }

        if ($posts->contains(fn (ShipmentEvent $event): bool => ($event->payload['outcome'] ?? null) === 'reassigned')) {
            return 'A successful provider courier reassignment was already recorded for this shipment.';
        }

        $targetCourierId = trim($targetCourierId);
        if ($targetCourierId !== '' && $posts->contains(
            fn (ShipmentEvent $event): bool => trim((string) ($event->payload['target_courier_id'] ?? '')) === $targetCourierId,
        )) {
            return 'A provider courier reassignment POST was already recorded for courier '.$targetCourierId.'.';
        }

        $hasAmbiguousOrFailed = $posts->contains(
            fn (ShipmentEvent $event): bool => in_array($event->payload['outcome'] ?? null, ['ambiguous', 'failed'], true),
        );
        if ($hasAmbiguousOrFailed && ! $allowAfterAmbiguousAttempt) {
            return 'A prior ambiguous or retryable provider courier reassignment attempt exists. Resolve provider state with GET before another POST.';
        }

        return null;
    }

    /**
     * Read-only Shiprocket courier serviceability for an existing bound shipment (reassign candidate discovery).
     *
     * @return array{status: string, pickup_postcode: string, delivery_postcode: string, weight_kg: float, provider_order_id: string|null, options: list<array<string, mixed>>, error: string|null}
     */
    public function discoverReassignCourierOptions(
        HardwareFulfilment $fulfilment,
        string $pickupPostcode,
        string $deliveryPostcode,
        ?float $weightKg = null,
    ): array {
        $this->assertProviderCallable();

        $shipment = $this->existingShipment($fulfilment);
        if ($shipment === null || ! $shipment->isBound()) {
            throw ValidationException::withMessages([
                'shipping' => 'Courier discovery requires a bound provider shipment.',
            ]);
        }

        $pickup = trim($pickupPostcode);
        $delivery = trim($deliveryPostcode);
        if ($pickup === '' || $delivery === '') {
            throw ValidationException::withMessages([
                'shipping' => 'Courier discovery requires pickup and delivery postcodes.',
            ]);
        }

        $weight = $weightKg ?? 0.5;
        if ($weight <= 0) {
            $weight = 0.5;
        }

        $providerOrderId = filled($shipment->external_order_id)
            ? (string) $shipment->external_order_id
            : null;

        try {
            $result = ShiprocketApiAuditScope::run(
                ShiprocketApiAuditContext::fromShipment($shipment),
                fn () => $this->gateway->listCourierOptions(new ShiprocketCourierOptionsRequest(
                    pickupPostcode: $pickup,
                    deliveryPostcode: $delivery,
                    weight: $weight,
                    cod: 0,
                    providerOrderId: $providerOrderId,
                )),
            );
        } catch (ShiprocketRetryableException $exception) {
            return [
                'status' => 'failed',
                'pickup_postcode' => $pickup,
                'delivery_postcode' => $delivery,
                'weight_kg' => $weight,
                'provider_order_id' => $providerOrderId,
                'options' => [],
                'error' => $exception->getMessage(),
            ];
        }

        $options = [];
        foreach ($result->options as $option) {
            $options[] = [
                'courier_id' => $option->courierId,
                'courier_name' => $option->courierName,
                'rate' => $option->rate,
                'coverage_charge' => $option->coverageCharge,
                'estimated_delivery' => $option->estimatedDelivery,
                'cod_available' => $option->codAvailable,
                'provider_recommended' => $option->providerRecommended,
                'courier_type' => $option->courierType,
            ];
        }

        return [
            'status' => $result->status,
            'pickup_postcode' => $pickup,
            'delivery_postcode' => $delivery,
            'weight_kg' => $weight,
            'provider_order_id' => $providerOrderId,
            'options' => $options,
            'error' => $result->error,
        ];
    }

    private function recordProviderCourierReassignPostEvent(
        Shipment $shipment,
        ShiprocketAwbResult $result,
        string $targetCourierId,
        string $previousAwb,
        ?User $actor,
    ): void {
        $outcome = match (true) {
            $result->status === 'reassigned' => 'reassigned',
            $result->status === 'ambiguous' => 'ambiguous',
            $result->retryable => 'failed',
            default => 'rejected',
        };

        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'source' => 'provider',
            'activity' => 'provider_courier_reassign_post',
            'awb' => $result->awb ?? $previousAwb,
            'external_order_id' => $shipment->external_order_id,
            'external_shipment_id' => $shipment->external_shipment_id,
            'payload' => [
                'outcome' => $outcome,
                'provider_status' => $result->status,
                'target_courier_id' => $targetCourierId,
                'previous_awb' => $previousAwb,
                'response_awb' => $result->awb,
                'response_courier_id' => $result->courierId,
                'response_courier_name' => $result->courierName,
                'provider_error' => $result->error,
                'retryable' => $result->retryable,
                'actor_user_id' => $actor?->id,
            ],
        ]);
    }

    private function applyProviderCourierReassignResult(
        HardwareFulfilment $fulfilment,
        Shipment $shipment,
        ShiprocketAwbResult $result,
        string $previousAwb,
        ShiprocketTrackResult $postTrack,
        ?User $actor,
    ): bool {
        $newAwb = trim((string) $result->awb);
        if ($newAwb === '') {
            return false;
        }

        if ($newAwb !== $previousAwb
            && Shipment::query()
                ->where('awb', $newAwb)
                ->whereKeyNot($shipment->id)
                ->exists()) {
            throw ValidationException::withMessages([
                'shipping' => 'Shiprocket returned an AWB that is already bound to another Desk shipment.',
            ]);
        }

        $courierId = trim((string) ($result->courierId ?? ''));
        $courierName = $result->courierName;
        $normalized = ShiprocketTrackingNormalizer::normalize($postTrack);
        $awbChanged = $newAwb !== $previousAwb;
        $previousCourierId = trim((string) $shipment->courier_id);
        $previousCourierName = $shipment->courier_name;
        $previousLabelUrl = $shipment->label_url;

        $shipment->forceFill([
            'status' => ShipmentStatus::AwbAssigned,
            'awb' => $newAwb,
            'courier_id' => $courierId !== '' ? $courierId : $shipment->courier_id,
            'courier_name' => $courierName ?? $shipment->courier_name,
            'label_url' => $awbChanged ? null : $shipment->label_url,
            'label_fetched_at' => $awbChanged ? null : $shipment->label_fetched_at,
            'provider_track_status' => $normalized['provider_track_status'],
            'provider_track_normalized' => $normalized['normalized']->value,
            'provider_tracked_at' => now(),
            'failure_class' => null,
            'last_error' => null,
            'last_reconciled_at' => now(),
        ])->save();

        $fulfilment->forceFill([
            'awb' => $newAwb,
            'provider_awb' => $newAwb,
            'selected_courier_id' => $courierId !== '' ? $courierId : $fulfilment->selected_courier_id,
            'selected_courier_name' => $courierName ?? $fulfilment->selected_courier_name,
            'selected_courier_at' => now(),
            'selected_courier_by_user_id' => $actor?->id,
        ])->save();

        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'source' => 'provider',
            'activity' => $awbChanged ? 'awb_reassigned' : 'courier_reassigned',
            'awb' => $newAwb,
            'external_order_id' => $shipment->external_order_id,
            'external_shipment_id' => $shipment->external_shipment_id,
            'payload' => [
                'reason' => 'provider_courier_reassign_api',
                'previous_awb' => $previousAwb !== '' ? $previousAwb : null,
                'previous_courier_id' => $previousCourierId !== '' ? $previousCourierId : null,
                'previous_courier_name' => $previousCourierName,
                'previous_label_url' => $previousLabelUrl,
                'reconciled_awb' => $newAwb,
                'reconciled_courier_id' => $courierId !== '' ? $courierId : null,
                'reconciled_courier_name' => $courierName,
                'provider_track_status' => $normalized['provider_track_status'],
                'provider_track_normalized' => $normalized['normalized']->value,
            ],
        ]);

        return true;
    }

    private function applyCourierReassignmentFromProviderSearch(
        HardwareFulfilment $fulfilment,
        Shipment $shipment,
        ShiprocketSearchResult $found,
        ?User $actor,
    ): void {
        $newAwb = trim((string) $found->awb);
        if ($newAwb === '') {
            throw ValidationException::withMessages([
                'shipping' => 'Shiprocket search did not return an AWB for this shipment.',
            ]);
        }

        $previousAwb = trim((string) $shipment->awb);
        $previousCourierId = trim((string) $shipment->courier_id);
        $previousCourierName = $shipment->courier_name;
        $previousLabelUrl = $shipment->label_url;
        $courierId = trim((string) ($found->courierId ?? ''));
        $courierName = $found->courierName;

        $track = $this->providerTrackForAwb($newAwb, $shipment);
        $normalized = ShiprocketTrackingNormalizer::normalize($track);

        $shipment->forceFill([
            'status' => ShipmentStatus::AwbAssigned,
            'awb' => $newAwb,
            'courier_id' => $courierId !== '' ? $courierId : $shipment->courier_id,
            'courier_name' => $courierName ?? $shipment->courier_name,
            'label_url' => null,
            'label_fetched_at' => null,
            'provider_track_status' => $normalized['provider_track_status'],
            'provider_track_normalized' => $normalized['normalized']->value,
            'provider_tracked_at' => now(),
            'failure_class' => null,
            'last_error' => null,
            'last_reconciled_at' => now(),
        ])->save();

        $fulfilment->forceFill([
            'awb' => $newAwb,
            'provider_awb' => $newAwb,
            'selected_courier_id' => $courierId !== '' ? $courierId : $fulfilment->selected_courier_id,
            'selected_courier_name' => $courierName ?? $fulfilment->selected_courier_name,
            'selected_courier_at' => now(),
            'selected_courier_by_user_id' => $actor?->id,
        ])->save();

        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'source' => 'reconcile',
            'activity' => 'awb_reassigned',
            'awb' => $newAwb,
            'external_order_id' => $shipment->external_order_id,
            'external_shipment_id' => $shipment->external_shipment_id,
            'payload' => [
                'reason' => 'provider_courier_reassignment_reconcile',
                'previous_awb' => $previousAwb !== '' ? $previousAwb : null,
                'previous_courier_id' => $previousCourierId !== '' ? $previousCourierId : null,
                'previous_courier_name' => $previousCourierName,
                'previous_label_url' => $previousLabelUrl,
                'reconciled_awb' => $newAwb,
                'reconciled_courier_id' => $courierId !== '' ? $courierId : null,
                'reconciled_courier_name' => $courierName,
                'provider_status' => $found->status,
                'provider_track_status' => $normalized['provider_track_status'],
                'provider_track_normalized' => $normalized['normalized']->value,
            ],
        ]);
    }

    private function providerTrackForAwb(string $awb, ?Shipment $shipment = null): ShiprocketTrackResult
    {
        try {
            $context = $shipment !== null
                ? ShiprocketApiAuditContext::fromShipment($shipment)
                : null;

            return ShiprocketApiAuditScope::run(
                $context,
                fn () => $this->gateway->trackByAwb($awb),
            );
        } catch (ShiprocketRetryableException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage().' Retry courier reassignment reconcile after Shiprocket is reachable.',
            ]);
        }
    }

    private function persistProviderTrackAfterAwb(
        Shipment $shipment,
        HardwareFulfilment $fulfilment,
        ?User $actor,
    ): void {
        $awb = trim((string) $shipment->awb);
        if ($awb === '' || $this->gateway instanceof NullShiprocketGateway) {
            return;
        }

        try {
            $track = $this->providerTrackForAwb($awb, $shipment);
            $normalized = ShiprocketTrackingNormalizer::normalize($track);
            $shipment->forceFill([
                'provider_track_status' => $normalized['provider_track_status'],
                'provider_track_normalized' => $normalized['normalized']->value,
                'provider_tracked_at' => now(),
            ])->save();

            if ($shipment->pickup_requested_at === null
                && ShiprocketTrackingNormalizer::pickupAlreadyAdvanced($track)) {
                $this->documents->reconcilePickupOnLockedShipment($fulfilment, $shipment, $track, $actor);
            }
        } catch (Throwable) {
            // Best-effort read-only enrichment; never fail AWB binding.
        }
    }

    private function bindAwbFromProviderSearch(
        HardwareFulfilment $fulfilment,
        Shipment $shipment,
        ShiprocketSearchResult $found,
        ?User $actor,
    ): void {
        $awb = trim((string) $found->awb);
        if ($awb === '') {
            throw ValidationException::withMessages([
                'shipping' => 'Shiprocket search did not return an AWB for this shipment.',
            ]);
        }

        if ($shipment->awb !== null && $shipment->awb !== $awb) {
            throw ValidationException::withMessages([
                'shipping' => 'AWB is already bound and cannot be overwritten.',
            ]);
        }

        $previousCourierId = trim((string) $shipment->courier_id);
        $courierId = trim((string) ($found->courierId ?? ''));
        $courierName = $found->courierName;

        $shipment->forceFill([
            'status' => ShipmentStatus::AwbAssigned,
            'awb' => $shipment->awb ?? $awb,
            'courier_id' => $courierId !== '' ? $courierId : $shipment->courier_id,
            'courier_name' => $courierName ?? $shipment->courier_name,
            'awb_assigned_at' => $shipment->awb_assigned_at ?? now(),
            'failure_class' => null,
            'last_error' => null,
            'last_reconciled_at' => now(),
        ])->save();

        if ($courierId !== '') {
            $fulfilment->forceFill([
                'selected_courier_id' => $courierId,
                'selected_courier_name' => $courierName,
                'selected_courier_at' => now(),
                'selected_courier_by_user_id' => $actor?->id,
            ])->save();
        }

        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'source' => 'reconcile',
            'activity' => 'awb_assigned',
            'awb' => $shipment->awb,
            'external_order_id' => $shipment->external_order_id,
            'external_shipment_id' => $shipment->external_shipment_id,
            'payload' => [
                'reason' => 'provider_search_reconcile',
                'provider_status' => $found->status,
                'previous_courier_id' => $previousCourierId !== '' ? $previousCourierId : null,
                'reconciled_courier_id' => $courierId !== '' ? $courierId : null,
            ],
        ]);

        $this->syncFulfilment($fulfilment, $shipment);

        if ($fulfilment->state === HardwareFulfilmentState::ShipmentCreated) {
            $this->workflow->transition(
                $fulfilment,
                HardwareFulfilmentState::AwbAssigned,
                actorType: $actor !== null ? 'user' : 'system',
                actorId: $actor?->id,
                payload: [
                    'reason' => 'hardware_awb_assigned',
                    'awb' => $shipment->awb,
                    'reconciled_from_provider_search' => true,
                ],
            );
        }
    }

    private function assertSearchIdentity(
        HardwareFulfilment $fulfilment,
        Shipment $shipment,
        ShiprocketSearchResult $found,
    ): void {
        if (! $found->found) {
            throw ValidationException::withMessages([
                'shipping' => 'Shiprocket search did not find this shipment.',
            ]);
        }

        $boundOrderId = trim((string) $shipment->external_order_id);
        $foundOrderId = trim((string) ($found->externalOrderId ?? ''));
        if ($boundOrderId !== '' && $foundOrderId !== '' && $boundOrderId !== $foundOrderId) {
            throw ValidationException::withMessages([
                'shipping' => 'Provider order id does not match the bound shipment.',
            ]);
        }

        $boundShipmentId = trim((string) $shipment->external_shipment_id);
        $foundShipmentId = trim((string) ($found->externalShipmentId ?? ''));
        if ($boundShipmentId !== '' && $foundShipmentId !== '' && $boundShipmentId !== $foundShipmentId) {
            throw ValidationException::withMessages([
                'shipping' => 'Provider shipment id does not match the bound shipment.',
            ]);
        }

        $merchantId = trim((string) ($found->merchantOrderId ?? ''));
        if ($merchantId !== '' && ! HardwareShipmentMerchantReference::acceptsMerchantOrderId($merchantId, $shipment, $fulfilment)) {
            throw ValidationException::withMessages([
                'shipping' => 'Provider merchant reference does not match this fulfilment.',
            ]);
        }
    }

    private function bindAwb(
        HardwareFulfilment $fulfilment,
        Shipment $shipment,
        string $awb,
        ?string $courierId,
        ?string $courierName,
        ?User $actor,
    ): void {
        if ($shipment->awb !== null && $shipment->awb !== $awb) {
            throw ValidationException::withMessages([
                'shipping' => 'AWB is already bound and cannot be overwritten.',
            ]);
        }

        $shipment->forceFill([
            'status' => ShipmentStatus::AwbAssigned,
            'awb' => $shipment->awb ?? $awb,
            'courier_id' => $shipment->courier_id ?? $courierId,
            'courier_name' => $shipment->courier_name ?? $courierName,
            'awb_assigned_at' => $shipment->awb_assigned_at ?? now(),
            'failure_class' => null,
            'last_error' => null,
        ])->save();

        $this->persistProviderTrackAfterAwb($shipment, $fulfilment, $actor);

        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'source' => 'provider',
            'activity' => 'awb_assigned',
            'awb' => $shipment->awb,
            'external_order_id' => $shipment->external_order_id,
            'external_shipment_id' => $shipment->external_shipment_id,
        ]);

        $this->syncFulfilment($fulfilment, $shipment);

        if ($fulfilment->state === HardwareFulfilmentState::ShipmentCreated) {
            $this->workflow->transition(
                $fulfilment,
                HardwareFulfilmentState::AwbAssigned,
                actorType: $actor !== null ? 'user' : 'system',
                actorId: $actor?->id,
                payload: [
                    'reason' => 'hardware_awb_assigned',
                    'awb' => $shipment->awb,
                ],
            );
        }
    }

    private function syncFulfilment(HardwareFulfilment $fulfilment, Shipment $shipment): void
    {
        $updates = [];
        if ($fulfilment->shipment_id === null) {
            $updates['shipment_id'] = $shipment->id;
        }
        if ($fulfilment->shipment_no === null) {
            $updates['shipment_no'] = $shipment->shipment_no;
        }
        if ($fulfilment->provider_shipment_id === null && $shipment->external_shipment_id !== null) {
            $updates['provider_shipment_id'] = $shipment->external_shipment_id;
        }
        if ($fulfilment->awb === null && $shipment->awb !== null) {
            $updates['awb'] = $shipment->awb;
        }
        if ($fulfilment->provider_awb === null && $shipment->awb !== null) {
            $updates['provider_awb'] = $shipment->awb;
        }
        if ($updates !== []) {
            $fulfilment->forceFill($updates)->save();
        }
    }

    private function existingShipment(HardwareFulfilment $fulfilment): ?Shipment
    {
        if ($fulfilment->shipment_id !== null) {
            return Shipment::query()->find($fulfilment->shipment_id);
        }

        return Shipment::query()->where('hardware_fulfilment_id', $fulfilment->id)->first();
    }

    private function searchForExisting(Shipment $shipment, HardwareFulfilment $fulfilment): ShiprocketSearchResult
    {
        $lastFound = null;

        try {
            foreach (HardwareShipmentMerchantReference::searchReferences($shipment, $fulfilment) as $reference) {
                $found = ShiprocketApiAuditScope::run(
                    ShiprocketApiAuditContext::fromShipment($shipment),
                    fn () => $this->gateway->searchOrders($reference),
                );
                if ($found->hasBindableIds()) {
                    return $found;
                }
                if ($found->retryable) {
                    return $found;
                }
                if ($found->found && $lastFound === null) {
                    $lastFound = $found;
                }
            }
        } catch (ShiprocketRetryableException $exception) {
            $this->markAmbiguous($shipment, $exception->getMessage());

            throw $exception;
        } catch (ShiprocketDisabledException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage(),
            ]);
        }

        if ($lastFound instanceof ShiprocketSearchResult) {
            return $lastFound;
        }

        return new ShiprocketSearchResult(
            provider: $this->gateway->provider(),
            found: false,
            merchantOrderId: $shipment->shipment_no,
        );
    }

    private function isAuthenticationFailure(ShiprocketNonRetryableException $exception): bool
    {
        return str_contains(strtolower($exception->getMessage()), 'authentication failed');
    }

    private function shouldReconcileFirst(Shipment $shipment): bool
    {
        return (int) $shipment->attempts > 1
            || $shipment->last_error !== null
            || in_array($shipment->failure_class, ['ambiguous', 'retryable'], true);
    }

    private function markAmbiguous(Shipment $shipment, string $message): void
    {
        $shipment->forceFill([
            'status' => ShipmentStatus::Ambiguous,
            'failure_class' => 'ambiguous',
            'last_error' => $message,
        ])->save();
    }

    private function markFailed(Shipment $shipment, string $message): void
    {
        $shipment->forceFill([
            'status' => ShipmentStatus::Failed,
            'failure_class' => 'provider_rejected',
            'last_error' => $message,
        ])->save();
    }

    /**
     * @return array{courier_id: string, courier_name: string|null}|null
     */
    private function optionalStoredCourier(HardwareFulfilment $fulfilment): ?array
    {
        $id = trim((string) $fulfilment->selected_courier_id);
        if ($id === '') {
            return null;
        }

        return [
            'courier_id' => $id,
            'courier_name' => $fulfilment->selected_courier_name,
        ];
    }

    private function assertProviderCallable(): void
    {
        $this->eligibility->assertProviderConfigured();

        if ($this->gateway instanceof NullShiprocketGateway || $this->gateway->provider() === 'none') {
            throw ValidationException::withMessages([
                'shipping' => NullShiprocketGateway::MESSAGE,
            ]);
        }
    }

    private function assertNotFrozen(HardwareFulfilment $fulfilment): void
    {
        if (HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Frozen pending hardware orders cannot be shipped.',
            ]);
        }
    }

    private function assertShiprocketShippingMethod(HardwareFulfilment $fulfilment): void
    {
        if ($fulfilment->usesExternalShipping()) {
            throw ValidationException::withMessages([
                'shipping' => 'Shiprocket shipment actions are not available for external shipments.',
            ]);
        }
    }
}
