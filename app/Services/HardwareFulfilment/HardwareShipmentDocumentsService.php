<?php

namespace App\Services\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\HardwareFulfilmentState;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentEvent;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwarePickupRequestOutcome;
use App\Services\Shipping\Data\ShiprocketDocumentResult;
use App\Services\Shipping\NullShiprocketGateway;
use App\Services\Shipping\ShiprocketAlreadyQueuedPickup;
use App\Services\Shipping\ShiprocketDisabledException;
use App\Services\Shipping\ShiprocketNonRetryableException;
use App\Services\Shipping\ShiprocketRetryableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HardwareShipmentDocumentsService
{
    public function __construct(
        private readonly HardwareShipmentEligibility $eligibility,
        private readonly ShiprocketGateway $gateway,
    ) {}

    public function generateLabel(HardwareFulfilment $fulfilment, ?User $actor = null): Shipment
    {
        return $this->mutateDocument($fulfilment, $actor, 'label', function (HardwareFulfilment $locked, Shipment $shipment) use ($actor): Shipment {
            if (filled($shipment->label_url)) {
                return $shipment;
            }

            $this->assertAwbReady($locked, $shipment);
            $this->assertProviderCallable();

            $result = $this->callDocument(
                fn (): ShiprocketDocumentResult => $this->gateway->generateLabel((string) $shipment->external_shipment_id),
                'label',
            );

            $shipment->forceFill([
                'label_url' => $result->url,
                'label_fetched_at' => now(),
                'last_error' => null,
            ])->save();

            $this->recordShipmentEvent($shipment, 'label_generated', [
                'label_url' => $result->url,
            ]);
            $this->recordFulfilmentEvent($locked, $actor, 'hardware_label_generated', [
                'shipment_id' => $shipment->id,
                'provider' => $result->provider,
                'correlation_id' => $shipment->correlation_id,
                'result' => $result->status,
                'external_shipment_id' => $shipment->external_shipment_id,
            ]);

            return $shipment->fresh() ?? $shipment;
        });
    }

    public function requestPickup(HardwareFulfilment $fulfilment, ?User $actor = null): HardwarePickupRequestOutcome
    {
        $alreadyLocal = false;
        $reconciled = false;

        $shipment = $this->mutateDocument($fulfilment, $actor, 'pickup', function (HardwareFulfilment $locked, Shipment $shipment) use ($actor, &$alreadyLocal, &$reconciled): Shipment {
            if ($shipment->pickup_requested_at !== null) {
                $alreadyLocal = true;

                return $shipment;
            }

            $this->assertAwbReady($locked, $shipment);
            $this->assertProviderCallable();

            try {
                $result = $this->gateway->requestPickup((string) $shipment->external_shipment_id);
            } catch (ShiprocketRetryableException $exception) {
                throw ValidationException::withMessages([
                    'shipping' => $exception->getMessage().' Reconcile before requesting pickup again.',
                ]);
            }

            if ($result->retryable) {
                throw ValidationException::withMessages([
                    'shipping' => ($result->error ?? 'Shiprocket pickup is retryable.').' Reconcile before requesting pickup again.',
                ]);
            }

            $reconciled = ShiprocketAlreadyQueuedPickup::matchesRejectedResult($result);

            if (! $result->isAccepted() && ! $reconciled) {
                throw ValidationException::withMessages([
                    'shipping' => $result->error ?? 'Shiprocket rejected pickup generation.',
                ]);
            }

            $shipment->forceFill([
                'pickup_requested_at' => now(),
                'last_error' => null,
            ])->save();

            $activity = $reconciled ? 'pickup_reconciled_already_queued' : 'pickup_requested';
            $reason = $reconciled ? 'hardware_pickup_reconciled_already_queued' : 'hardware_pickup_requested';

            $this->recordShipmentEvent($shipment, $activity, [
                'provider' => $result->provider,
                'result' => $result->status,
                'already_queued' => $reconciled,
                'error' => $result->error,
            ]);
            $this->recordFulfilmentEvent($locked, $actor, $reason, [
                'shipment_id' => $shipment->id,
                'provider' => $result->provider,
                'correlation_id' => $shipment->correlation_id,
                'result' => $result->status,
                'already_queued' => $reconciled,
                'external_shipment_id' => $shipment->external_shipment_id,
            ]);

            return $shipment->fresh() ?? $shipment;
        });

        return new HardwarePickupRequestOutcome(
            shipment: $shipment,
            alreadyLocal: $alreadyLocal,
            reconciled: $reconciled,
        );
    }

    public function generateManifest(HardwareFulfilment $fulfilment, ?User $actor = null): Shipment
    {
        return $this->mutateDocument($fulfilment, $actor, 'manifest', function (HardwareFulfilment $locked, Shipment $shipment) use ($actor): Shipment {
            if (filled($shipment->manifest_url) || filled($shipment->manifest_id)) {
                return $shipment;
            }

            $this->assertAwbReady($locked, $shipment);
            if ($shipment->pickup_requested_at === null) {
                throw ValidationException::withMessages([
                    'shipping' => 'Manifest generation requires a requested pickup.',
                ]);
            }

            $this->assertProviderCallable();

            $result = $this->callDocument(
                fn (): ShiprocketDocumentResult => $this->gateway->generateManifest((string) $shipment->external_shipment_id),
                'manifest',
            );

            $shipment->forceFill([
                'manifest_url' => $result->url,
                'manifest_id' => $result->documentId ?? $shipment->manifest_id,
                'manifest_generated_at' => now(),
                'last_error' => null,
            ])->save();

            $this->recordShipmentEvent($shipment, 'manifest_generated', [
                'manifest_url' => $result->url,
                'manifest_id' => $result->documentId,
            ]);
            $this->recordFulfilmentEvent($locked, $actor, 'hardware_manifest_generated', [
                'shipment_id' => $shipment->id,
                'provider' => $result->provider,
                'correlation_id' => $shipment->correlation_id,
                'result' => $result->status,
                'external_shipment_id' => $shipment->external_shipment_id,
                'manifest_id' => $result->documentId,
            ]);

            return $shipment->fresh() ?? $shipment;
        });
    }

    public function markReadyForPickup(HardwareFulfilment $fulfilment, ?User $actor = null): HardwareFulfilment
    {
        $this->assertNotFrozen($fulfilment);

        return DB::transaction(function () use ($fulfilment, $actor): HardwareFulfilment {
            $locked = HardwareFulfilment::query()
                ->whereKey($fulfilment->id)
                ->lockForUpdate()
                ->with(['shipment', 'packageEvidences'])
                ->firstOrFail();

            $this->assertNotFrozen($locked);

            if ($locked->ready_for_pickup_at !== null) {
                return $locked;
            }

            $ready = $this->eligibility->inspect($locked);
            if (! $ready->canMarkReadyForPickup) {
                throw ValidationException::withMessages([
                    'shipping' => 'Ready for pickup requires a requested pickup and an assigned AWB.',
                ]);
            }

            $locked->forceFill([
                'ready_for_pickup_at' => now(),
            ])->save();

            $this->recordFulfilmentEvent($locked, $actor, 'hardware_ready_for_pickup', [
                'shipment_id' => $locked->shipment_id,
                'awb' => $locked->awb,
                'result' => 'ready',
            ]);

            return $locked->fresh() ?? $locked;
        });
    }

    /**
     * @param  callable(HardwareFulfilment, Shipment): Shipment  $callback
     */
    private function mutateDocument(
        HardwareFulfilment $fulfilment,
        ?User $actor,
        string $action,
        callable $callback,
    ): Shipment {
        $this->assertNotFrozen($fulfilment);

        try {
            return DB::transaction(function () use ($fulfilment, $callback): Shipment {
                $locked = HardwareFulfilment::query()
                    ->whereKey($fulfilment->id)
                    ->lockForUpdate()
                    ->firstOrFail();

                $this->assertNotFrozen($locked);

                $shipment = $locked->shipment_id !== null
                    ? Shipment::query()->lockForUpdate()->find($locked->shipment_id)
                    : Shipment::query()->where('hardware_fulfilment_id', $locked->id)->lockForUpdate()->first();

                if ($shipment === null || ! $shipment->isBound()) {
                    throw ValidationException::withMessages([
                        'shipping' => 'This action requires a created provider shipment.',
                    ]);
                }

                return $callback($locked, $shipment);
            });
        } catch (ShiprocketRetryableException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage().' Reconcile before retrying '.$action.'.',
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

    /**
     * @param  callable(): ShiprocketDocumentResult  $call
     */
    private function callDocument(callable $call, string $action): ShiprocketDocumentResult
    {
        try {
            $result = $call();
        } catch (ShiprocketRetryableException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage().' Reconcile before retrying '.$action.'.',
            ]);
        }

        if ($result->retryable) {
            throw ValidationException::withMessages([
                'shipping' => ($result->error ?? 'Shiprocket '.$action.' is retryable.').' Reconcile before retrying '.$action.'.',
            ]);
        }

        if ($result->status !== 'generated' || ($result->url === null && $result->documentId === null)) {
            throw ValidationException::withMessages([
                'shipping' => $result->error ?? 'Shiprocket rejected '.$action.' generation.',
            ]);
        }

        return $result;
    }

    private function assertAwbReady(HardwareFulfilment $fulfilment, Shipment $shipment): void
    {
        if ($fulfilment->state !== HardwareFulfilmentState::AwbAssigned) {
            throw ValidationException::withMessages([
                'state' => 'This action requires an assigned AWB.',
            ]);
        }

        if (! filled($shipment->awb)) {
            throw ValidationException::withMessages([
                'shipping' => 'This action requires a persisted provider AWB.',
            ]);
        }
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordShipmentEvent(Shipment $shipment, string $activity, array $payload): void
    {
        ShipmentEvent::query()->create([
            'shipment_id' => $shipment->id,
            'source' => 'provider',
            'activity' => $activity,
            'awb' => $shipment->awb,
            'external_order_id' => $shipment->external_order_id,
            'external_shipment_id' => $shipment->external_shipment_id,
            'payload' => $payload,
        ]);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function recordFulfilmentEvent(
        HardwareFulfilment $fulfilment,
        ?User $actor,
        string $reason,
        array $payload,
    ): void {
        HardwareFulfilmentEvent::query()->create([
            'hardware_fulfilment_id' => $fulfilment->id,
            'from_state' => $fulfilment->state,
            'to_state' => $fulfilment->state,
            'actor_type' => $actor !== null ? 'user' : 'system',
            'actor_id' => $actor?->id,
            'payload' => array_merge(['reason' => $reason], $payload),
            'created_at' => now(),
        ]);
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
}
