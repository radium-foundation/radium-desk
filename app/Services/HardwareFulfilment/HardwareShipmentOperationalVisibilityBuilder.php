<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\ShipmentStatus;
use App\Enums\ShiprocketTrackNormalized;
use App\Models\HardwareFulfilment;
use App\Models\Shipment;
use App\Models\ShipmentEvent;
use App\Services\HardwareFulfilment\Data\HardwareShipmentApiAuditRow;
use App\Services\HardwareFulfilment\Data\HardwareShipmentOperationalVisibility;
use App\Services\HardwareFulfilment\Data\HardwareShipmentReadiness;
use App\Services\Shipping\ShiprocketApiResponseSanitizer;
use App\Services\Shipping\ShiprocketTrackingNormalizer;
use Illuminate\Support\Carbon;

final class HardwareShipmentOperationalVisibilityBuilder
{
    private const TRACK_STALE_HOURS = 48;

    /** @var list<string> */
    private const AUDIT_PAYLOAD_ALLOWLIST = [
        'operation',
        'http_method',
        'endpoint',
        'http_status',
        'outcome',
        'duration_ms',
        'provider_message',
        'retryable',
        'awb_code',
        'provider_order_id',
        'provider_shipment_id',
        'label_created',
        'pickup_status',
        'shipment_status',
        'current_status_id',
        'current_status',
        'courier_option_count',
    ];

    public function build(
        ?Shipment $boundShipment,
        HardwareShipmentReadiness $ready,
        HardwareFulfilment $fulfilment,
    ): HardwareShipmentOperationalVisibility {
        if ($ready->isExternalShipping) {
            return $this->externalVisibility($boundShipment, $ready, $fulfilment);
        }

        if (! $ready->alreadyCreated && $boundShipment === null) {
            return $this->inactive($ready, 'Provider order not created', 'Desk has not created a Shiprocket shipment yet.', applies: false);
        }

        [$headline, $detail, $blocked, $blockedReason] = $this->resolveLifecycle($boundShipment, $ready);
        $partial = $this->partialSuccess($boundShipment, $ready);
        $trackFreshness = $this->trackFreshness($boundShipment);
        $refs = HardwareShipmentMerchantReference::displayReferences($boundShipment, $fulfilment);

        return new HardwareShipmentOperationalVisibility(
            applies: true,
            lifecycleHeadline: $headline,
            lifecycleDetail: $detail,
            blocked: $blocked,
            blockedReason: $blockedReason,
            partialSuccess: $partial['flag'],
            partialSuccessNote: $partial['note'],
            deskShipmentStatus: $ready->status,
            shiprocketMerchantReference: $refs['display'],
            merchantReferenceIsLegacyHw: $refs['legacy_hw'],
            canonicalMerchantReference: $refs['canonical'],
            externalShipmentId: $ready->providerShipmentId,
            externalOrderId: $boundShipment?->external_order_id,
            awb: $ready->awb,
            courier: $ready->courier,
            shiprocketTrackStatus: $this->displayOrMissing($ready->providerTrackStatus),
            shiprocketTrackNormalized: $this->normalizedTrackLabel($ready->providerTrackNormalized),
            courierMovementLabel: $this->courierMovementLabel($ready),
            providerStatusCode: $this->providerStatusCode($ready->providerTrackStatus, $boundShipment),
            sanitizedProviderRejection: $this->sanitizeOperatorText($ready->providerRejection ?? $boundShipment?->last_error),
            lastProviderTrackAt: $this->formatTimestamp($boundShipment?->provider_tracked_at),
            trackFreshness: $trackFreshness,
            lastProviderApiAt: $this->lastProviderApiAt($boundShipment),
            labelAvailability: $ready->labelUrl !== null ? 'Available' : $ready->labelStatus,
            deskPickupEvidence: $this->deskPickupEvidence($ready, $boundShipment),
            pickupEvidenceNote: $this->pickupEvidenceNote($ready, $boundShipment),
            apiAuditRows: $this->apiAuditRows($boundShipment),
        );
    }

