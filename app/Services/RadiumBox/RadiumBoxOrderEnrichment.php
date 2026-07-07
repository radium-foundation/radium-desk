<?php

namespace App\Services\RadiumBox;

readonly class RadiumBoxOrderEnrichment
{
    /**
     * @param  array<int, mixed>|null  $serviceHistory
     * @param  array<string, mixed>|null  $amcDetails
     */
    public function __construct(
        public ?string $serialNumber = null,
        public ?string $deviceModel = null,
        public ?string $activationYear = null,
        public ?string $warranty = null,
        public ?string $amc = null,
        public ?string $radiumboxPaymentStatus = null,
        public ?string $radiumboxOrderStatus = null,
        public ?string $customerName = null,
        public ?string $customerPhone = null,
        public ?string $customerEmail = null,
        public ?string $gstNumber = null,
        public ?string $invoiceNumber = null,
        public ?string $purchaseYear = null,
        public ?array $serviceHistory = null,
        public ?string $amcStatus = null,
        public ?string $amcYear = null,
        public ?array $amcDetails = null,
        public ?string $legacyOrderStatus = null,
    ) {}

    public function hasData(): bool
    {
        return filled($this->serialNumber)
            || filled($this->deviceModel)
            || filled($this->activationYear)
            || filled($this->warranty)
            || filled($this->amc);
    }

    public function hasLegacyPreviewData(): bool
    {
        return $this->hasData()
            || filled($this->customerName)
            || filled($this->customerPhone)
            || filled($this->customerEmail)
            || filled($this->gstNumber)
            || filled($this->invoiceNumber)
            || filled($this->purchaseYear)
            || filled($this->serviceHistory)
            || filled($this->amcStatus)
            || filled($this->amcYear)
            || filled($this->amcDetails)
            || filled($this->legacyOrderStatus)
            || filled($this->radiumboxOrderStatus);
    }

    /**
     * @return array<string, string>
     */
    public function supplementalMetadata(): array
    {
        $metadata = array_filter([
            'activation_year' => $this->activationYear,
            'warranty' => $this->warranty,
            'amc' => $this->amc,
            'radiumbox_payment_status' => $this->radiumboxPaymentStatus,
            'radiumbox_order_status' => $this->radiumboxOrderStatus,
        ], fn (?string $value): bool => filled($value));

        if ($this->hasPendingRadiumBoxPaymentStatus()) {
            $metadata['radiumbox_payment_status_ignored'] = 'true';
        }

        return $metadata;
    }

    public function hasPendingRadiumBoxPaymentStatus(): bool
    {
        foreach ([$this->radiumboxPaymentStatus, $this->radiumboxOrderStatus] as $status) {
            if ($status === null) {
                continue;
            }

            $normalized = strtolower(trim($status));

            if (in_array($normalized, ['pending', 'unpaid', 'payment_pending', 'awaiting_payment'], true)) {
                return true;
            }
        }

        return false;
    }
}
