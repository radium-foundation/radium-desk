<?php

namespace App\Services\Refunds;

use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\AuditLog;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\AuditLogService;
use App\Support\Refunds\DeskFirstWalletRefundSupport;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

/**
 * Persists Desk-owned evidence when desk-first wallet completion fails after CW credit commits.
 */
final class DeskFirstWalletRefundInterruptedCompletionRecorder
{
    public const AUDIT_EVENT = 'refund.desk_first_spoke_audit_failed';

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function recordIfApplicable(
        RefundRequest $refund,
        User $user,
        Request $request,
        ValidationException $exception,
    ): void {
        if (! $this->applies($refund)) {
            return;
        }

        $credit = $this->committedDeskCredit($refund);
        if ($credit === null) {
            return;
        }

        $messages = $exception->errors();
        $spokeMessage = is_array($messages['refund'] ?? null)
            ? trim((string) (($messages['refund'][0] ?? '') ?: ''))
            : '';
        if ($spokeMessage === '') {
            $spokeMessage = 'Spoke audit failed.';
        }

        $ledgerEntryId = (int) $credit->id;

        DB::transaction(function () use ($refund, $user, $request, $spokeMessage, $ledgerEntryId): void {
            $locked = RefundRequest::query()->lockForUpdate()->find($refund->id);
            if ($locked === null || $locked->status !== RefundStatus::PendingExecution) {
                return;
            }

            $this->auditLogService->log(
                userId: $user->id,
                event: self::AUDIT_EVENT,
                auditable: $locked,
                oldValues: [
                    'status' => $locked->status->value,
                    'reference_no' => $locked->reference_no,
                ],
                newValues: [
                    'status' => $locked->status->value,
                    'reference_no' => $locked->reference_no,
                    'central_wallet_ledger_entry_id' => $ledgerEntryId,
                    'wallet_reference' => 'CW:'.$ledgerEntryId,
                    'spoke_audit_error' => $spokeMessage,
                ],
                request: $request,
            );

            $locked->touch();
        });
    }

    public static function latestSpokeAuditFailureMessage(RefundRequest $refund): ?string
    {
        $log = AuditLog::query()
            ->where('auditable_type', $refund->getMorphClass())
            ->where('auditable_id', $refund->getKey())
            ->where('event', self::AUDIT_EVENT)
            ->orderByDesc('id')
            ->first();

        if ($log === null) {
            return null;
        }

        $newValues = $log->new_values;
        if (! is_array($newValues)) {
            return null;
        }

        $message = $newValues['spoke_audit_error'] ?? null;

        return is_string($message) && trim($message) !== '' ? trim($message) : null;
    }

    private function applies(RefundRequest $refund): bool
    {
        if (! DeskFirstWalletRefundSupport::isDeskFirstEnabled()) {
            return false;
        }

        if ($refund->approved_refund_method !== ApprovedRefundMethod::Wallet) {
            return false;
        }

        if ($refund->status !== RefundStatus::PendingExecution) {
            return false;
        }

        return true;
    }

    private function committedDeskCredit(RefundRequest $refund): ?CentralWalletLedgerEntry
    {
        $reference = trim((string) $refund->reference_no);
        if ($reference === '') {
            return null;
        }

        return CentralWalletLedgerEntry::query()
            ->where('business_reference', $reference)
            ->where('entry_type', LedgerEntryType::Credit)
            ->first();
    }
}
