<?php

namespace App\Console\Commands;

use App\Enums\IncidentStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\RadiumBox\RadiumBoxService;
use App\Services\RadiumBox\RadiumBoxSyncRecoveryService;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAutomationMonitorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('radiumbox:backfill-ready-queue
    {--limit= : Maximum number of cases to process}
    {--chunk=50 : Number of incidents to load per chunk}
    {--dry-run : Show what would be processed without dispatching or assigning}')]
#[Description('Backfill Ready Queue for active service cases missing RadiumBox enrichment or assignment re-evaluation')]
class BackfillReadyQueueCommand extends Command
{
    /** @var list<string> */
    protected $aliases = ['readyqueue:backfill'];

    private int $scanned = 0;

    private int $processed = 0;

    private int $skipped = 0;

    private int $failed = 0;

    /** @var array<int, true> */
    private array $enrichmentHandledOrderIds = [];

    public function __construct(
        private readonly RadiumBoxService $radiumBoxService,
        private readonly RadiumBoxOrderEnrichmentService $enrichmentService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
        private readonly RadiumBoxSyncRecoveryService $recoveryService,
        private readonly ServiceCaseAssignmentEligibilityService $eligibilityService,
        private readonly ServiceCaseAutomationMonitorService $automationMonitor,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->resolveLimit();
        $chunkSize = max(1, (int) $this->option('chunk'));

        $this->info(sprintf(
            'Scanning active service cases for Ready Queue backfill in chunks of %d%s.',
            $chunkSize,
            $dryRun ? ' (dry run)' : '',
        ));

        $this->candidateQuery()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($incidents) use ($dryRun, $limit): bool {
                foreach ($incidents as $incident) {
                    if ($limit !== null && $this->processed >= $limit) {
                        return false;
                    }

                    $this->processIncident($incident, $dryRun, $limit);
                }

                $this->renderProgress($dryRun);

                return $limit === null || $this->processed < $limit;
            });

        $this->renderSummary($dryRun);

        return self::SUCCESS;
    }

    private function processIncident(Incident $incident, bool $dryRun, ?int $limit): void
    {
        $this->scanned++;

        $order = $incident->order;
        $decision = $this->resolveDecision($incident, $order);

        if ($decision['type'] === 'skip') {
            $this->skipped++;
            $this->logSkipped($incident, $order, $decision['reason']);

            return;
        }

        if ($limit !== null && $this->processed >= $limit) {
            return;
        }

        $action = $decision['action'];

        if (in_array($action, ['dispatch_enrichment', 'retry_enrichment'], true)) {
            $this->enrichmentHandledOrderIds[$order->id] = true;
        }

        if ($dryRun) {
            $this->processed++;
            $this->logProcessed($incident, $order, $action, dryRun: true);

            return;
        }

        try {
            $this->executeAction($incident, $order, $action);
            $this->processed++;
            $this->logProcessed($incident, $order, $action, dryRun: false);
        } catch (Throwable $exception) {
            $this->failed++;
            $this->logFailed($incident, $order, $action, $exception->getMessage());

            $this->error(sprintf(
                'Failed %s for incident %d / order %s: %s',
                $action,
                $incident->id,
                $order->order_id,
                $exception->getMessage(),
            ));
        }
    }

    /**
     * @return array{type: 'skip', reason: string}|array{type: 'process', action: string}
     */
    private function resolveDecision(Incident $incident, ?Order $order): array
    {
        if ($order === null) {
            return ['type' => 'skip', 'reason' => 'missing_order'];
        }

        if (! filled(trim((string) $order->order_id))) {
            return ['type' => 'skip', 'reason' => 'missing_order_id'];
        }

        if (Order::isHardwareOrderId($order->order_id) || $order->isInquiryOrder()) {
            return ['type' => 'skip', 'reason' => 'non_service_order'];
        }

        if ($this->isAlreadyReadyQueueAssignee($incident)) {
            return ['type' => 'skip', 'reason' => 'already_in_ready_queue'];
        }

        $status = $this->syncStore->status($order->id);
        $needsEnrichmentPath = $this->radiumBoxService->needsEnrichment($order)
            || $status === RadiumBoxEnrichmentSyncStatus::Pending
            || $status === RadiumBoxEnrichmentSyncStatus::Failed;

        if ($needsEnrichmentPath && array_key_exists($order->id, $this->enrichmentHandledOrderIds)) {
            return ['type' => 'skip', 'reason' => 'enrichment_already_handled_this_run'];
        }

        if ($status === RadiumBoxEnrichmentSyncStatus::Pending) {
            if ($this->recoveryService->isStalePending($order)) {
                return ['type' => 'process', 'action' => 'retry_enrichment'];
            }

            return ['type' => 'skip', 'reason' => 'enrichment_already_pending'];
        }

        if ($status === RadiumBoxEnrichmentSyncStatus::Failed) {
            return ['type' => 'process', 'action' => 'retry_enrichment'];
        }

        if ($this->radiumBoxService->needsEnrichment($order)) {
            return ['type' => 'process', 'action' => 'dispatch_enrichment'];
        }

        if (! $this->eligibilityService->passesValidationForOrder($order)) {
            return ['type' => 'skip', 'reason' => 'not_eligible_for_ready_queue'];
        }

        if ($incident->automation_pending_until !== null && $incident->automation_pending_until->isPast()) {
            return ['type' => 'skip', 'reason' => 'awaiting_grace_processor'];
        }

        return ['type' => 'process', 'action' => 'evaluate_eligibility'];
    }

    private function executeAction(Incident $incident, Order $order, string $action): void
    {
        if ($action === 'dispatch_enrichment') {
            $this->enrichmentService->dispatch($order);

            return;
        }

        if ($action === 'retry_enrichment') {
            $this->enrichmentService->retryOrderEnrichment($order);

            return;
        }

        if ($action === 'evaluate_eligibility') {
            $actor = $this->resolveActor($incident);
            $this->eligibilityService->evaluateAssignmentEligibility($order->fresh(), $actor);

            return;
        }

        throw new \InvalidArgumentException("Unsupported action: {$action}");
    }

    private function isAlreadyReadyQueueAssignee(Incident $incident): bool
    {
        if ($incident->assigned_to_user_id === null) {
            return false;
        }

        // Always resolve fresh — eager-loaded role relations can be empty/stale in CLI runs.
        $assignee = User::query()->find($incident->assigned_to_user_id);

        if ($assignee === null) {
            return false;
        }

        return $assignee->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);
    }

    private function resolveActor(Incident $incident): User
    {
        $incident->loadMissing('creator');

        return $this->automationMonitor->resolveAutomationActor($incident->creator);
    }

    /**
     * @return Builder<Incident>
     */
    private function candidateQuery(): Builder
    {
        return Incident::query()
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->whereNotNull('order_id')
            ->with(['order', 'assignee', 'creator']);
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

    private function renderProgress(bool $dryRun): void
    {
        $this->line(sprintf(
            'Progress — cases scanned: %d, %s: %d, cases skipped: %d, cases failed: %d',
            $this->scanned,
            $dryRun ? 'cases would process' : 'cases processed',
            $this->processed,
            $this->skipped,
            $this->failed,
        ));
    }

    private function renderSummary(bool $dryRun): void
    {
        $this->newLine();
        $this->info('Summary');
        $this->line("cases scanned: {$this->scanned}");
        $this->line(($dryRun ? 'cases would process' : 'cases processed').": {$this->processed}");
        $this->line("cases skipped: {$this->skipped}");
        $this->line("cases failed: {$this->failed}");

        Log::info('Ready Queue backfill completed.', [
            'dry_run' => $dryRun,
            'scanned' => $this->scanned,
            'processed' => $dryRun ? 0 : $this->processed,
            'would_process' => $dryRun ? $this->processed : 0,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
        ]);
    }

    private function logProcessed(Incident $incident, ?Order $order, string $action, bool $dryRun): void
    {
        Log::info('Ready Queue backfill processed case.', [
            'dry_run' => $dryRun,
            'action' => $action,
            'incident_id' => $incident->id,
            'order_id' => $order?->order_id,
            'order_db_id' => $order?->id,
            // Do not pass a possibly-stale Order model — SyncStore memoizes preloaded status.
            'sync_status' => $order !== null
                ? $this->syncStore->status($order->id)->value
                : null,
        ]);
    }

    private function logSkipped(Incident $incident, ?Order $order, string $reason): void
    {
        Log::info('Ready Queue backfill skipped case.', [
            'reason' => $reason,
            'incident_id' => $incident->id,
            'order_id' => $order?->order_id,
            'order_db_id' => $order?->id,
        ]);
    }

    private function logFailed(Incident $incident, ?Order $order, string $action, string $message): void
    {
        Log::warning('Ready Queue backfill failed case.', [
            'action' => $action,
            'incident_id' => $incident->id,
            'order_id' => $order?->order_id,
            'order_db_id' => $order?->id,
            'message' => $message,
        ]);
    }
}
