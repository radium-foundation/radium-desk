<?php

namespace App\Data\Operations;

readonly class CashfreeDeviceEnrichmentQualitySummary
{
    public function __construct(
        public int $paidOrdersMissingDeviceInfo,
        public int $recoveredFromRadiumBox,
        public int $needCustomerContact,
    ) {}

    /**
     * @return array<string, int>
     */
    public function toArray(): array
    {
        return [
            'paid_orders_missing_device_info' => $this->paidOrdersMissingDeviceInfo,
            'recovered_from_radiumbox' => $this->recoveredFromRadiumBox,
            'need_customer_contact' => $this->needCustomerContact,
        ];
    }
}
