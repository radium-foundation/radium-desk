<?php

namespace App\Services\HardwareFulfilment\Data;

/**
 * Read-only operational snapshot for hardware fulfilment shipment UI.
 * Built only from persisted Desk/provider fields — no invented lifecycle steps.
 *
 * @param  list<HardwareShipmentApiAuditRow>  $apiAuditRows
 */
final class HardwareShipmentOperationalVisibility
{
    public function __construct(
        public readonly bool $applies,
        public readonly string $lifecycleHeadline,
        public readonly string $lifecycleDetail,
        public readonly bool $blocked,
        public readonly ?string $blockedReason,
        public readonly bool $partialSuccess,
        public readonly ?string $partialSuccessNote,
        public readonly string $deskShipmentStatus,
        public readonly string $shiprocketMerchantReference,
        public readonly bool $merchantReferenceIsLegacyHw,
        public readonly ?string $canonicalMerchantReference,
        public readonly ?string $externalShipmentId,
        public readonly ?string $externalOrderId,
        public readonly ?string $awb,
        public readonly ?string $courier,
        public readonly string $shiprocketTrackStatus,
        public readonly string $shiprocketTrackNormalized,
        public readonly string $courierMovementLabel,
        public readonly string $providerStatusCode,
        public readonly ?string $sanitizedProviderRejection,
        public readonly string $lastProviderTrackAt,
        public readonly string $trackFreshness,
        public readonly string $lastProviderApiAt,
        public readonly string $labelAvailability,
        public readonly string $deskPickupEvidence,
        public readonly string $pickupEvidenceNote,
        public readonly array $apiAuditRows,
    ) {}

    public function showPanel(): bool
    {
        return $this->applies;
    }
}
