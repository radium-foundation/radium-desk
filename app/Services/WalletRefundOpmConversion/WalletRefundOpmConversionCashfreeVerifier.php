<?php

namespace App\Services\WalletRefundOpmConversion;

use App\Models\WalletRefundOpmConversion;
use App\Services\Cashfree\CashfreeApiClient;
use App\Services\Cashfree\Exceptions\CashfreeApiException;
use Illuminate\Validation\ValidationException;

final class WalletRefundOpmConversionCashfreeVerifier
{
    public function __construct(
        private readonly CashfreeApiClient $cashfree,
    ) {}

    /**
     * @return array{status: string, verified: bool, ambiguous: bool, message?: string}
     */
    public function verify(WalletRefundOpmConversion $conversion): array
    {
        if (! $this->cashfree->isPgApiConfigured()) {
            return [
                'status' => 'unavailable',
                'verified' => false,
                'ambiguous' => true,
                'message' => 'Cashfree PG API is not configured.',
            ];
        }

        $orderId = trim((string) $conversion->cashfree_order_id);
        $refundId = trim((string) $conversion->cashfree_refund_id);
        if ($orderId === '' || $refundId === '') {
            throw ValidationException::withMessages([
                'cashfree_refund_id' => 'Cashfree order id and refund id are required for verification.',
            ]);
        }

        try {
            $payload = $this->cashfree->getRefund($orderId, $refundId);
        } catch (CashfreeApiException $exception) {
            return [
                'status' => 'lookup_failed',
                'verified' => false,
                'ambiguous' => true,
                'message' => $exception->getMessage(),
            ];
        }

        $status = (string) ($payload['refund_status'] ?? '');
        $amount = round((float) ($payload['refund_amount'] ?? 0), 2);
        $expected = round((float) $conversion->cashfree_refund_amount, 2);

        if ($expected > 0 && abs($amount - $expected) > 0.009) {
            return [
                'status' => 'amount_mismatch',
                'verified' => false,
                'ambiguous' => false,
                'message' => 'Cashfree refund amount does not match the recorded payout amount.',
            ];
        }

        $successful = in_array($status, (array) config('wallet_refund_opm_conversion.cashfree.successful_statuses', []), true);
        if (! $successful) {
            $pending = in_array(strtoupper($status), ['PENDING', 'PROCESSING', ''], true);

            return [
                'status' => $pending ? 'pending' : 'not_successful',
                'verified' => false,
                'ambiguous' => $pending,
                'message' => 'Cashfree refund status is not successful.',
            ];
        }

        return [
            'status' => 'success',
            'verified' => true,
            'ambiguous' => false,
        ];
    }
}
