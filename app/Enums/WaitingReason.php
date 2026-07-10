<?php

namespace App\Enums;

enum WaitingReason: string
{
    case SerialNumber = 'serial_number';
    case Payment = 'payment';
    case Invoice = 'invoice';
    case CustomerApproval = 'customer_approval';
    case Photos = 'photos';
    case DevicePickup = 'device_pickup';
    case Other = 'other';
    case CustomerNotResponding = 'customer_not_responding';

    public function label(): string
    {
        return config("waiting_states.reasons.{$this->value}.label", str($this->value)->headline()->toString());
    }

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
