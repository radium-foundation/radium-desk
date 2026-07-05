<?php

namespace App\Console\Commands;

use App\Models\CashfreeWebhookLog;
use App\Services\Cashfree\CashfreeHistoricalRecoveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('cashfree:recover-historical
    {--dry-run : Report recoverable payments without replaying webhooks}
    {--log= : Assess or recover a single failed webhook log by ID}')]
#[Description('Recover historical Cashfree PAYMENT_SUCCESS webhooks that failed due to resolved bugs')]
class RecoverHistoricalCashfreeWebhooksCommand extends Command
{
    public function __construct(
        private readonly CashfreeHistoricalRecoveryService $recoveryService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $singleLogId = $this->option('log');
        $logId = is_numeric($singleLogId) ? (int) $singleLogId : null;

        if ($singleLogId !== null && $singleLogId !== '' && $logId === null) {
            $this->error(sprintf('Webhook log #%s is not a valid ID.', $singleLogId));

            return self::FAILURE;
        }

        if ($logId !== null && CashfreeWebhookLog::query()->find($logId) === null) {
            $this->error(sprintf('Webhook log #%d was not found.', $logId));

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry run — no webhook logs will be replayed.');
        }

        $result = $this->recoveryService->recover($dryRun, $logId);

        $this->newLine();
        $this->line('Found: '.$result->found);
        $this->line('Recoverable: '.$result->recoverable);
        $this->line('Already exists: '.$result->alreadyExists);
        $this->line('Unsafe: '.$result->unsafe);

        if (! $dryRun) {
            $this->newLine();
            $this->line('Recovered: '.$result->recovered);
            $this->line('Still failed: '.$result->stillFailed);
        }

        Log::info('Cashfree historical recovery command completed.', [
            'dry_run' => $dryRun,
            'log_id' => $logId,
            'found' => $result->found,
            'recoverable' => $result->recoverable,
            'already_exists' => $result->alreadyExists,
            'unsafe' => $result->unsafe,
            'recovered' => $result->recovered,
            'still_failed' => $result->stillFailed,
        ]);

        return self::SUCCESS;
    }
}
