<?php

namespace App\Enums;

enum HardwareDashboardQueue: string
{
    case Ready = 'ready';
    case Exceptions = 'exceptions';
    case Pickup = 'pickup';
    case Completed = 'completed';

    public function label(): string
    {
        return match ($this) {
            self::Ready => 'Ready',
            self::Exceptions => 'Exceptions',
            self::Pickup => 'Pickup',
            self::Completed => 'Completed',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Ready => 'info',
            self::Exceptions => 'danger',
            self::Pickup => 'warning',
            self::Completed => 'primary',
        };
    }

    public static function fromStage(
        HardwareFulfilmentOperationalStage $stage,
        bool $packagePhotoRecorded,
        bool $providerError = false,
    ): self {
        if ($providerError || $stage === HardwareFulfilmentOperationalStage::BlockedReview) {
            return self::Exceptions;
        }

        if ($stage === HardwareFulfilmentOperationalStage::Completed) {
            return $packagePhotoRecorded ? self::Completed : self::Ready;
        }

        if (in_array($stage, [
            HardwareFulfilmentOperationalStage::PickupManifestPending,
            HardwareFulfilmentOperationalStage::ReadyForPickup,
        ], true)) {
            return self::Pickup;
        }

        return self::Ready;
    }
}
