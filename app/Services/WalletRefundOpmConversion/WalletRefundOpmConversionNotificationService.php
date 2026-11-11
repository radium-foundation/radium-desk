<?php

namespace App\Services\WalletRefundOpmConversion;

use App\Models\WalletRefundOpmConversion;
use App\Services\RefundNotificationService;
use Illuminate\Support\Facades\Log;

/**
 * Staff-only notifications tied to persisted conversion state (no customer OPM-paid messaging).
 */
final class WalletRefundOpmConversionNotificationService
{
    public function notifyApproversOfSubmission(WalletRefundOpmConversion $conversion): void
    {
        $conversion->loadMissing('refundRequest.requester');
        $refund = $conversion->refundRequest;
        if ($refund === null) {
            return;
        }

        try {
            app(RefundNotificationService::class)->notifyApproversOfSubmission($refund);
        } catch (\Throwable $exception) {
            Log::warning('[Wallet OPM conversion] Approver notification failed.', [
                'conversion_id' => $conversion->public_id,
                'message' => $exception->getMessage(),
            ]);
        }
    }

    public function notifyRequesterOfDecision(WalletRefundOpmConversion $conversion, string $trigger): void
    {
        $conversion->loadMissing('refundRequest.requester');
        $refund = $conversion->refundRequest;
        if ($refund === null) {
            return;
        }

        try {
            app(RefundNotificationService::class)->notifyRequesterOfDecision($refund, $trigger);
        } catch (\Throwable $exception) {
            Log::warning('[Wallet OPM conversion] Requester notification failed.', [
                'conversion_id' => $conversion->public_id,
                'trigger' => $trigger,
                'message' => $exception->getMessage(),
            ]);
        }
    }

}
