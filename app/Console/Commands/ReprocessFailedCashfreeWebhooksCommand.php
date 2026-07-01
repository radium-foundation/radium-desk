<?php

namespace App\Console\Commands;

use App\Models\CashfreeWebhookLog;
use App\Models\Order;
use App\Services\Cashfree\CashfreeWebhookPayloadParser;
use App\Services\Cashfree\CashfreeWebhookProcessorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Collection;

#[Signature('cashfree:reprocess-failed
    {--dry-run : Report what would be processed without making changes}
    {--log= : Replay a single webhook log by ID}')]
#[Description('Reprocess failed Cashfree webhooks caused by resolveAutomationActor errors')]
class ReprocessFailedCashfreeWebhooksCommand extends Command
{
    private const RESOLVE_AUTOMATION_ACTOR_ERROR = 'resolveAutomationActor';

    public function __construct(
        private readonly CashfreeWebhookProcessorService $webhookProcessorService,
        private readonly CashfreeWebhookPayloadParser $payloadParser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = microtime(true);
        $dryRun = (bool) $this->option('dry-run');
        $singleLogId = $this->option('log');

        if ($singleLogId !== null && $singleLogId !== '') {
            $webhookLog = CashfreeWebhookLog::query()->find((int) $singleLogId);

            if ($webhookLog === null) {
                $this->error(sprintf('Webhook log #%s was not found.', $singleLogId));

                return self::FAILURE;
            }

            $logs = collect([$webhookLog]);
        } else {
            $logs = $this->findFailedLogs();
        }

        if ($logs->isEmpty()) {
            $this->info('No failed Cashfree webhook logs matched for reprocessing.');
            $this->displaySummary(
                total: 0,
                recovered: 0,
                skipped: 0,
                stillFailed: 0,
                elapsedSeconds: microtime(true) - $startedAt,
                dryRun: $dryRun,
            );

            return self::SUCCESS;
        }

        if ($dryRun) {
            $this->info(sprintf('Dry run: %d webhook log(s) would be reprocessed.', $logs->count()));

            $skipped = 0;
            $wouldRecover = 0;
            $wouldStillFail = 0;

            foreach ($logs as $log) {
                $outcome = $this->predictOutcome($log);
                $this->line(sprintf('- Log #%d (%s)', $log->id, $outcome));

                match ($outcome) {
                    'skipped (already exists)' => $skipped++,
                    'would recover' => $wouldRecover++,
                    default => $wouldStillFail++,
                };
            }

            $this->displaySummary(
                total: $logs->count(),
                recovered: $wouldRecover,
                skipped: $skipped,
                stillFailed: $wouldStillFail,
                elapsedSeconds: microtime(true) - $startedAt,
                dryRun: true,
            );

            return self::SUCCESS;
        }

        $recovered = 0;
        $skipped = 0;
        $stillFailed = 0;

        foreach ($logs as $log) {
            $alreadyExists = $this->orderAlreadyExistsForLog($log);

            $result = $this->webhookProcessorService->process($log->fresh());

            if ($result->processing_status === CashfreeWebhookProcessorService::STATUS_PROCESSED) {
                if ($alreadyExists) {
                    $skipped++;
                } else {
                    $recovered++;
                }

                continue;
            }

            $stillFailed++;
        }

        $this->displaySummary(
            total: $logs->count(),
            recovered: $recovered,
            skipped: $skipped,
            stillFailed: $stillFailed,
            elapsedSeconds: microtime(true) - $startedAt,
            dryRun: false,
        );

        return self::SUCCESS;
    }

    /**
     * @return Collection<int, CashfreeWebhookLog>
     */
    private function findFailedLogs(): Collection
    {
        return CashfreeWebhookLog::query()
            ->where('processing_status', CashfreeWebhookLog::STATUS_FAILED)
            ->where('processing_error', 'like', '%'.self::RESOLVE_AUTOMATION_ACTOR_ERROR.'%')
            ->orderBy('received_at')
            ->orderBy('id')
            ->get();
    }

    private function orderAlreadyExistsForLog(CashfreeWebhookLog $log): bool
    {
        $cfPaymentId = $this->resolveCfPaymentId($log);

        if ($cfPaymentId === null) {
            return false;
        }

        return Order::query()
            ->where('cashfree_payment_id', $cfPaymentId)
            ->exists();
    }

    private function predictOutcome(CashfreeWebhookLog $log): string
    {
        $payload = $log->request_payload ?? [];

        if (! $this->payloadParser->isSuccessfulPayment($payload)) {
            return 'no successful payment payload';
        }

        if ($this->orderAlreadyExistsForLog($log)) {
            return 'skipped (already exists)';
        }

        $cfPaymentId = $this->resolveCfPaymentId($log);

        if ($cfPaymentId === null) {
            return 'would still fail (missing cf_payment_id)';
        }

        $existingLog = CashfreeWebhookLog::query()
            ->where('cf_payment_id', $cfPaymentId)
            ->where('id', '!=', $log->id)
            ->whereNotNull('incident_id')
            ->where('processing_status', CashfreeWebhookProcessorService::STATUS_PROCESSED)
            ->exists();

        if ($existingLog) {
            return 'skipped (already exists)';
        }

        return 'would recover';
    }

    private function resolveCfPaymentId(CashfreeWebhookLog $log): ?string
    {
        $payload = $log->request_payload ?? [];

        return $this->payloadParser->cfPaymentId($payload) ?? $log->cf_payment_id;
    }

    private function displaySummary(
        int $total,
        int $recovered,
        int $skipped,
        int $stillFailed,
        float $elapsedSeconds,
        bool $dryRun,
    ): void {
        $this->newLine();
        $this->info('Summary');
        $this->line('Total failed logs found: '.$total);
        $this->line(($dryRun ? 'Would recover: ' : 'Successfully recovered: ').$recovered);
        $this->line('Skipped (already exists): '.$skipped);
        $this->line('Still failed: '.$stillFailed);
        $this->line(sprintf('Execution time: %.2fs', $elapsedSeconds));
    }
}
