<?php

namespace App\Console\Commands;

use App\Enums\CashfreeHistoricalRecoveryDisposition;
use App\Services\Cashfree\CashfreeMissingOrderAutoRecoveryService;
use App\Services\Cashfree\CashfreePaymentIntegrityService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;

#[Signature('cashfree:auto-recover-missing
    {--dry-run : List recoverable missing paid orders without replaying webhooks}
    {--limit= : Max recoverable payments to process this run}')]
#[Description('Automatically recover Cashfree SUCCESS payments that are missing Desk orders')]
class AutoRecoverMissingCashfreeOrdersCommand extends Command
{
    public function __construct(
        private readonly CashfreeMissingOrderAutoRecoveryService $autoRecoveryService,
        private readonly CashfreePaymentIntegrityService $integrityService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! (bool) config('cashfree.auto_recover.enabled', true) && ! $this->option('dry-run')) {
            $this->warn('Cashfree auto-recovery is disabled (cashfree.auto_recover.enabled=false).');

            return self::SUCCESS;
        }

        $limitOption = $this->option('limit');
        $limit = is_numeric($limitOption) ? max(1, (int) $limitOption) : null;

        if ($this->option('dry-run')) {
            $report = $this->integrityService->reconcile();
            $recoverable = collect($report->missingOrders)
                ->filter(fn ($record): bool => $record->recoveryEligibility === CashfreeHistoricalRecoveryDisposition::Recoverable)
                ->take($limit ?? max(1, (int) config('cashfree.auto_recover.max_per_run', 20)));

            $this->info('Dry run — no webhook logs will be replayed.');
            $this->line('Recoverable missing paid orders: '.$recoverable->count());

            foreach ($recoverable as $missing) {
                $this->line(sprintf(
                    '- log #%d | order_id=%s | cf_payment_id=%s | paid_at=%s',
                    $missing->webhookLogId,
                    $missing->orderId ?? 'unknown',
                    $missing->cfPaymentId,
                    $missing->paidAt?->toDateTimeString() ?? 'unknown',
                ));
            }

            return self::SUCCESS;
        }

        $result = $this->autoRecoveryService->run($limit);

        $this->line('Found: '.$result->found);
        $this->line('Recovered: '.$result->recovered);
        $this->line('Still failed: '.$result->stillFailed);

        return $result->stillFailed > 0 ? self::FAILURE : self::SUCCESS;
    }
}
