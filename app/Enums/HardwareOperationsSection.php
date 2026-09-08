<?php

namespace App\Enums;

enum HardwareOperationsSection: string
{
    case NeedsFulfilment = 'needs_fulfilment';
    case InProgress = 'in_progress';
    case ReadyForPickup = 'ready_for_pickup';
    case Exceptions = 'exceptions';

    public function label(): string
    {
        return match ($this) {
            self::NeedsFulfilment => 'Needs Fulfilment',
            self::InProgress => 'In Progress',
            self::ReadyForPickup => 'Ready for Pickup',
            self::Exceptions => 'Exceptions',
        };
    }

    public static function fromStage(
        HardwareFulfilmentOperationalStage $stage,
        bool $hasProviderError = false,
    ): self {
        if ($hasProviderError || $stage === HardwareFulfilmentOperationalStage::BlockedReview) {
            return self::Exceptions;
        }

        return match ($stage) {
            HardwareFulfilmentOperationalStage::AwaitingFulfilment => self::NeedsFulfilment,
            HardwareFulfilmentOperationalStage::ReadyForPickup,
            HardwareFulfilmentOperationalStage::Completed => self::ReadyForPickup,
            default => self::InProgress,
        };
    }
}
