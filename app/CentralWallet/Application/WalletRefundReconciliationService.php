<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Infrastructure\Persistence\CentralWalletReconciliationItem;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletReconciliationRun;
use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Models\RefundRequest;
use App\Services\Refunds\WalletRefundExistingCreditDetection;
use App\Services\Refunds\WalletRefundExistingCreditDetector;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

final class WalletRefundReconciliationService
{
    public function __construct(
        private readonly WalletRefundExistingCreditDetector $detector,
    ) {}

    public function runDailyDetection(): CentralWalletReconciliationRun
    {
        $startedAt = now();

        $run = CentralWalletReconciliationRun::query()->create([
            'id' => (string) Str::uuid(),
            'scope' => 'wallet_refund_stranded_detection',
            'status' => 'running',
            'started_at' => $startedAt,
        ]);

        $matched = 0;
        $ambiguous = 0;
        $blocking = 0;
        $scanned = 0;

        RefundRequest::query()
            ->with('order')
            ->where('status', RefundStatus::PendingExecution)
            ->where('approved_refund_method', ApprovedRefundMethod::Wallet)
            ->orderBy('id')
            ->chunkById(
                max(1, (int) config('central_wallet.reconciliation.batch_size', 100)),
                function ($refunds) use ($run, &$matched, &$ambiguous, &$blocking, &$scanned): void {
                    foreach ($refunds as $refund) {
                        if (! $refund instanceof RefundRequest) {
                            continue;
                        }

                        if (! $this->detector->supports($refund)) {
                            continue;
                        }

                        $scanned++;
                        $detection = $this->detector->detect($refund);
                        $item = $this->mapDetectionToItem($run->id, $refund, $detection);

                        if ($item === null) {
                            continue;
                        }

                        CentralWalletReconciliationItem::query()->create($item);

                        match ($detection->status()) {
                            WalletRefundExistingCreditDetection::STATUS_MATCHED => $matched++,
                            WalletRefundExistingCreditDetection::STATUS_AMBIGUOUS => $ambiguous++,
                            default => $blocking++,
                        };
                    }
                },
            );

        $summary = [
            'mode' => 'read_only_detection',
            'items_processed' => $scanned,
            'stranded_with_credit' => $matched,
            'ambiguous_credit' => $ambiguous,
            'blocking_mismatch' => $blocking,
            'auto_completed' => 0,
        ];

        $run->update([
            'status' => 'completed',
            'completed_at' => now(),
            'summary' => $summary,
        ]);

        Log::channel((string) config('central_wallet.log_channel', 'stack'))->info(
            'central_wallet.reconciliation.wallet_refund_detection.completed',
            [
                'run_id' => $run->id,
                'summary' => $summary,
            ],
        );

        return $run->fresh() ?? $run;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function mapDetectionToItem(
        string $runId,
        RefundRequest $refund,
        WalletRefundExistingCreditDetection $detection,
    ): ?array {
        if ($detection->isNotFound() || $detection->isUnsupported()) {
            return null;
        }

        $entry = $detection->ledgerEntry();

        $itemType = match ($detection->status()) {
            WalletRefundExistingCreditDetection::STATUS_MATCHED => 'wallet_refund_stranded_with_credit',
            WalletRefundExistingCreditDetection::STATUS_AMBIGUOUS => 'wallet_refund_ambiguous_credit',
            default => 'wallet_refund_credit_mismatch',
        };

        $severity = $detection->isMatched() ? 'info' : 'warning';

        return [
            'run_id' => $runId,
            'item_type' => $itemType,
            'severity' => $severity,
            'details' => [
                'refund_id' => $refund->id,
                'business_reference' => $refund->reference_no,
                'order_id' => $refund->order?->order_id,
                'detection_status' => $detection->status(),
                'admin_message' => $detection->adminMessage(),
                'wallet_ledger_entry_id' => $entry?->id,
                'central_wallet_id' => $entry?->central_wallet_id,
                'wallet_reference' => $detection->walletReference(),
                'amount' => $entry !== null ? (string) $entry->amount : null,
                'currency' => $entry !== null ? (string) $entry->currency : null,
                'reconciliation_reference' => $refund->reference_no,
            ],
        ];
    }
}
