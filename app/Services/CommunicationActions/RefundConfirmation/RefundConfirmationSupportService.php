<?php

namespace App\Services\CommunicationActions\RefundConfirmation;

use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\RefundRequest;

final class RefundConfirmationSupportService
{
    public function resolveApprovedRefund(Incident $incident): ?RefundRequest
    {
        $incident->loadMissing(['order', 'refundRequests']);

        $eligibleStatuses = array_map(
            fn (RefundStatus $status): string => $status->value,
            array_filter(
                RefundStatus::cases(),
                fn (RefundStatus $status): bool => $status->allowsCustomerConfirmation(),
            ),
        );

        $incidentRefund = $incident->refundRequests()
            ->whereIn('status', $eligibleStatuses)
            ->orderByDesc('executed_at')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->first();

        if ($incidentRefund instanceof RefundRequest) {
            return $incidentRefund;
        }

        $order = $incident->order;

        if ($order === null) {
            return null;
        }

        return $order->refundRequests()
            ->whereIn('status', $eligibleStatuses)
            ->orderByDesc('executed_at')
            ->orderByDesc('reviewed_at')
            ->orderByDesc('id')
            ->first();
    }

    public function formatRefundAmount(RefundRequest $refund): string
    {
        return number_format($refund->displayAmount(), 2);
    }

    public function companyName(): string
    {
        return trim((string) config('communication_actions.company_name', 'Radium Box'));
    }

    public function supportContact(): string
    {
        return trim((string) config('communication_actions.support_contact', 'support@radiumbox.com'));
    }
}
