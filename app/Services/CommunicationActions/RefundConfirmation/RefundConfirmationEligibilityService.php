<?php

namespace App\Services\CommunicationActions\RefundConfirmation;

use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;

class RefundConfirmationEligibilityService
{
    public function __construct(
        private readonly RefundConfirmationSupportService $supportService,
    ) {}

    public function ineligibilityReason(Incident $incident): ?string
    {
        $incident->loadMissing('order');
        $order = $incident->order;

        if ($order === null) {
            return 'Link an order before sending a refund confirmation.';
        }

        if (! $this->hasCustomerContact($order)) {
            return 'Customer contact details are required before sending a refund confirmation.';
        }

        $refund = $this->supportService->resolveApprovedRefund($incident);

        if ($refund === null) {
            return 'Refund confirmation can be sent only after a refund has been approved for this case.';
        }

        if (! $this->hasRefundAmount($refund)) {
            return 'A refund amount is required before sending a refund confirmation.';
        }

        return null;
    }

    private function hasCustomerContact(Order $order): bool
    {
        $phone = trim((string) ($order->customer_phone ?? ''));
        $email = trim((string) ($order->customer_email ?? ''));

        if ($phone !== '') {
            return true;
        }

        return $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) !== false;
    }

    private function hasRefundAmount(RefundRequest $refund): bool
    {
        return (float) $refund->amount > 0;
    }
}
