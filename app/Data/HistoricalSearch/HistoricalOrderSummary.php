<?php

namespace App\Data\HistoricalSearch;

readonly class HistoricalOrderSummary
{
    /**
     * @param  list<array{label: string, value: string}>  $provenance
     */
    public function __construct(
        public int $histOrderId,
        public ?string $orderId,
        public ?string $orderDate,
        public ?int $orderYear,
        public string $paymentDisplay,
        public ?string $paymentDate,
        public ?string $orderAmount,
        public ?string $invoiceNumber,
        public ?string $awb,
        public ?string $productModel,
        public ?string $serialNumber,
        public string $tenureDisplay,
        public ?string $tenureEndDate,
        public array $provenance,
        public bool $isHistoricalOnly = true,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'hist_order_id' => $this->histOrderId,
            'order_id' => $this->orderId,
            'order_date' => $this->orderDate,
            'order_year' => $this->orderYear,
            'payment_display' => $this->paymentDisplay,
            'payment_date' => $this->paymentDate,
            'order_amount' => $this->orderAmount,
            'invoice_number' => $this->invoiceNumber,
            'awb' => $this->awb,
            'product_model' => $this->productModel,
            'serial_number' => $this->serialNumber,
            'tenure_display' => $this->tenureDisplay,
            'tenure_end_date' => $this->tenureEndDate,
            'provenance' => $this->provenance,
            'is_historical_only' => $this->isHistoricalOnly,
        ];
    }
}
