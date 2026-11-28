<?php

namespace App\Support\PreShipmentCancellation;

use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\PreShipmentCancellationStatus;
use App\Enums\RefundStatus;
use App\Models\PreShipmentCancellationRequest;
use App\Services\PreShipmentCancellation\PreShipmentCancellationRefundAutomationService;

final class PreShipmentCancellationRefundOperatorState
{
    public const CREDITED_TO_WALLET = 'credited_to_wallet';

    public const PENDING_FINANCE = 'pending_finance';

    public const UNLINKED_ACTION_REQUIRED = 'unlinked_action_required';

    /**
     * @return array{key: string, label: string, detail: ?string}
     */
    public static function resolve(PreShipmentCancellationRequest $case): array
    {
        if ($case->sub_status === PreShipmentCancellationRefundAutomationService::SUB_STATUS_LINK_FAILED) {
            return self::unlinked($case->execution_hold_reason);
        }

        if ($case->refund_request_id === null) {
            if ($case->status === PreShipmentCancellationStatus::FinanceApproved) {
                return self::unlinked('Cancellation is approved; refund request is not yet linked.');
            }

            if ($case->sub_status === PreShipmentCancellationRefundAutomationService::SUB_STATUS_EXECUTION_FAILED) {
                return self::unlinked($case->execution_hold_reason);
            }
        }

        $refund = $case->relationLoaded('refundRequest')
            ? $case->refundRequest
            : $case->refundRequest()->first();

        if ($refund === null && $case->refund_request_id !== null) {
            return self::unlinked('Linked refund record could not be loaded.');
        }

        if ($refund !== null
            && in_array($refund->status, [RefundStatus::Completed, RefundStatus::Closed], true)
            && $refund->approved_refund_method === ApprovedRefundMethod::Wallet) {
            $reference = (string) ($refund->reference_no ?? '');
            $hasLedger = $reference !== ''
                && CentralWalletLedgerEntry::query()
                    ->where('business_reference', $reference)
                    ->where('status', 'posted')
                    ->exists();

            if ($hasLedger) {
                return [
                    'key' => self::CREDITED_TO_WALLET,
                    'label' => 'Credited to Wallet',
                    'detail' => 'Canonical desk-first wallet credit is posted for '.$reference.'.',
                ];
            }
        }

        if ($refund !== null) {
            if ($case->sub_status === PreShipmentCancellationRefundAutomationService::SUB_STATUS_EXECUTION_FAILED) {
                return [
                    'key' => self::UNLINKED_ACTION_REQUIRED,
                    'label' => 'Unlinked / Action Required',
                    'detail' => (string) ($case->execution_hold_reason ?? 'Wallet automation did not complete.'),
                ];
            }

            return [
                'key' => self::PENDING_FINANCE,
                'label' => 'Pending Finance',
                'detail' => 'Refund request is linked and awaiting Finance workflow completion.',
            ];
        }

        return self::unlinked(null);
    }

    /**
     * @return array{key: string, label: string, detail: ?string}
     */
    private static function unlinked(?string $detail): array
    {
        return [
            'key' => self::UNLINKED_ACTION_REQUIRED,
            'label' => 'Unlinked / Action Required',
            'detail' => $detail,
        ];
    }
}
