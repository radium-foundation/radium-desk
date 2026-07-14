<?php

namespace App\Data\Refunds;

final readonly class RefundProfileDefinition
{
    public function __construct(
        public string $key,
        public string $label,
        public float $cancellationCharges,
        public ?float $gstOnCancellation,
        public float $otherDeduction,
        public bool $applyGstRate,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'label' => $this->label,
            'cancellation_charges' => $this->cancellationCharges,
            'gst_on_cancellation' => $this->gstOnCancellation,
            'other_deduction' => $this->otherDeduction,
            'apply_gst_rate' => $this->applyGstRate,
        ];
    }
}
