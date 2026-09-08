<?php

namespace App\Enums;

enum HardwareFulfilmentPackageEvidenceKind: string
{
    case PackageBeforeLabel = 'package_before_label';
    case PackageLabelApplied = 'package_label_applied';

    public function label(): string
    {
        return match ($this) {
            self::PackageBeforeLabel => 'Package photo',
            self::PackageLabelApplied => 'Label-applied package photo',
        };
    }
}
