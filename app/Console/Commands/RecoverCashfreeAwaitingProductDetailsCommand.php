<?php

namespace App\Console\Commands;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAutomationMonitorService;
use App\Services\ServiceCaseStatusService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('cashfree:recover-awaiting-product-details
    {--dry-run : Preview recovery without writing (default)}
    {--execute : Promote eligible incidents to Open and re-run Ready Queue eligibility}
    {--limit= : Maximum number of incidents to process}
    {--chunk=50 : Number of incidents to load per chunk}')]
#[Description('One-time recovery: promote historical Cashfree AwaitingProductDetails incidents to Open when identity validation passes (idempotent)')]
class RecoverCashfreeAwaitingProductDetailsCommand extends Command
{
    private int $scanned = 0;

    private int $eligible = 0;

    private int $promoted = 0;

    private int $skipped = 0;

    private int $alreadyOpen = 0;

    private int $failures = 0;

    public function __construct(
        private readonly ServiceCaseAssignmentEligibilityService $eligibilityService,
        private readonly ServiceCaseStatusService $statusService,
        private readonly ServiceCaseAutomationMonitorService $automationMonitor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $this->resetCounters();

        $dryRun = ! (bool) $this->option('execute');
        $limit = $this->resolveLimit();
        $chunkSize = max(1, (int) $this->option('chunk'));

        if ($dryRun) {
            $this->info('Dry run — no changes will be written. Pass --execute to apply recovery.');
        } else {
            $this->warn('Execute mode — eligible Cashfree incidents will be promoted to Open.');
        }

        $this->info(sprintf(
            'Scanning Cashfree AwaitingProductDetails incidents in chunks of %d%s.',
            $chunkSize,
            $limit !== null ? " (limit {$limit})" : '',
        ));

        $actor = $this->automationMonitor->resolveAutomationActor();
        $handled = 0;

        $this->candidateQuery()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($incidents) use ($dryRun, $limit, $actor, &$handled): bool {
                foreach ($incidents as $incident) {
                    if ($limit !== null && $handled >= $limit) {
                        return false;
                    }

                    $this->processIncident($incident, $actor, $dryRun);
                    $handled++;
                }

                $this->renderProgress($dryRun);

                return $limit === null || $handled < $limit;
            });

        $this->renderSummary($dryRun);

        return self::SUCCESS;
    }

    private function processIncident(Incident $incident, User $actor, bool $dryRun): void
    {
        $this->scanned++;

        $incident = Incident::query()
            ->with(['order'])
            ->find($incident->id);

        if ($incident === null) {
            $this->skipped++;
            $this->logSkipped(null, null, 'incident_not_found');

            return;
        }

        if ($incident->status === IncidentStatus::Open) {
            $this->alreadyOpen++;
            $this->logSkipped($incident, $incident->order, 'already_open');

            return;
        }

        if ($incident->status === IncidentStatus::Closed) {
            $this->skipped++;
            $this->logSkipped($incident, $incident->order, 'closed');

            return;
        }

        if ($incident->status !== IncidentStatus::AwaitingProductDetails) {
            $this->skipped++;
            $this->logSkipped($incident, $incident->order, 'status_not_awaiting_product_details');

            return;
        }

        $order = $incident->order;

        if ($order === null) {
            $this->skipped++;
            $this->logSkipped($incident, null, 'order_missing');

            return;
        }

        if (! $this->eligibilityService->passesValidationForOrder($order)) {
            $this->skipped++;
            $this->logSkipped($incident, $order, 'validation_failed');

            return;
        }

        $this->eligible++;

        if ($dryRun) {
            $this->promoted++;
            $this->logPromoted($incident, $order, $actor, dryRun: true);

            return;
        }

        try {
            $outcome = DB::transaction(function () use ($incident, $actor): string {
                $fresh = Incident::query()
                    ->whereKey($incident->id)
                    ->lockForUpdate()
                    ->with(['order'])
                    ->first();

                if ($fresh === null) {
                    return 'missing';
                }

                if ($fresh->status === IncidentStatus::Open) {
                    return 'already_open';
                }

                if ($fresh->status !== IncidentStatus::AwaitingProductDetails) {
                    return 'status_changed';
                }

                $freshOrder = $fresh->order;

                if ($freshOrder === null) {
                    return 'order_missing';
                }

                if (! $this->eligibilityService->passesValidationForOrder($freshOrder)) {
                    return 'validation_failed';
                }

                $promoted = $this->statusService->updateStatus(
                    incident: $fresh,
                    status: IncidentStatus::Open,
                    actor: $actor,
                );

                $this->eligibilityService->evaluateAssignmentEligibility(
                    $promoted->order?->fresh() ?? $freshOrder->fresh(),
                    $actor,
                );

                $this->logPromoted($promoted, $freshOrder, $actor, dryRun: false);

                return 'promoted';
            });

            match ($outcome) {
                'promoted' => $this->promoted++,
                'already_open' => $this->recordRaceSkip($incident, $order, 'already_open', alreadyOpen: true),
                default => $this->recordRaceSkip($incident, $order, $outcome, alreadyOpen: false),
            };
        } catch (Throwable $exception) {
            $this->failures++;
            $this->logFailure($incident, $order, $exception);

            $this->error(sprintf(
                'Failed recovering incident %d (order %s): %s',
                $incident->id,
                $order->order_id,
                $exception->getMessage(),
            ));
        }
    }

    private function recordRaceSkip(Incident $incident, Order $order, string $reason, bool $alreadyOpen): void
    {
        // Eligible was counted before the locked re-check; reverse it for race skips.
        $this->eligible = max(0, $this->eligible - 1);

        if ($alreadyOpen) {
            $this->alreadyOpen++;
        } else {
            $this->skipped++;
        }

        $this->logSkipped($incident, $order, $reason);
    }

    /**
     * @return Builder<Incident>
     */
    private function candidateQuery(): Builder
    {
        return Incident::query()
            ->with(['order'])
            ->where('source', IncidentSource::Cashfree)
            ->where('status', IncidentStatus::AwaitingProductDetails)
            ->whereHas('order');
    }

    private function resetCounters(): void
    {
        $this->scanned = 0;
        $this->eligible = 0;
        $this->promoted = 0;
        $this->skipped = 0;
        $this->alreadyOpen = 0;
        $this->failures = 0;
    }

    private function resolveLimit(): ?int
    {
        $limit = $this->option('limit');

        if ($limit === null || $limit === '') {
            return null;
        }

        return max(1, (int) $limit);
    }

    private function renderProgress(bool $dryRun): void
    {
        $this->line(sprintf(
            'Progress — scanned: %d | eligible: %d | %s: %d | skipped: %d | already-open: %d | failures: %d',
            $this->scanned,
            $this->eligible,
            $dryRun ? 'would-promote' : 'promoted',
            $this->promoted,
            $this->skipped,
            $this->alreadyOpen,
            $this->failures,
        ));
    }

    private function renderSummary(bool $dryRun): void
    {
        $this->newLine();
        $this->info($dryRun ? 'Dry-run summary' : 'Execute summary');
        $this->line('scanned: '.$this->scanned);
        $this->line('eligible: '.$this->eligible);
        $this->line(($dryRun ? 'promoted (would): ' : 'promoted: ').$this->promoted);
        $this->line('skipped: '.$this->skipped);
        $this->line('already-open: '.$this->alreadyOpen);
        $this->line('failures: '.$this->failures);

        Log::info('cashfree.recover_awaiting_product_details.completed', [
            'dry_run' => $dryRun,
            'scanned' => $this->scanned,
            'eligible' => $this->eligible,
            'promoted' => $this->promoted,
            'skipped' => $this->skipped,
            'already_open' => $this->alreadyOpen,
            'failures' => $this->failures,
        ]);
    }

    private function logPromoted(Incident $incident, Order $order, User $actor, bool $dryRun): void
    {
        Log::info('cashfree.recover_awaiting_product_details.promoted', [
            'dry_run' => $dryRun,
            'incident_id' => $incident->id,
            'reference_no' => $incident->reference_no,
            'order_id' => $order->order_id,
            'order_db_id' => $order->id,
            'actor_id' => $actor->id,
            'from_status' => IncidentStatus::AwaitingProductDetails->value,
            'to_status' => IncidentStatus::Open->value,
        ]);
    }

    private function logSkipped(?Incident $incident, ?Order $order, string $reason): void
    {
        Log::info('cashfree.recover_awaiting_product_details.skipped', [
            'incident_id' => $incident?->id,
            'reference_no' => $incident?->reference_no,
            'order_id' => $order?->order_id,
            'order_db_id' => $order?->id,
            'reason' => $reason,
            'status' => $incident?->status?->value,
        ]);
    }

    private function logFailure(Incident $incident, Order $order, Throwable $exception): void
    {
        Log::error('cashfree.recover_awaiting_product_details.failed', [
            'incident_id' => $incident->id,
            'reference_no' => $incident->reference_no,
            'order_id' => $order->order_id,
            'order_db_id' => $order->id,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
        ]);
    }
}