    private function inactive(
        HardwareShipmentReadiness $ready,
        string $headline,
        string $detail,
        bool $applies = true,
    ): HardwareShipmentOperationalVisibility {
        return new HardwareShipmentOperationalVisibility(
            applies: $applies,
            lifecycleHeadline: $headline,
            lifecycleDetail: $detail,
            blocked: false,
            blockedReason: null,
            partialSuccess: false,
            partialSuccessNote: null,
            deskShipmentStatus: $ready->status,
            shiprocketMerchantReference: '—',
            merchantReferenceIsLegacyHw: false,
            canonicalMerchantReference: null,
            externalShipmentId: null,
            externalOrderId: null,
            awb: $ready->awb,
            courier: $ready->courier,
            shiprocketTrackStatus: 'Not available',
            shiprocketTrackNormalized: 'Not tracked',
            courierMovementLabel: 'Not available',
            providerStatusCode: '—',
            sanitizedProviderRejection: $this->sanitizeOperatorText($ready->providerRejection),
            lastProviderTrackAt: 'Never',
            trackFreshness: 'missing',
            lastProviderApiAt: 'Never',
            labelAvailability: $ready->labelStatus,
            deskPickupEvidence: 'Not applicable',
            pickupEvidenceNote: 'Pickup cannot be requested until a provider shipment exists.',
            apiAuditRows: [],
        );
    }

    private function externalVisibility(
        ?Shipment $boundShipment,
        HardwareShipmentReadiness $ready,
        HardwareFulfilment $fulfilment,
    ): HardwareShipmentOperationalVisibility {
        $refs = HardwareShipmentMerchantReference::displayReferences($boundShipment, $fulfilment);

        return new HardwareShipmentOperationalVisibility(
            applies: true,
            lifecycleHeadline: 'External shipping',
            lifecycleDetail: 'This fulfilment uses external shipping, not Shiprocket.',
            blocked: false,
            blockedReason: null,
            partialSuccess: false,
            partialSuccessNote: null,
            deskShipmentStatus: $ready->status,
            shiprocketMerchantReference: $refs['display'],
            merchantReferenceIsLegacyHw: false,
            canonicalMerchantReference: $refs['canonical'],
            externalShipmentId: $ready->providerShipmentId,
            externalOrderId: $boundShipment?->external_order_id,
            awb: $ready->awb,
            courier: $ready->courier,
            shiprocketTrackStatus: 'N/A',
            shiprocketTrackNormalized: 'N/A',
            courierMovementLabel: $ready->trackingUrl !== null ? 'Track via courier URL' : 'No tracking URL',
            providerStatusCode: '—',
            sanitizedProviderRejection: null,
            lastProviderTrackAt: 'N/A',
            trackFreshness: 'n/a',
            lastProviderApiAt: 'N/A',
            labelAvailability: $ready->labelStatus,
            deskPickupEvidence: 'N/A',
            pickupEvidenceNote: 'External shipments are not picked up through Shiprocket.',
            apiAuditRows: [],
        );
    }

