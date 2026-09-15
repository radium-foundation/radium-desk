<?php

namespace App\Services\HardwareFulfilment;

use App\Contracts\Shipping\ShiprocketGateway;
use App\Enums\ShipmentStatus;
use App\Models\HardwareFulfilment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\DashboardBroadcastService;
use App\Services\Shipping\NullShiprocketGateway;
use App\Services\Shipping\ShiprocketDisabledException;
use App\Services\Shipping\ShiprocketNonRetryableException;
use App\Services\Shipping\ShiprocketRetryableException;
use App\Services\Shipping\ShiprocketTrackingNormalizer;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

/**
 * Read-only Shiprocket track ingest. Does not create/cancel/pickup/label.
 */
final class HardwareShiprocketTrackingService
{
    public function __construct(
        private readonly ShiprocketGateway $gateway,
        private readonly ShiprocketTrackingNormalizer $normalizer,
        private readonly DashboardBroadcastService $dashboardBroadcast,
    ) {}

    /**
     * @return array{scanned: int, changed: int, skipped: int, failed: int}
     */
    public function syncEligible(int $limit = 25): array
    {
        $stats = ['scanned' => 0, 'changed' => 0, 'skipped' => 0, 'failed' => 0];

        if ($this->gateway instanceof NullShiprocketGateway) {
            return $stats;
        }

        $minAge = max(60, (int) config('shipping.tracking.min_interval_seconds', 300));
        $cutoff = Carbon::now()->subSeconds($minAge);

        $shipments = Shipment::query()
            ->where('provider', 'shiprocket')
            ->where('status', ShipmentStatus::AwbAssigned)
            ->where(function ($query): void {
                $query->whereNotNull('awb')->where('awb', '!=', '')
                    ->orWhere(function ($inner): void {
                        $inner->whereNotNull('external_shipment_id')->where('external_shipment_id', '!=', '');
                    });
            })
            ->where(function ($query) use ($cutoff): void {
                $query->whereNull('provider_tracked_at')
                    ->orWhere('provider_tracked_at', '<=', $cutoff);
            })
            ->orderByRaw('provider_tracked_at IS NOT NULL')
            ->orderBy('provider_tracked_at')
            ->limit(max(1, $limit))
            ->get(['id']);

        foreach ($shipments as $row) {
            $stats['scanned']++;
            try {
                $result = $this->syncShipmentId((int) $row->id);
                if ($result === 'changed') {
                    $stats['changed']++;
                } else {
                    $stats['skipped']++;
                }
            } catch (Throwable $exception) {
                $stats['failed']++;
                Log::warning('shiprocket.track.sync_failed', [
                    'shipment_id' => $row->id,
                    'class' => $exception::class,
                ]);
            }
        }

        return $stats;
    }

    /**
     * @return 'changed'|'unchanged'|'skipped'
     */
    public function syncShipmentId(int $shipmentId): string
    {
        return DB::transaction(function () use ($shipmentId): string {
            $shipment = Shipment::query()
                ->whereKey($shipmentId)
                ->lockForUpdate()
                ->first();

            if ($shipment === null) {
                return 'skipped';
            }

            $awb = trim((string) $shipment->awb);
            $externalId = trim((string) $shipment->external_shipment_id);

            if ($awb === '' && $externalId === '') {
                return 'skipped';
            }

            try {
                $track = $awb !== ''
                    ? $this->gateway->trackByAwb($awb)
                    : $this->gateway->trackByShipment($externalId);
            } catch (ShiprocketRetryableException|ShiprocketNonRetryableException|ShiprocketDisabledException) {
                return 'skipped';
            }

            $raw = trim((string) $track->status);
            if ($raw === '' || $track->retryable) {
                return 'skipped';
            }

            $normalized = $this->normalizer->normalizeTrack($raw, $track->activities);
            $previousRaw = (string) ($shipment->provider_track_status ?? '');
            $previousNormalized = (string) ($shipment->provider_track_normalized ?? '');

            if ($previousRaw === $raw && $previousNormalized === $normalized->value) {
                $shipment->forceFill([
                    'provider_tracked_at' => now(),
                    'last_reconciled_at' => now(),
                ])->save();

                return 'unchanged';
            }

            $shipment->forceFill([
                'provider_track_status' => mb_substr($raw, 0, 64),
                'provider_track_normalized' => $normalized->value,
                'provider_tracked_at' => now(),
                'last_reconciled_at' => now(),
            ])->save();

            ShipmentEvent::query()->create([
                'shipment_id' => $shipment->id,
                'source' => 'reconcile',
                'activity' => 'track_synced',
                'payload' => [
                    'provider_track_status' => $raw,
                    'provider_track_normalized' => $normalized->value,
                ],
            ]);

            $fulfilment = $shipment->hardwareFulfilment
                ?? ($shipment->hardware_fulfilment_id !== null
                    ? HardwareFulfilment::query()->find($shipment->hardware_fulfilment_id)
                    : null);

            if ($fulfilment instanceof HardwareFulfilment) {
                $this->dashboardBroadcast->hardwareFulfilmentUpdated($fulfilment, null);
            }

            return 'changed';
        });
    }
}
