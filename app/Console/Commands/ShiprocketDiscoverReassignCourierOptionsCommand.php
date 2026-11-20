<?php

namespace App\Console\Commands;

use App\Models\HardwareFulfilment;
use App\Services\HardwareFulfilment\HardwareShipmentService;
use App\Services\Shipping\NullShiprocketGateway;
use App\Contracts\Shipping\ShiprocketGateway;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('desk:shiprocket-discover-reassign-courier-options
    {fulfilment : Hardware fulfilment id (allowlist only)}
    {--pickup-postcode=110020 : Shiprocket pickup postcode}
    {--delivery-postcode= : Delivery postcode (required)}
    {--weight=0.24 : Parcel weight in kg}
    {--json : Emit machine-readable JSON only}')]
#[Description('P-20-49: read-only Shiprocket serviceability for alternative courier reassignment.')]
class ShiprocketDiscoverReassignCourierOptionsCommand extends Command
{
    public function handle(HardwareShipmentService $shipments, ShiprocketGateway $gateway): int
    {
        $fulfilmentId = (int) $this->argument('fulfilment');
        $allowlist = array_map('intval', config('hardware_shiprocket_provider_recovery.courier_reassign_fulfilment_ids', []));

        if (! in_array($fulfilmentId, $allowlist, true)) {
            $this->error('Fulfilment '.$fulfilmentId.' is not on the courier reassignment allowlist.');

            return self::FAILURE;
        }

        if ($gateway instanceof NullShiprocketGateway) {
            $this->error('Shiprocket provider is not configured for HTTP on this environment.');

            return self::FAILURE;
        }

        $delivery = trim((string) $this->option('delivery-postcode'));
        if ($delivery === '') {
            $this->error('--delivery-postcode is required.');

            return self::FAILURE;
        }

        /** @var HardwareFulfilment $fulfilment */
        $fulfilment = HardwareFulfilment::query()->with('shipment')->findOrFail($fulfilmentId);

        $payload = $shipments->discoverReassignCourierOptions(
            $fulfilment,
            pickupPostcode: (string) $this->option('pickup-postcode'),
            deliveryPostcode: $delivery,
            weightKg: (float) $this->option('weight'),
        );
        $payload['fulfilment_id'] = $fulfilmentId;
        $payload['shipment_id'] = $fulfilment->shipment?->id;
        $payload['external_shipment_id'] = $fulfilment->shipment?->external_shipment_id;
        $payload['current_courier_id'] = $fulfilment->shipment?->courier_id;
        $payload['current_awb'] = $fulfilment->shipment?->awb;

        if ((bool) $this->option('json')) {
            $this->line(json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
        } else {
            $this->info('Courier options status: '.$payload['status']);
            foreach ($payload['options'] as $row) {
                $this->line(sprintf(
                    '- %s %s rate=%s etd=%s recommended=%s',
                    $row['courier_id'],
                    $row['courier_name'],
                    $row['rate'] ?? '?',
                    $row['estimated_delivery'] ?? '?',
                    ($row['provider_recommended'] ?? false) ? 'yes' : 'no',
                ));
            }
        }

        return self::SUCCESS;
    }
}