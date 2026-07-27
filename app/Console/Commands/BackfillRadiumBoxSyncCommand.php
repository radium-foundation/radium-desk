<?php

namespace App\Console\Commands;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxEnrichmentRetryPolicy;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\RadiumBox\RadiumBoxService;
use App\Services\RadiumBox\RadiumBoxSyncRecoveryService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('radiumbox:backfill-sync
    {--limit= : Maximum number of orders to process}
    {--chunk=50 : Number of orders to load per chunk}
    {--dry-run : Show what would be processed without dispatching jobs}
    {--order= : Process a single order by order_id}')]
#[Description('Backfill missed RadiumBox enrichment syncs using existing dispatch/retry paths (idempotent)')]
class BackfillRadiumBoxSyncCommand extends Command
{
    private int $scanned = 0;

    private int $processed = 0;

    private int $skipped = 0;

    private int $failed = 0;

    public function __construct(
        private readonly RadiumBoxOrderEnrichmentService $enrichmentService,
        private readonly RadiumBoxService $radiumBoxService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
        private readonly RadiumBoxSyncRecoveryService $recoveryService,
        private readonly RadiumBoxEnrichmentRetryPolicy $retryPolicy,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $limit = $this->resolveLimit();
        $chunkSize = max(1, (int) $this->option('chunk'));
        $orderId = $this->option('order');
        $orderId = is_string($orderId) && $orderId !== '' ? trim($orderId) : null;

        if ($orderId !== null) {
            $order = Order::query()->where('order_id', $orderId)->first();

            if ($order === null) {
                $this->error("Order not found: {$orderId}");

                return self::FAILURE;
            }

            $this->processOrder($order, $dryRun, $limit, manualOverride: true);
            $this->renderSummary($dryRun);

            return self::SUCCESS;
        }

        $this->info(sprintf(
            'Scanning paid Cashfree orders for RadiumBox sync backfill in chunks of %d%s.',
            $chunkSize,
            $dryRun ? ' (dry run)' : '',
        ));

        $this->candidateQuery()
            ->orderBy('id')
            ->chunkById($chunkSize, function ($orders) use ($dryRun, $limit): bool {
                foreach ($orders as $order) {
                    if ($limit !== null && $this->processed >= $limit) {
                        return false;
                    }

                    $this->processOrder($order, $dryRun, $limit, manualOverride: false);
                }

                $this->renderProgress($dryRun);

                return $limit === null || $this->processed < $limit;
            });

        $this->renderSummary($dryRun);

        return self::SUCCESS;
    }

    private function processOrder(Order $order, bool $dryRun, ?int $limit, bool $manualOverride): void
    {
        $this->scanned++;

        $decision = $this->resolveDecision($order, $manualOverride);

        if ($decision['type'] === 'skip') {
            $this->skipped++;
            $this->logSkipped($order, $decision['reason'], $manualOverride);

            return;
        }

        if ($limit !== null && $this->processed >= $limit) {
            return;
        }

        $action = $decision['action'];

        if ($dryRun) {
            $this->processed++;
            $this->logProcessed($order, $action, dryRun: true, manualOverride: $manualOverride);

            return;
        }

        try {
            $this->executeAction($order, $action);
            $this->processed++;
            $this->logProcessed($order, $action, dryRun: false, manualOverride: $manualOverride);
        } catch (Throwable $exception) {
            $this->failed++;
            $this->logFailed($order, $action, $exception->getMessage());

            $this->error(sprintf(
                'Failed %s for order %s: %s',
                $action,
                $order->order_id,
                $exception->getMessage(),
            ));
        }
    }

    /**
     * @return array{type: 'skip', reason: string}|array{type: 'process', action: string}
     */
    private function resolveDecision(Order $order, bool $manualOverride): array
    {
        if (! filled(trim((string) $order->order_id))) {
            return ['type' => 'skip', 'reason' => 'missing_order_id'];
        }

        if (! filled($order->cashfree_payment_id)) {
            return ['type' => 'skip', 'reason' => 'not_cashfree_paid_order'];
        }

        if (! $this->radiumBoxService->needsEnrichment($order)) {
            return ['type' => 'skip', 'reason' => 'already_complete'];
        }

        $status = $this->syncStore->status($order->id);

        if ($status === RadiumBoxEnrichmentSyncStatus::Pending) {
            if ($this->recoveryService->isStalePending($order)) {
                return ['type' => 'process', 'action' => 'retry_enrichment'];
            }

            return ['type' => 'skip', 'reason' => 'enrichment_already_pending'];
        }

        if ($status === RadiumBoxEnrichmentSyncStatus::Failed) {
            if ($manualOverride || $this->recoveryService->isSafeToRecover($order)) {
                return ['type' => 'process', 'action' => 'retry_enrichment'];
            }

            return ['type' => 'skip', 'reason' => 'failed_not_safe_to_recover'];
        }

        if ($manualOverride) {
            return ['type' => 'process', 'action' => 'dispatch_enrichment'];
        }

        if (! $this->retryPolicy->isWithinAutomaticWindow($order)) {
            return ['type' => 'skip', 'reason' => 'automatic_retry_window_expired'];
        }

        if (! $this->retryPolicy->hasRetryIntervalElapsed($order, $this->syncStore->lastAttemptAt($order->id))) {
            return ['type' => 'skip', 'reason' => 'retry_interval_not_elapsed'];
        }

        return ['type' => 'process', 'action' => 'dispatch_enrichment'];
    }

    private function executeAction(Order $order, string $action): void
    {
        if ($action === 'dispatch_enrichment') {
            $this->enrichmentService->dispatchToMaintenance($order);

            return;
        }

        if ($action === 'retry_enrichment') {
            $this->enrichmentService->retryOrderEnrichment($order);

            return;
        }

        throw new \InvalidArgumentException("Unsupported action: {$action}");
    }

    /**
     * @return Builder<Order>
     */
    private function candidateQuery(): Builder
    {
        return Order::query()
            ->whereNotNull('cashfree_payment_id')
            ->where('cashfree_payment_id', '!=', '')
            ->whereNotNull('order_id')
            ->where('order_id', '!=', '')
            ->where(function (Builder $query): void {
                $query->where(function (Builder $serialQuery): void {
                    $serialQuery->whereNull('serial_number')
                        ->orWhere('serial_number', '');
                })->orWhere(function (Builder $deviceModelQuery): void {
                    $deviceModelQuery
                        ->where(function (Builder $textQuery): void {
                            $textQuery->whereNull('device_model')
                                ->orWhere('device_model', '');
                        })
                        ->whereNull('device_model_id');
                });
            });
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
            'Progress — orders scanned: %d, %s: %d, orders skipped: %d, orders failed: %d',
            $this->scanned,
            $dryRun ? 'orders would process' : 'orders processed',
            $this->processed,
            $this->skipped,
            $this->failed,
        ));
    }

    private function renderSummary(bool $dryRun): void
    {
        $this->newLine();
        $this->info('Summary');
        $this->line("orders scanned: {$this->scanned}");
        $this->line(($dryRun ? 'orders would process' : 'orders processed').": {$this->processed}");
        $this->line("orders skipped: {$this->skipped}");
        $this->line("orders failed: {$this->failed}");

        Log::info('RadiumBox sync backfill completed.', [
            'dry_run' => $dryRun,
            'scanned' => $this->scanned,
            'processed' => $dryRun ? 0 : $this->processed,
            'would_process' => $dryRun ? $this->processed : 0,
            'skipped' => $this->skipped,
            'failed' => $this->failed,
        ]);
    }

    private function logProcessed(Order $order, string $action, bool $dryRun, bool $manualOverride): void
    {
        Log::info('RadiumBox sync backfill processed order.', [
            'dry_run' => $dryRun,
            'action' => $action,
            'manual_override' => $manualOverride,
            'order_id' => $order->order_id,
            'order_db_id' => $order->id,
            'sync_status' => $this->syncStore->status($order->id)->value,
        ]);
    }

    private function logSkipped(Order $order, string $reason, bool $manualOverride): void
    {
        Log::info('RadiumBox sync backfill skipped order.', [
            'reason' => $reason,
            'manual_override' => $manualOverride,
            'order_id' => $order->order_id,
            'order_db_id' => $order->id,
            'sync_status' => $this->syncStore->status($order->id)->value,
        ]);
    }

    private function logFailed(Order $order, string $action, string $message): void
    {
        Log::warning('RadiumBox sync backfill failed order.', [
            'action' => $action,
            'order_id' => $order->order_id,
            'order_db_id' => $order->id,
            'message' => $message,
        ]);
    }
}
