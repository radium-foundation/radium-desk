<?php

namespace App\Data\Operations;

readonly class SupportSlotReminderItem
{
    public function __construct(
        public string $customerName,
        public string $deviceModel,
    ) {}

    /**
     * @return array<string, string>
     */
    public function toArray(): array
    {
        return [
            'customer_name' => $this->customerName,
            'device_model' => $this->deviceModel,
        ];
    }
}
