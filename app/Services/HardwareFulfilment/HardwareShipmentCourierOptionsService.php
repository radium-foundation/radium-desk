<?php

namespace App\Services\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\HardwareFulfilment\Data\HardwareShipmentCourierQuote;
use App\Services\Shipping\Data\ShiprocketCourierOption;
use App\Services\Shipping\Data\ShiprocketCourierOptionsRequest;
use App\Services\Shipping\NullShiprocketGateway;
use App\Services\Shipping\ShiprocketDisabledException;
use App\Services\Shipping\ShiprocketNonRetryableException;
use App\Services\Shipping\ShiprocketRetryableException;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HardwareShipmentCourierOptionsService
{
    public function __construct(
        private readonly HardwareShipmentEligibility $eligibility,
        private readonly HardwarePickupResolver $pickups,
        private readonly ShiprocketGateway $gateway,
        private readonly HardwareShipmentCollectionModeResolver $collectionModes,
    ) {}

    /**
     * @return list<array<string, mixed>>
     */
    public function fetch(HardwareFulfilment $fulfilment, ?User $actor = null): array
    {
        $this->assertNotFrozen($fulfilment);
        $this->assertProviderCallable();

        try {
            return DB::transaction(function () use ($fulfilment): array {
                $locked = HardwareFulfilment::query()
                    ->whereKey($fulfilment->id)
                    ->lockForUpdate()
                    ->with(['commerceOrder.items', 'serials.inventorySerial.product.packaging'])
                    ->firstOrFail();

                $this->assertNotFrozen($locked);
                $this->assertNoBoundShipment($locked);

                $ready = $this->quoteReady($locked);
                $pickupPostcode = $this->pickups->requirePostcodeForBranch($ready['branch']);
                $providerOrderId = $this->providerOrderId($locked);
                $collection = $this->collectionModes->forFulfilment($locked);
                $fingerprint = HardwareShipmentCourierQuote::fingerprint(
                    $ready,
                    $pickupPostcode,
                    $collection->serviceabilityCod(),
                    $providerOrderId,
                );

                $result = $this->gateway->listCourierOptions(new ShiprocketCourierOptionsRequest(
                    pickupPostcode: $pickupPostcode,
                    deliveryPostcode: $ready['shipping']['pincode'],
                    weight: $ready['parcel']['weight'],
                    cod: $collection->serviceabilityCod(),
                    providerOrderId: $providerOrderId,
                ));

                if ($result->retryable) {
                    throw new ShiprocketRetryableException(
                        $result->error ?? 'Shiprocket courier options are retryable.',
                    );
                }

                if ($result->status !== 'listed') {
                    throw ValidationException::withMessages([
                        'shipping' => $result->error ?? 'Shiprocket returned no courier options.',
                    ]);
                }

                $options = array_map(
                    static fn (ShiprocketCourierOption $option): array => $option->toArray(),
                    $result->options,
                );

                $locked->forceFill([
                    'courier_options_snapshot' => [
                        'options' => $options,
                        'recommended_courier_id' => $result->recommendedCourierId,
                        'recommendation_returned' => $result->recommendationReturned,
                        'pickup_postcode' => $pickupPostcode,
                        'delivery_postcode' => $ready['shipping']['pincode'],
                        'weight' => $ready['parcel']['weight'],
                        'cod' => $collection->serviceabilityCod(),
                        'collection_mode' => $collection->value,
                        'provider_order_id' => $providerOrderId,
                    ],
                    'courier_options_fingerprint' => $fingerprint,
                    'courier_options_fetched_at' => now(),
                    'courier_options_expires_at' => now()->addSeconds(HardwareShipmentCourierQuote::ttlSeconds()),
                    'selected_courier_id' => null,
                    'selected_courier_name' => null,
                    'selected_courier_at' => null,
                    'selected_courier_by_user_id' => null,
                ])->save();

                return $options;
            });
        } catch (ShiprocketRetryableException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage().' Fetch courier options again before creating a shipment.',
            ]);
        } catch (ShiprocketDisabledException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage(),
            ]);
        }
    }

    public function select(HardwareFulfilment $fulfilment, string $courierId, ?User $actor = null): void
    {
        $this->assertNotFrozen($fulfilment);

        DB::transaction(function () use ($fulfilment, $courierId, $actor): void {
            $locked = HardwareFulfilment::query()
                ->whereKey($fulfilment->id)
                ->lockForUpdate()
                ->with(['commerceOrder.items', 'serials.inventorySerial.product.packaging'])
                ->firstOrFail();

            $this->assertNotFrozen($locked);
            $this->assertNoBoundShipment($locked);

            $ready = $this->quoteReady($locked);
            $pickupPostcode = $this->pickups->requirePostcodeForBranch($ready['branch']);
            $fingerprint = HardwareShipmentCourierQuote::fingerprint(
                $ready,
                $pickupPostcode,
                $this->collectionModes->forFulfilment($locked)->serviceabilityCod(),
                $this->providerOrderId($locked),
            );

            if (! HardwareShipmentCourierQuote::isFresh($locked, $fingerprint)) {
                throw ValidationException::withMessages([
                    'courier_id' => 'Courier options are stale. Get courier options again.',
                ]);
            }

            $selected = trim($courierId);
            $match = null;
            foreach (HardwareShipmentCourierQuote::options($locked) as $option) {
                if (($option['courier_id'] ?? null) === $selected) {
                    $match = $option;
                    break;
                }
            }

            if ($match === null) {
                throw ValidationException::withMessages([
                    'courier_id' => 'Selected courier was not returned by Shiprocket for this fulfilment.',
                ]);
            }

            $locked->forceFill([
                'selected_courier_id' => $match['courier_id'],
                'selected_courier_name' => $match['courier_name'] ?? null,
                'selected_courier_at' => now(),
                'selected_courier_by_user_id' => $actor?->id,
            ])->save();
        });
    }

    /**
     * @return array{courier_id: string, courier_name: string|null}
     */
    public function requireValidSelection(HardwareFulfilment $fulfilment): array
    {
        $ready = $this->eligibility->require($fulfilment);
        $pickupPostcode = $this->pickups->requirePostcodeForBranch($ready['branch']);
        $fingerprint = HardwareShipmentCourierQuote::fingerprint(
            $ready,
            $pickupPostcode,
            $this->collectionModes->forFulfilment($fulfilment)->serviceabilityCod(),
            $this->providerOrderId($fulfilment),
        );

        if (! HardwareShipmentCourierQuote::hasValidSelection($fulfilment, $fingerprint)) {
            throw ValidationException::withMessages([
                'courier_id' => 'Select a current Shiprocket courier option before creating the shipment.',
            ]);
        }

        return [
            'courier_id' => (string) $fulfilment->selected_courier_id,
            'courier_name' => $fulfilment->selected_courier_name,
        ];
    }

    public function selectedCourierId(HardwareFulfilment $fulfilment): ?string
    {
        $id = trim((string) $fulfilment->selected_courier_id);

        return $id === '' ? null : $id;
    }

    /**
     * Re-quote serviceability for a bound shipment immediately before AWB.
     * Keeps the stored courier when it is still returned. If it is not,
     * uses Shiprocket's current recommended id when that id is in the list.
     * Does not pick the first listed courier and does not retry AWB.
     *
     * @return array{courier_id: string, courier_name: string|null}
     */
    public function resolveCourierForAwb(HardwareFulfilment $fulfilment, ?User $actor = null): array
    {
        $this->assertNotFrozen($fulfilment);
        $this->assertProviderCallable();

        try {
            $resolved = DB::transaction(function () use ($fulfilment, $actor): array {
                $locked = HardwareFulfilment::query()
                    ->whereKey($fulfilment->id)
                    ->lockForUpdate()
                    ->with(['commerceOrder.items', 'serials.inventorySerial.product.packaging', 'shipment'])
                    ->firstOrFail();

                $this->assertNotFrozen($locked);

                $shipment = $locked->shipment_id !== null
                    ? $locked->shipment
                    : $locked->shipment()->lockForUpdate()->first();

                if ($shipment === null || ! $shipment->isBound()) {
                    throw ValidationException::withMessages([
                        'shipping' => 'AWB assignment requires a created provider shipment.',
                    ]);
                }

                if (filled($shipment->awb)) {
                    $id = $this->selectedCourierId($locked) ?? trim((string) $shipment->courier_id);

                    return [
                        'courier_id' => $id,
                        'courier_name' => $locked->selected_courier_name ?? $shipment->courier_name,
                    ];
                }

                $ready = $this->eligibility->quoteInputs($locked);
                $pickupPostcode = $this->pickups->requirePostcodeForBranch($ready['branch']);
                $providerOrderId = $this->providerOrderId($locked);
                $collection = $this->collectionModes->forFulfilment($locked);
                $fingerprint = HardwareShipmentCourierQuote::fingerprint(
                    $ready,
                    $pickupPostcode,
                    $collection->serviceabilityCod(),
                    $providerOrderId,
                );

                $result = $this->gateway->listCourierOptions(new ShiprocketCourierOptionsRequest(
                    pickupPostcode: $pickupPostcode,
                    deliveryPostcode: $ready['shipping']['pincode'],
                    weight: $ready['parcel']['weight'],
                    cod: $collection->serviceabilityCod(),
                    providerOrderId: $providerOrderId,
                ));

                if ($result->retryable) {
                    throw new ShiprocketRetryableException(
                        $result->error ?? 'Shiprocket courier options are retryable.',
                    );
                }

                if ($result->status !== 'listed') {
                    throw ValidationException::withMessages([
                        'shipping' => $result->error ?? 'Shiprocket returned no courier options.',
                    ]);
                }

                $options = array_map(
                    static fn (ShiprocketCourierOption $option): array => $option->toArray(),
                    $result->options,
                );

                $locked->forceFill([
                    'courier_options_snapshot' => [
                        'options' => $options,
                        'recommended_courier_id' => $result->recommendedCourierId,
                        'recommendation_returned' => $result->recommendationReturned,
                        'pickup_postcode' => $pickupPostcode,
                        'delivery_postcode' => $ready['shipping']['pincode'],
                        'weight' => $ready['parcel']['weight'],
                        'cod' => $collection->serviceabilityCod(),
                        'collection_mode' => $collection->value,
                        'provider_order_id' => $providerOrderId,
                        'quoted_for' => 'awb',
                    ],
                    'courier_options_fingerprint' => $fingerprint,
                    'courier_options_fetched_at' => now(),
                    'courier_options_expires_at' => now()->addSeconds(HardwareShipmentCourierQuote::ttlSeconds()),
                ])->save();

                $storedId = $this->selectedCourierId($locked)
                    ?? (trim((string) $shipment->courier_id) === '' ? null : trim((string) $shipment->courier_id));
                $stored = $this->optionById($options, $storedId);

                if ($stored !== null) {
                    $this->persistSelection($locked, $shipment, $stored, $actor, keepActor: true);

                    return [
                        'courier_id' => (string) $stored['courier_id'],
                        'courier_name' => $stored['courier_name'] ?? null,
                    ];
                }

                $recommended = $this->optionById($options, $result->recommendedCourierId);
                if ($recommended !== null) {
                    $this->persistSelection($locked, $shipment, $recommended, $actor, keepActor: false);

                    return [
                        'courier_id' => (string) $recommended['courier_id'],
                        'courier_name' => $recommended['courier_name'] ?? null,
                    ];
                }

                return [
                    'error' => $storedId === null
                        ? 'Shiprocket returned no currently serviceable courier for this shipment. Select a courier from a fresh Get Courier Options result, then Assign AWB.'
                        : 'Courier '.$storedId.' is no longer serviceable. Select a current Shiprocket courier, then Assign AWB.',
                ];
            });
        } catch (ShiprocketRetryableException $exception) {
            throw ValidationException::withMessages([
                'shipping' => $exception->getMessage().' Retry Assign AWB after Shiprocket is reachable. This is not a courier-serviceability rejection.',
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

        if (isset($resolved['error'])) {
            throw ValidationException::withMessages([
                'courier_id' => $resolved['error'],
            ]);
        }

        return [
            'courier_id' => (string) $resolved['courier_id'],
            'courier_name' => $resolved['courier_name'] ?? null,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $options
     * @return array<string, mixed>|null
     */
    private function optionById(array $options, ?string $courierId): ?array
    {
        $id = trim((string) $courierId);
        if ($id === '') {
            return null;
        }

        foreach ($options as $option) {
            if (($option['courier_id'] ?? null) === $id) {
                return $option;
            }
        }

        return null;
    }

    /**
     * @param  array<string, mixed>  $option
     */
    private function persistSelection(
        HardwareFulfilment $fulfilment,
        Shipment $shipment,
        array $option,
        ?User $actor,
        bool $keepActor,
    ): void {
        $courierId = (string) $option['courier_id'];
        $courierName = $option['courier_name'] ?? null;
        $same = $this->selectedCourierId($fulfilment) === $courierId;

        $fulfilment->forceFill([
            'selected_courier_id' => $courierId,
            'selected_courier_name' => $courierName,
            'selected_courier_at' => $same && $fulfilment->selected_courier_at !== null
                ? $fulfilment->selected_courier_at
                : now(),
            'selected_courier_by_user_id' => $keepActor && $same
                ? $fulfilment->selected_courier_by_user_id
                : $actor?->id,
        ])->save();

        $shipment->forceFill([
            'courier_id' => $courierId,
            'courier_name' => $courierName,
        ])->save();
    }

    /**
     * @return array{
     *     invoice: StatutoryInvoice,
     *     serials: list<string>,
     *     branch: InventoryBranch,
     *     pickup: string,
     *     shipping: array<string, string>,
     *     parcel: array{weight: float, length: float, breadth: float, height: float},
     *     parcel_source: string
     * }
     */
    private function quoteReady(HardwareFulfilment $fulfilment): array
    {
        $shipment = $fulfilment->shipment_id !== null
            ? $fulfilment->shipment
            : $fulfilment->shipment()->first();

        if ($shipment !== null && $shipment->isBound() && ! filled($shipment->awb)) {
            return $this->eligibility->quoteInputs($fulfilment);
        }

        return $this->eligibility->require($fulfilment);
    }

    private function providerOrderId(HardwareFulfilment $fulfilment): ?string
    {
        $shipment = $fulfilment->shipment_id !== null
            ? $fulfilment->shipment
            : $fulfilment->shipment()->first();

        $orderId = trim((string) ($shipment?->external_order_id ?? ''));

        return $orderId === '' ? null : $orderId;
    }

    private function assertNoBoundShipment(HardwareFulfilment $fulfilment): void
    {
        $shipment = $fulfilment->shipment_id !== null
            ? $fulfilment->shipment
            : $fulfilment->shipment()->first();

        if ($shipment !== null && $shipment->isBound() && filled($shipment->awb)) {
            throw ValidationException::withMessages([
                'shipping' => 'Provider shipment already has an AWB. Courier options cannot be changed.',
            ]);
        }
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