    /**
     * @return array{0: string, 1: string, 2: bool, 3: ?string}
     */
    private function resolveLifecycle(?Shipment $shipment, HardwareShipmentReadiness $ready): array
    {
        if ($ready->providerRejection !== null && trim($ready->providerRejection) !== '') {
            return [
                'Provider rejected shipment create',
                'Desk recorded a provider rejection. No AWB or label exists until resolved.',
                true,
                $this->sanitizeOperatorText($ready->providerRejection),
            ];
        }

        $deskStatus = $shipment?->status;
        if ($deskStatus === ShipmentStatus::Failed) {
            return [
                'Desk shipment failed',
                'Last provider interaction failed. Check sanitized audit entries below.',
                true,
                $this->sanitizeOperatorText($shipment?->last_error),
            ];
        }

        if (ShiprocketTrackingNormalizer::isPickupErrorLabel($ready->providerTrackStatus)) {
            $pickupDesk = $ready->pickupStatus === 'Requested' ? 'Desk pickup was requested.' : 'Desk pickup was never requested.';

            return [
                'Pickup error (Shiprocket / courier)',
                'Shiprocket track reports Pickup Error. '.$pickupDesk.' AWB alone does not mean pickup succeeded.',
                true,
                'Resolve with Shiprocket or the courier before label/pickup recovery.',
            ];
        }

        if (! filled($ready->awb)) {
            if ($ready->alreadyCreated) {
                return [
                    'Provider order created',
                    'Shiprocket accepted the order. AWB is not assigned yet.',
                    false,
                    null,
                ];
            }

            return ['Provider order pending', 'Complete shipment creation on Desk.', false, null];
        }

        if ($ready->labelUrl === null) {
            return [
                'AWB assigned — label not generated',
                'Courier AWB exists locally. Official Shiprocket label has not been generated.',
                false,
                null,
            ];
        }

        if ($ready->pickupStatus !== 'Requested') {
            return [
                'Label generated — pickup not requested',
                'Label is available. Desk has not called Request Pickup (pickup_requested_at is empty).',
                false,
                null,
            ];
        }

        $normalized = ShiprocketTrackNormalized::tryFrom((string) ($ready->providerTrackNormalized ?? ''));
        if ($normalized === ShiprocketTrackNormalized::Delivered) {
            return ['Delivered (provider track)', 'Shiprocket track reports delivered.', false, null];
        }
        if ($normalized === ShiprocketTrackNormalized::InTransit) {
            return ['In transit (provider track)', 'Courier movement reported via Shiprocket track.', false, null];
        }
        if ($normalized === ShiprocketTrackNormalized::PickedUp) {
            return ['Picked up (provider track)', 'Provider track shows picked up.', false, null];
        }
        if ($normalized === ShiprocketTrackNormalized::OutForPickup) {
            return ['Out for pickup (provider track)', 'Courier is out for pickup per Shiprocket track.', false, null];
        }
        if ($normalized === ShiprocketTrackNormalized::PickupQueued) {
            return ['Pickup scheduled / queued (provider track)', 'Provider queued pickup. Confirm against Desk pickup request timestamp.', false, null];
        }

        if ($ready->manifestStatus === 'Available' || $ready->readyForPickup) {
            return [
                'Ready for dispatch',
                'Pickup requested and manifest/ready steps recorded on Desk.',
                false,
                null,
            ];
        }

        return [
            'Pickup requested (Desk)',
            'Desk recorded pickup_requested_at. Awaiting manifest or ready-for-pickup steps.',
            false,
            null,
        ];
    }

    /**
     * @return array{flag: bool, note: ?string}
     */
    private function partialSuccess(?Shipment $shipment, HardwareShipmentReadiness $ready): array
    {
        foreach ($this->apiAuditRows($shipment) as $row) {
            if ($row->outcome === 'partial') {
                return [
                    'flag' => true,
                    'note' => 'Recent Shiprocket API outcome was partial: '.$row->detailLine,
                ];
            }
        }

        if (filled($ready->awb) && $ready->labelUrl === null && $ready->alreadyCreated) {
            return [
                'flag' => true,
                'note' => 'AWB assigned without a stored label URL.',
            ];
        }

        return ['flag' => false, 'note' => null];
    }

    private function trackFreshness(?Shipment $shipment): string
    {
        $trackedAt = $shipment?->provider_tracked_at;
        if ($trackedAt === null) {
            return filled($shipment?->awb) ? 'missing' : 'not_applicable';
        }

        $hours = $trackedAt->diffInHours(now());

        return $hours >= self::TRACK_STALE_HOURS ? 'stale' : 'fresh';
    }

    private function deskPickupEvidence(HardwareShipmentReadiness $ready, ?Shipment $shipment): string
    {
        if ($shipment?->pickup_requested_at !== null) {
            return 'Requested on '.$this->formatTimestamp($shipment->pickup_requested_at);
        }

        if ($ready->pickupStatus === 'Requested') {
            return 'Requested (Desk)';
        }

        return 'Not requested';
    }

    private function pickupEvidenceNote(HardwareShipmentReadiness $ready, ?Shipment $shipment): string
    {
        if ($shipment?->pickup_requested_at === null && ShiprocketTrackingNormalizer::isPickupErrorLabel($ready->providerTrackStatus)) {
            return 'Provider track shows Pickup Error without a Desk pickup request.';
        }

        if ($shipment?->pickup_requested_at === null
            && ShiprocketTrackNormalized::tryFrom((string) ($ready->providerTrackNormalized ?? ''))?->overridesReadyForPickup()) {
            return 'Provider track shows advanced pickup movement, but Desk has no pickup_requested_at.';
        }

        return '';
    }

