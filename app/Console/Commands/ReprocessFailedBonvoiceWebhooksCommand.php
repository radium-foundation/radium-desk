<?php

namespace App\Console\Commands;

use App\Models\BonvoiceCallEvent;
use App\Models\BonvoiceWebhookLog;
use App\Services\Bonvoice\BonvoiceWebhookPayloadParser;
use App\Services\Bonvoice\BonvoiceWebhookProcessOptions;
use App\Services\Bonvoice\BonvoiceWebhookProcessorService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Throwable;

#[Signature('bonvoice:reprocess-failed
    {--dry-run : Report what would be processed without making changes}
    {--log= : Replay a single webhook log by ID}
    {--from= : Only include logs received at or after this datetime}
    {--to= : Only include logs received at or before this datetime}
    {--status=failed : Filter by processing_status (failed, received, all)}
    {--error= : Filter processing_error by substring}
    {--limit= : Maximum number of logs to process}
    {--with-notifications : Send live assist notifications during replay}
    {--with-recovery : Run missed call recovery during replay}')]
#[Description('Reprocess BonVoice webhook logs for recovery')]
class ReprocessFailedBonvoiceWebhooksCommand extends Command
{
    public function __construct(
        private readonly BonvoiceWebhookProcessorService $webhookProcessorService,
        private readonly BonvoiceWebhookPayloadParser $payloadParser,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $startedAt = microtime(true);
        $dryRun = (bool) $this->option('dry-run');
        $singleLogId = $this->option('log');
        $limit = $this->resolveLimit();
        $options = new BonvoiceWebhookProcessOptions(
            suppressNotifications: ! (bool) $this->option('with-notifications'),
            suppressRecovery: ! (bool) $this->option('with-recovery'),
        );

        if ($singleLogId !== null && $singleLogId !== '') {
            $webhookLog = BonvoiceWebhookLog::query()->find((int) $singleLogId);

            if ($webhookLog === null) {
                $this->error(sprintf('Webhook log #%s was not found.', $singleLogId));

                return self::FAILURE;
            }

            $logs = collect([$webhookLog]);
        } else {
            $logs = $this->findLogs($limit);
        }

        if ($logs->isEmpty()) {
            $this->info('No BonVoice webhook logs matched for reprocessing.');
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
                    'skipped (already processed)' => $skipped++,
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
            if ($this->alreadyProcessedForCall($log)) {
                $skipped++;

                continue;
            }

            try {
                $result = $this->webhookProcessorService->process($log->fresh(), $options);

                if ($result->processing_status === BonvoiceWebhookProcessorService::STATUS_PROCESSED) {
                    $recovered++;

                    continue;
                }

                $stillFailed++;
            } catch (Throwable) {
                $stillFailed++;
            }
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
     * @return Collection<int, BonvoiceWebhookLog>
     */
    private function findLogs(?int $limit): Collection
    {
        $query = $this->buildQuery();

        $logs = $query->get();

        return $this->sortByStartTime($logs)
            ->when($limit !== null, fn (Collection $collection) => $collection->take($limit))
            ->values();
    }

    /**
     * @return Builder<BonvoiceWebhookLog>
     */
    private function buildQuery(): Builder
    {
        $query = BonvoiceWebhookLog::query();
        $status = strtolower((string) $this->option('status'));

        if ($status !== 'all') {
            $query->where('processing_status', $status);
        }

        $errorFilter = $this->option('error');

        if (is_string($errorFilter) && $errorFilter !== '') {
            $query->where('processing_error', 'like', '%'.$errorFilter.'%');
        }

        $from = $this->option('from');

        if (is_string($from) && $from !== '') {
            $query->where('received_at', '>=', Carbon::parse($from));
        }

        $to = $this->option('to');

        if (is_string($to) && $to !== '') {
            $query->where('received_at', '<=', Carbon::parse($to));
        }

        return $query;
    }

    /**
     * @param  Collection<int, BonvoiceWebhookLog>  $logs
     * @return Collection<int, BonvoiceWebhookLog>
     */
    private function sortByStartTime(Collection $logs): Collection
    {
        return $logs->sortBy([
            fn (BonvoiceWebhookLog $log) => $this->resolveStartTimeSortKey($log),
            fn (BonvoiceWebhookLog $log) => $log->id,
        ])->values();
    }

    private function resolveStartTimeSortKey(BonvoiceWebhookLog $log): string
    {
        $payload = $log->payload ?? [];
        $startedAt = $this->payloadParser->startedAt($payload);

        if ($startedAt !== null) {
            return $startedAt->toDateTimeString();
        }

        return '9999-12-31 23:59:59';
    }

    private function alreadyProcessedForCall(BonvoiceWebhookLog $log): bool
    {
        if ($log->processing_status === BonvoiceWebhookLog::STATUS_PROCESSED) {
            return true;
        }

        $payload = $log->payload ?? [];
        $callId = $this->payloadParser->callId($payload);

        if (! filled($callId)) {
            return false;
        }

        $leg = $this->payloadParser->leg($payload);

        return BonvoiceCallEvent::query()
            ->where('call_id', $callId)
            ->where('leg', $leg)
            ->where('webhook_log_id', '!=', $log->id)
            ->exists();
    }

    private function predictOutcome(BonvoiceWebhookLog $log): string
    {
        if ($this->alreadyProcessedForCall($log)) {
            return 'skipped (already processed)';
        }

        $payload = $log->payload ?? [];

        if (! $this->payloadParser->hasRequiredIdentifiers($payload)) {
            return 'would still fail (missing callID)';
        }

        return 'would recover';
    }

    private function resolveLimit(): ?int
    {
        $limit = $this->option('limit');

        if ($limit === null || $limit === '') {
            return null;
        }

        $parsed = (int) $limit;

        return $parsed > 0 ? $parsed : null;
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
        $this->line('Total logs found: '.$total);
        $this->line(($dryRun ? 'Would recover: ' : 'Successfully recovered: ').$recovered);
        $this->line('Skipped (already processed): '.$skipped);
        $this->line('Still failed: '.$stillFailed);
        $this->line(sprintf('Execution time: %.2fs', $elapsedSeconds));
    }
}
