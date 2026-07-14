<?php

namespace App\Data\Refunds;

final readonly class RefundCalculationResult
{
    public function __construct(
        public float $totalPaidAmount,
        public float $alreadyRefundedAmount,
        public float $maximumRefundable,
        public float $cancellationCharges,
        public float $gstOnCancellation,
        public float $otherDeduction,
        public float $totalDeduction,
        public float $refundAmount,
        public ?string $deductionProfileKey = null,
        public ?string $partialDifferenceReason = null,
        public ?string $partialDifferenceNotes = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'total_paid_amount' => $this->round($this->totalPaidAmount),
            'already_refunded_amount' => $this->round($this->alreadyRefundedAmount),
            'maximum_refundable' => $this->round($this->maximumRefundable),
            'cancellation_charges' => $this->round($this->cancellationCharges),
            'gst_on_cancellation' => $this->round($this->gstOnCancellation),
            'other_deduction' => $this->round($this->otherDeduction),
            'total_deduction' => $this->round($this->totalDeduction),
            'refund_amount' => $this->round($this->refundAmount),
            'deduction_profile_key' => $this->deductionProfileKey,
            'partial_difference_reason' => $this->partialDifferenceReason,
            'partial_difference_notes' => $this->partialDifferenceNotes,
        ];
    }

    private function round(float $value): float
    {
        return round($value, 2);
    }
}
