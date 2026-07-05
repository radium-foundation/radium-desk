<?php

namespace App\Console\Commands;

use App\Data\CashfreeMissingPaidOrderRecord;
use App\Enums\CashfreeHistoricalRecoveryDisposition;
use App\Services\Cashfree\CashfreePaymentIntegrityService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cashfree:reconcile')]
#[Description('Compare Cashfree PAYMENT_SUCCESS webhook logs against Desk orders')]
class ReconcileCashfreePaymentsCommand extends Command
{
    public function __construct(
        private readonly CashfreePaymentIntegrityService $integrityService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $report = $this->integrityService->reconcile();

        $this->line('Successful Cashfree payments: '.$report->successfulCashfreePayments);
        $this->line('Desk orders: '.$report->deskOrders);
        $this->line('Missing orders: '.$report->missingOrdersCount);
        $this->line('Failed processing: '.$report->failedProcessing);
        $this->line('Paid without Desk order: '.$report->paidWithoutDeskOrderCount);

        if ($report->missingOrders !== []) {
            $this->newLine();
            $this->info('Missing paid orders');

            foreach ($report->missingOrders as $missing) {
                $this->line(sprintf(
                    '- log #%d | order_id=%s | cf_payment_id=%s | paid_at=%s | recovery=%s (%s)',
                    $missing->webhookLogId,
                    $missing->orderId ?? 'unknown',
                    $missing->cfPaymentId,
                    $missing->paidAt?->toDateTimeString() ?? 'unknown',
                    $missing->recoveryEligibility->value,
                    $missing->recoveryReason,
                ));
            }
        }

        $recoverable = collect($report->missingOrders)
            ->filter(fn (CashfreeMissingPaidOrderRecord $record): bool => $record->recoveryEligibility === CashfreeHistoricalRecoveryDisposition::Recoverable)
            ->count();

        if ($recoverable > 0) {
            $this->newLine();
            $this->comment(sprintf(
                '%d payment(s) are eligible for recovery via cashfree:recover-historical.',
                $recoverable,
            ));
        }

        return self::SUCCESS;
    }
}
