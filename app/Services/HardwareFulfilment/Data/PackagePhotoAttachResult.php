<?php

namespace App\Services\HardwareFulfilment\Data;

use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use App\Models\HardwareFulfilmentPackageEvidence;

final class PackagePhotoAttachResult
{
    public function __construct(
        public readonly HardwareFulfilmentPackageEvidence $evidence,
        public readonly PackagePhotoOptimizationResult $optimized,
    ) {}

    public function statusMessage(HardwareFulfilmentPackageEvidenceKind $kind): string
    {
        if ($kind === HardwareFulfilmentPackageEvidenceKind::PackageBeforeLabel) {
            if ($this->optimized->wasOptimized) {
                return 'Package photo uploaded. Optimized to '.$this->optimized->sizeKb().' KB.';
            }

            return 'Package photo uploaded.';
        }

        if ($this->optimized->wasOptimized) {
            return $kind->label().' uploaded. Optimized to '.$this->optimized->sizeKb().' KB.';
        }

        return $kind->label().' recorded.';
    }
}
