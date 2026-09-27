<?php

namespace App\Services\Refunds;

use App\Models\RefundRequest;

/**
 * Guards execution completion inputs from conflating Desk refund references
 * with external payout / wallet ledger identifiers.
 */
final class RefundExecutionInputGuard
{
    public static function matchesDeskRefundReference(RefundRequest $refund, ?string $value): bool
    {
        if (! is_string($value)) {
            return false;
        }

        $candidate = strtoupper(trim($value));
        if ($candidate === '') {
            return false;
        }

        return $candidate === strtoupper(trim((string) $refund->reference_no));
    }

    /**
     * @param  array{
     *     execution_reference_no?: string|null,
     *     execution_transaction_id?: string|null,
     * }  $data
     * @return array{
     *     execution_reference_no?: string|null,
     *     execution_transaction_id?: string|null,
     *     execution_remarks?: string|null,
     * }
     */
    public static function sanitizeForCompletion(RefundRequest $refund, array $data): array
    {
        if ($refund->approved_refund_method?->value !== 'wallet') {
            return $data;
        }

        $data['execution_reference_no'] = null;
        $data['execution_transaction_id'] = null;

        return $data;
    }
}
