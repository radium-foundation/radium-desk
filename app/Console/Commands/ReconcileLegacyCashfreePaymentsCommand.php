<?php

namespace App\Console\Commands;

use App\Services\Cashfree\LegacyCashfreePaymentReconciliationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

#[Description('Post-import legacy Cashfree payment reconciliation (dry-run by default)')]
class ReconcileLegacyCashfreePaymentsCommand extends Command
{
    protected $signature = 'cashfree:reconcile-legacy-payments
        {--limit=25 : Maximum reconciliation rows to process this run}
        {--dry-run : Classify and report only (default when --execute omitted)}
        {--execute : Link verified payments (requires CASHFREE_LEGACY_PAYMENT_RECONCILIATION_EXECUTION_ENABLED=true)}
        {--write= : Optional JSON summary path}';

    public function __construct(
        private readonly LegacyCashfreePaymentReconciliationService $reconciliationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->reconciliationService->enabled()) {
            $this->error('Legacy payment reconciliation is disabled (cashfree.legacy_payment_reconciliation.enabled).');

            return self::FAILURE;
        }

        $execute = (bool) $this->option('execute');
        $dryRun = ! $execute;

        if ($execute && ! $this->reconciliationService->executionEnabled()) {
            $this->error('Execution is disabled. Set CASHFREE_LEGACY_PAYMENT_RECONCILIATION_EXECUTION_ENABLED=true to link payments.');

            return self::FAILURE;
        }

        $limit = max(1, min(500, (int) $this->option('limit')));

        $this->info('Legacy Cashfree payment reconciliation (RadiumDesk-P-04-10-198)');
        $this->line($dryRun ? 'Mode: DRY-RUN (no financial mutations).' : 'Mode: EXECUTE (verified payments may link).');
        $this->line('Scope: reconciliation queue rows only (not bulk legacy population scan).');
        $this->newLine();

        $rows = $this->reconciliationService->dueReconciliationsQuery()
            ->limit($limit)
            ->get();

        if ($rows->isEmpty()) {
            $this->line('No due reconciliation rows.');

            return self::SUCCESS;
        }

        $summary = [];

        foreach ($rows as $index => $reconciliation) {
            try {
                $updated = $this->reconciliationService->process($reconciliation, $dryRun);
                $summary[] = [
                    'reconciliation_id' => $updated->id,
                    'desk_order_id' => $updated->order_id,
                    'business_order_id' => $updated->business_order_id,
                    'state' => $updated->state->value,
                    'reason' => $updated->reason,
                    'cf_payment_id' => $updated->cf_payment_id,
                    'finance_journal_id' => $updated->finance_journal_id,
                ];
                $this->line(sprintf(
                    '%s → %s',
                    $updated->business_order_id,
                    $updated->state->value,
                ));
            } catch (Throwable $exception) {
                $this->error($reconciliation->business_order_id.' failed: '.$exception->getMessage());

                return self::FAILURE;
            }

            if ($index < $rows->count() - 1) {
                usleep(1_500_000);
            }
        }

        $writePath = trim((string) $this->option('write'));

        if ($writePath !== '') {
            $absolute = $writePath[0] === '/' ? $writePath : base_path($writePath);
            File::ensureDirectoryExists(dirname($absolute));
            File::put($absolute, json_encode([
                'dry_run' => $dryRun,
                'processed' => count($summary),
                'rows' => $summary,
            ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES)."\n");
            $this->info('Wrote summary: '.$absolute);
        }

        return self::SUCCESS;
    }
}
