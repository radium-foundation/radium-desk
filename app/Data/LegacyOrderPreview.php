<?php

namespace App\Data;

use App\Services\RadiumBox\RadiumBoxOrderEnrichment;
use App\Support\AppDateFormatter;
use App\Support\LegacyOrderDisplay;
use Illuminate\Support\Carbon;

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
        public readonly ?Carbon $legacyOrderDate = null,
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
            legacyOrderDate: $enrichment->legacyOrderDate,
        );
    }

    public function isCompleteForOneClick(?string $intakePhone = null): bool
    {
        return $this->missingFieldsForOneClick($intakePhone) === [];
    }

    /**
     * @return list<string>
     */
    public function missingFieldsForOneClick(?string $intakePhone = null): array
    {
        $missing = [];

        if (! filled($this->customerName)) {
            $missing[] = 'customer_name';
        }

        $phone = filled($this->mobile) ? $this->mobile : $intakePhone;

        if (! filled($phone)) {
            $missing[] = 'mobile';
        }

        if (! filled($this->productModel)) {
            $missing[] = 'product_model';
        }

        if (! filled($this->serialNumber)) {
            $missing[] = 'serial_number';
        }

        return $missing;
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
            'amc_details_display' => LegacyOrderDisplay::formatAmcDetails($this->amcDetails),
            'legacy_order_status' => $this->legacyOrderStatus,
            'legacy_order_date' => AppDateFormatter::datetime($this->legacyOrderDate),
        ];
    }
}
