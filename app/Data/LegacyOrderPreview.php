<?php

namespace App\Data;

use App\Services\RadiumBox\RadiumBoxOrderEnrichment;

class LegacyOrderPreview
{
    /**
     * @param  array<int, mixed>|null  $serviceHistory
     * @param  array<string, mixed>|null  $amcDetails
     */
    public function __construct(
        public readonly string $orderId,
        public readonly ?string $customerName = null,
        public readonly ?string $mobile = null,
        public readonly ?string $email = null,
        public readonly ?string $productModel = null,
        public readonly ?string $serialNumber = null,
        public readonly ?string $gstNumber = null,
        public readonly ?string $invoiceNumber = null,
        public readonly ?string $purchaseYear = null,
        public readonly ?array $serviceHistory = null,
        public readonly ?string $amcStatus = null,
        public readonly ?string $amcYear = null,
        public readonly ?array $amcDetails = null,
        public readonly ?string $legacyOrderStatus = null,
    ) {}

    public static function fromEnrichment(string $orderId, RadiumBoxOrderEnrichment $enrichment): self
    {
        return new self(
            orderId: $orderId,
            customerName: $enrichment->customerName,
            mobile: $enrichment->customerPhone,
            email: $enrichment->customerEmail,
            productModel: $enrichment->deviceModel,
            serialNumber: $enrichment->serialNumber,
            gstNumber: $enrichment->gstNumber,
            invoiceNumber: $enrichment->invoiceNumber,
            purchaseYear: $enrichment->purchaseYear ?? $enrichment->activationYear,
            serviceHistory: $enrichment->serviceHistory,
            amcStatus: $enrichment->amcStatus ?? $enrichment->amc,
            amcYear: $enrichment->amcYear,
            amcDetails: $enrichment->amcDetails,
            legacyOrderStatus: $enrichment->legacyOrderStatus ?? $enrichment->radiumboxOrderStatus,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'order_id' => $this->orderId,
            'customer_name' => $this->customerName,
            'mobile' => $this->mobile,
            'email' => $this->email,
            'product_model' => $this->productModel,
            'serial_number' => $this->serialNumber,
            'gst_number' => $this->gstNumber,
            'invoice_number' => $this->invoiceNumber,
            'purchase_year' => $this->purchaseYear,
            'service_history' => $this->serviceHistory,
            'amc_status' => $this->amcStatus,
            'amc_year' => $this->amcYear,
            'amc_details' => $this->amcDetails,
            'legacy_order_status' => $this->legacyOrderStatus,
        ];
    }
}
