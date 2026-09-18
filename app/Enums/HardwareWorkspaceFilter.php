<?php

namespace App\Enums;

enum HardwareWorkspaceFilter: string
{
    case NeedsAction = 'needs_action';
    case MappingRequired = 'mapping_required';
    case AwaitingSerial = 'awaiting_serial';
    case AwbPending = 'awb_pending';
    case PackagePhotoPending = 'package_photo_pending';
    case Shipping = 'shipping';
    case OutForPickup = 'out_for_pickup';
    case ReadyForPickup = 'ready_for_pickup';
    case InTransit = 'in_transit';
    case PickedUp = 'picked_up';
    case Completed = 'completed';
    case Delivered = 'delivered';
    case All = 'all';
    case Ready = 'ready';
    case Exceptions = 'exceptions';
    case Pickup = 'pickup';
    case Scheduled = 'scheduled';

    public function label(): string
    {
        return match ($this) {
            self::NeedsAction => 'Needs Action',
            self::MappingRequired => 'Product Mapping Required',
            self::AwaitingSerial => 'Awaiting Serial',
            self::AwbPending => 'AWB Pending',
            self::PackagePhotoPending => 'Package Photo Pending',
            self::Shipping => 'Shipping',
            self::OutForPickup => 'Out for Pickup',
            self::ReadyForPickup => 'Ready for Pickup',
            self::InTransit => 'In Transit',
            self::PickedUp => 'Picked Up',
            self::Completed => 'Completed',
            self::Delivered => 'Delivered',
            self::All => 'All',
            self::Ready => 'Ready',
            self::Exceptions => 'Exceptions',
            self::Pickup => 'Pickup',
            self::Scheduled => 'Scheduled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::NeedsAction, self::MappingRequired, self::AwaitingSerial, self::AwbPending, self::PackagePhotoPending => 'danger',
            self::Shipping, self::OutForPickup, self::ReadyForPickup, self::InTransit, self::PickedUp => 'warning',
            self::Completed, self::Delivered => 'primary',
            self::All => 'secondary',
            self::Ready => 'info',
            self::Exceptions => 'danger',
            self::Pickup => 'warning',
            self::Scheduled => 'secondary',
        };
    }

    /**
     * @return list<self>
     */
    public static function needsActionFilters(): array
    {
        return [
            self::MappingRequired,
            self::AwaitingSerial,
            self::AwbPending,
            self::PackagePhotoPending,
        ];
    }

    /**
     * Verified production shipping states. Out for Delivery is not a mapped
     * ShiprocketTrackNormalized value and is not listed.
     *
     * @return list<self>
     */
    public static function shippingFilters(): array
    {
        return [
            self::OutForPickup,
            self::ReadyForPickup,
            self::InTransit,
            self::PickedUp,
        ];
    }

    public function isNeedsAction(): bool
    {
        return $this === self::NeedsAction || in_array($this, self::needsActionFilters(), true);
    }

    public function isShipping(): bool
    {
        return $this === self::Shipping || in_array($this, self::shippingFilters(), true);
    }

    public function isReadyForPickupWorkspace(): bool
    {
        return $this === self::ReadyForPickup;
    }

    /**
     * Shipping aggregate and in-flight lifecycle queues. Excludes the dedicated
     * Ready for Pickup workspace tab, which uses the same underlying filter.
     */
    public function isShippingTopTab(): bool
    {
        return in_array($this, [
            self::Shipping,
            self::OutForPickup,
            self::InTransit,
            self::PickedUp,
        ], true);
    }

    public function showsShippingLifecycleSubNav(): bool
    {
        return $this->isShipping();
    }
}
