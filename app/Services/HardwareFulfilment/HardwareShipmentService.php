<?php

namespace App\Services\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentState;
use App\Enums\ShipmentStatus;
use App\Models\HardwareFulfilment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\Shipping\Data\ShiprocketCreateOrderResult;
use App\Services\Shipping\Data\ShiprocketSearchResult;
use App\Services\Shipping\NullShiprocketGateway;
use App\Services\Shipping\ShiprocketDisabledException;
use App\Services\Shipping\ShiprocketNonRetryableException;
use App\Services\Shipping\ShiprocketRetryableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class HardwareShipmentService
{
    public function __construct(
        private readonly HardwareShipmentEligibility $eligibility,
        private readonly HardwareShipmentMapper $mapper,
        private readonly HardwareFulfilmentWorkflowService $workflow,
        private readonly ShiprocketGateway $gateway,
        private readonly HardwareFulfilmentParcelSnapshotService $snapshots,
        private readonly HardwareShipmentCourierOptionsService $couriers,
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

                if ($existing !== null && $existing->failure_class === 'provider_rejected') {
                    throw new ShiprocketNonRetryableException(
                        trim(($existing->last_error ?: 'Provider validation failure').' Previous provider validation failure is not retried.'),
                    );
                }

                $this->snapshots->attachIfEligible($locked, $actor);

                $ready = $this->eligibility->require($locked);
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
                $found = $this->searchForExisting($shipment);
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
                $result = $this->gateway->createOrder($this->mapper->map(
                    $shipment,
                    $locked->commerceOrder,
                    $ready['invoice'],
                    $ready['serials'],
                    $ready['shipping'],
                    $ready['parcel'],
                    $ready['pickup'],
                ));
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
        $this->assertNotFrozen($fulfilment);

        try {
            return DB::transaction(function () use ($fulfilment, $actor): Shipment {
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

                    return $shipment;
                }

                if ($locked->state !== HardwareFulfilmentState::ShipmentCreated) {
                    throw ValidationException::withMessages([
                        'state' => 'AWB assignment requires SHIPMENT_CREATED.',
                    ]);
                }

                $this->assertProviderCallable();

                try {
                    $result = $this->gateway->assignAwb(
                        (string) $shipment->external_shipment_id,
                        $this->couriers->selectedCourierId($locked) ?? $shipment->courier_id,
                    );
                } catch (ShiprocketRetryableException $exception) {
                    $shipment->forceFill([
                        'failure_class' => 'retryable',
                        'last_error' => $exception->getMessage(),
                    ])->save();

                    throw $exception;
                }

                if ($result->retryable) {
                    throw new ShiprocketRetryableException($result->error ?? 'Shiprocket AWB assignment is retryable.');
                }

                if ($result->status !== 'assigned' || ! filled($result->awb)) {
                    throw new ShiprocketNonRetryableException($result->error ?? 'Shiprocket rejected AWB assignment.');
                }

                $this->bindAwb($locked, $shipment, $result->awb, $result->courierId, $result->courierName, $actor);

                return $shipment->fresh() ?? $shipment;
            });
        } catch (ShiprocketRetryableException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage().' Reconcile before assigning another AWB.',
            ]);
        } catch (ShiprocketNonRetryableException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage(),
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $ready
     */
    /**
     * @param  array<string, mixed>  $ready
     * @param  array{courier_id: string, courier_name: string|null}|null  $courier
     */
    private function openShipment(HardwareFulfilment $fulfilment, array $ready, ?array $courier): Shipment
    {
        $order = $fulfilment->commerceOrder;
        $shipmentNo = $this->shipmentNo($fulfilment);

        return Shipment::query()->create([
            'shipment_no' => $shipmentNo,
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

    private function searchForExisting(Shipment $shipment): ShiprocketSearchResult
    {
        try {
            return $this->gateway->searchOrders($shipment->shipment_no);
        } catch (ShiprocketRetryableException $exception) {
            $this->markAmbiguous($shipment, $exception->getMessage());

            throw $exception;
        } catch (ShiprocketDisabledException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage(),
            ]);
        }
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

    private function shipmentNo(HardwareFulfilment $fulfilment): string
    {
        return 'HW-'.$fulfilment->source_id;
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
        if (HardwareFulfilmentEligibility::isFrozenSourceId((string) $fulfilment->source_id)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Frozen pending hardware orders cannot be shipped.',
            ]);
        }
    }
}
