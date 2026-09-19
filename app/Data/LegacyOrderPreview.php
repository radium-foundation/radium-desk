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
     * @param  array<string, mixed>|null  $deliveryAddress
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
        public readonly ?string $paymentStatus = null,
        public readonly ?string $paymentMethod = null,
        public readonly ?string $paymentAmount = null,
        public readonly ?Carbon $invoiceDate = null,
        public readonly ?string $shipmentStatus = null,
        public readonly ?string $awb = null,
        public readonly ?string $productVariant = null,
        public readonly ?string $productSku = null,
        public readonly ?array $deliveryAddress = null,
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
            paymentStatus: $enrichment->radiumboxPaymentStatus,
            paymentMethod: $enrichment->paymentMethod,
            paymentAmount: $enrichment->paymentAmount,
            invoiceDate: $enrichment->invoiceDate,
            shipmentStatus: $enrichment->shipmentStatus ?? $enrichment->legacyOrderStatus ?? $enrichment->radiumboxOrderStatus,
            awb: $enrichment->awb,
            productVariant: $enrichment->productVariant,
            productSku: $enrichment->productSku,
            deliveryAddress: $enrichment->deliveryAddress,
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
            'product_variant' => $this->productVariant,
            'product_sku' => $this->productSku,
            'gst_number' => $this->gstNumber,
            'invoice_number' => $this->invoiceNumber,
            'invoice_date' => AppDateFormatter::datetime($this->invoiceDate),
            'purchase_year' => $this->purchaseYear,
            'service_history' => $this->serviceHistory,
            'amc_status' => $this->amcStatus,
            'amc_year' => $this->amcYear,
            'amc_details' => $this->amcDetails,
            'amc_details_display' => LegacyOrderDisplay::formatAmcDetails($this->amcDetails),
            'legacy_order_status' => $this->legacyOrderStatus,
            'legacy_order_date' => AppDateFormatter::datetime($this->legacyOrderDate),
            'payment_status' => $this->paymentStatus,
            'payment_method' => $this->paymentMethod,
            'payment_amount' => $this->paymentAmount,
            'payment_amount_display' => LegacyOrderDisplay::formatInrAmount($this->paymentAmount),
            'shipment_status' => $this->shipmentStatus,
            'awb' => $this->awb,
            'delivery_address' => $this->deliveryAddress,
            'delivery_address_display' => LegacyOrderDisplay::formatDeliveryAddress($this->deliveryAddress),
        ];
    }
}
