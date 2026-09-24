<?php

namespace App\Services\StatutoryInvoice\Data;

use App\Enums\StatutoryInvoiceRefundReviewStatus;
use App\Models\User;

final class StatutoryInvoiceRefundReviewSnapshot
{
    public function __construct(
        public readonly StatutoryInvoiceRefundReviewStatus $status,
        public readonly string $message,
        public readonly bool $posBoundary,
        public readonly ?int $linkedOrderId = null,
        public readonly ?string $linkedOrderPublicId = null,
        public readonly ?string $paymentMethod = null,
        public readonly float $totalPaidAmount = 0.0,
        public readonly float $alreadyRefundedAmount = 0.0,
        public readonly float $maximumRefundable = 0.0,
        public readonly ?int $activeRefundRequestId = null,
        public readonly ?string $activeRefundReference = null,
    ) {}

    public function allowsExplicitRefundRequest(?User $user): bool
    {
        if ($user === null || ! $this->status->isActionableForExplicitRefundRequest()) {
            return false;
        }

        return $user->can('refunds.create');
    }

    /**
     * @return array<string, mixed>
     */
    public function toAuditArray(): array
    {
        return [
            'status' => $this->status->value,
            'label' => $this->status->label(),
            'message' => $this->message,
            'pos_boundary' => $this->posBoundary,
            'linked_order_id' => $this->linkedOrderId,
            'linked_order_public_id' => $this->linkedOrderPublicId,
            'payment_method' => $this->paymentMethod,
            'total_paid_amount' => $this->totalPaidAmount,
            'already_refunded_amount' => $this->alreadyRefundedAmount,
            'maximum_refundable' => $this->maximumRefundable,
            'active_refund_request_id' => $this->activeRefundRequestId,
            'active_refund_reference' => $this->activeRefundReference,
        ];
    }
}
