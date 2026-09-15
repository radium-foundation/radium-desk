<?php

namespace App\Services\StatutoryInvoice\Data;

/**
 * Resolved place of supply for a statutory invoice transaction.
 */
final class PlaceOfSupplyResolution
{
    public const CONFIDENCE_VERIFIED = 'verified';

    public const CONFIDENCE_REVIEW = 'review';

    public const CLASSIFICATION_VALID = 'valid';

    public const CLASSIFICATION_REVIEW = 'review';

    public const CLASSIFICATION_BLOCKED = 'blocked';

    public function __construct(
        public readonly ?string $state,
        public readonly ?string $stateCode,
        public readonly ?string $source,
        public readonly string $confidence,
        public readonly string $gstinPosClassification,
    ) {}

    public function isResolvable(): bool
    {
        return $this->state !== null
            && $this->stateCode !== null
            && $this->gstinPosClassification !== self::CLASSIFICATION_BLOCKED;
    }
}