    private function courierMovementLabel(HardwareShipmentReadiness $ready): string
    {
        $courier = strtolower((string) $ready->courier);
        $track = $this->normalizedTrackLabel($ready->providerTrackNormalized);
        if (str_contains($courier, 'shadowfax') || str_contains($courier, 'instant pickup')) {
            return $track.' (via Shiprocket → courier partner)';
        }

        return $track;
    }

    private function normalizedTrackLabel(?string $normalized): string
    {
        $enum = ShiprocketTrackNormalized::tryFrom(trim((string) $normalized));

        return $enum?->dashboardStatusLabel() ?? 'Not tracked or unknown';
    }

    private function providerStatusCode(?string $providerTrackStatus, ?Shipment $shipment): string
    {
        $raw = trim((string) $providerTrackStatus);
        if ($raw !== '' && ctype_digit($raw)) {
            return $raw;
        }

        if ($shipment !== null && filled($shipment->provider_track_status) && ctype_digit((string) $shipment->provider_track_status)) {
            return (string) $shipment->provider_track_status;
        }

        return '—';
    }

    private function lastProviderApiAt(?Shipment $shipment): string
    {
        if ($shipment === null) {
            return 'Never';
        }

        $latest = $shipment->events
            ->where('source', 'shiprocket_api')
            ->sortByDesc('created_at')
            ->first();

        return $this->formatTimestamp($latest?->created_at);
    }

    /**
     * @return list<HardwareShipmentApiAuditRow>
     */
    private function apiAuditRows(?Shipment $shipment): array
    {
        if ($shipment === null) {
            return [];
        }

        $tz = (string) config('app.timezone', 'Asia/Kolkata');

        return $shipment->events
            ->where('source', 'shiprocket_api')
            ->sortByDesc('created_at')
            ->take(12)
            ->map(function (ShipmentEvent $event) use ($tz): HardwareShipmentApiAuditRow {
                $payload = is_array($event->payload) ? $event->payload : [];
                $operation = (string) ($payload['operation'] ?? str_replace('shiprocket_api:', '', (string) $event->activity));
                $outcome = (string) ($payload['outcome'] ?? 'unknown');
                $httpStatus = (int) ($payload['http_status'] ?? 0);
                $providerMessage = isset($payload['provider_message'])
                    ? ShiprocketApiResponseSanitizer::sanitizeMessage((string) $payload['provider_message'])
                    : null;
                $detail = $this->auditDetailLine($payload, $operation, $outcome, $httpStatus);

                return new HardwareShipmentApiAuditRow(
                    activity: (string) $event->activity,
                    recordedAtIst: $event->created_at?->timezone($tz)->format('Y-m-d H:i') ?? '—',
                    operation: $operation,
                    outcome: $outcome,
                    httpStatus: $httpStatus,
                    providerMessage: $providerMessage,
                    detailLine: $detail,
                );
            })
            ->values()
            ->all();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    private function auditDetailLine(array $payload, string $operation, string $outcome, int $httpStatus): string
    {
        $parts = [str_replace('_', ' ', $operation), $outcome, 'HTTP '.$httpStatus];
        foreach (self::AUDIT_PAYLOAD_ALLOWLIST as $key) {
            if (! array_key_exists($key, $payload)) {
                continue;
            }
            $value = $payload[$key];
            if ($value === null || $value === '') {
                continue;
            }
            if (is_scalar($value)) {
                $parts[] = str_replace('_', ' ', $key).'='.ShiprocketApiResponseSanitizer::sanitizeMessage((string) $value);
            }
        }

        return implode(' · ', $parts);
    }

    private function formatTimestamp(mixed $at): string
    {
        if ($at === null) {
            return 'Never';
        }

        $tz = (string) config('app.timezone', 'Asia/Kolkata');
        $carbon = $at instanceof Carbon ? $at : Carbon::parse($at);

        return $carbon->timezone($tz)->format('Y-m-d H:i').' IST';
    }

    private function displayOrMissing(?string $value): string
    {
        $text = trim((string) $value);

        return $text !== '' ? $text : 'Not available';
    }

    private function sanitizeOperatorText(?string $value): ?string
    {
        $text = trim((string) $value);

        return $text === '' ? null : ShiprocketApiResponseSanitizer::sanitizeMessage($text);
    }
}
