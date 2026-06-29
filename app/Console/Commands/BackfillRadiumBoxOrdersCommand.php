<?php

namespace App\Console\Commands;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\RadiumBox\RadiumBoxService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\Log;
use Throwable;

#[Signature('radiumbox:backfill-orders
    {--limit= : Maximum number of orders to queue}
    {--chunk=50 : Number of orders to load per chunk}
    {--dry-run : Show what would be queued without dispatching jobs}
    {--order= : Process a single order by order_id}')]
#[Description('Queue RadiumBox enrichment retries for paid Cashfree orders missing serial number or device model')]
class BackfillRadiumBoxOrdersCommand extends Command
{
    private int $ordersScanned = 0;

    private int $ordersQueued = 0;

    private int $ordersSkipped = 0;

    private int $ordersAlreadyComplete = 0;

    private int $failedDispatches = 0;

    public function __construct(
        private readonly RadiumBoxOrderEnrichmentService $enrichmentService,
        private readonly RadiumBoxService $radiumBoxService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
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

            $this->processOrder($order, $dryRun, $limit);
            $this->renderSummary($dryRun);

            return self::SUCCESS;
        }

        $query = $this->qualifyingOrdersQuery();

        $this->info(sprintf(
            'Scanning paid Cashfree orders missing product details in chunks of %d%s.',
            $chunkSize,
            $dryRun ? ' (dry run)' : '',
        ));

        $query->orderBy('id')->chunkById($chunkSize, function ($orders) use ($dryRun, $limit): bool {
            foreach ($orders as $order) {
                if ($limit !== null && $this->ordersQueued >= $limit) {
                    return false;
                }

                $this->processOrder($order, $dryRun, $limit);
            }

            $this->renderProgress($dryRun);

            if ($limit !== null && $this->ordersQueued >= $limit) {
                return false;
            }

            return true;
        });

        $this->renderSummary($dryRun);

        return self::SUCCESS;
    }

    private function processOrder(Order $order, bool $dryRun, ?int $limit): void
    {
        $this->ordersScanned++;

        $skipReason = $this->resolveSkipReason($order);

        if ($skipReason === 'already_complete') {
            $this->ordersAlreadyComplete++;
            $this->logRetrySkipped($order, $skipReason);

            return;
        }

        if ($skipReason !== null) {
            $this->ordersSkipped++;
            $this->logRetrySkipped($order, $skipReason);

            return;
        }

        if ($limit !== null && $this->ordersQueued >= $limit) {
            return;
        }

        if ($dryRun) {
            $this->ordersQueued++;
            $this->logRetryStarted($order, dryRun: true);

            return;
        }

        try {
            $this->enrichmentService->dispatch($order);
            $this->ordersQueued++;
            $this->logRetryStarted($order, dryRun: false);
        } catch (Throwable $exception) {
            $this->failedDispatches++;
            $this->ordersSkipped++;
            $this->logRetryFailed($order, $exception->getMessage(), phase: 'dispatch');

            $this->error(sprintf(
                'Failed to queue order %s (ID %d): %s',
                $order->order_id,
                $order->id,
                $exception->getMessage(),
            ));
        }
    }

    private function resolveSkipReason(Order $order): ?string
    {
        if (! filled($order->order_id)) {
            return 'missing_order_id';
        }

        if (! filled($order->cashfree_payment_id)) {
            return 'not_cashfree_paid_order';
        }

        if (! $this->radiumBoxService->needsEnrichment($order)) {
            return 'already_complete';
        }

        if ($this->syncStore->status($order->id) === RadiumBoxEnrichmentSyncStatus::Pending) {
            return 'enrichment_already_pending';
        }

        return null;
    }

    /**
     * @return Builder<Order>
     */
    private function qualifyingOrdersQuery(): Builder
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
            'Progress — scanned: %d, %s: %d, skipped: %d, already complete: %d, failed dispatches: %d, estimated completion: %s',
            $this->ordersScanned,
            $dryRun ? 'would queue' : 'queued',
            $this->ordersQueued,
            $this->ordersSkipped,
            $this->ordersAlreadyComplete,
            $this->failedDispatches,
            $this->estimatedCompletion($dryRun),
        ));
    }

    private function renderSummary(bool $dryRun): void
    {
        $this->newLine();
        $this->info('Summary');
        $this->line("Orders scanned: {$this->ordersScanned}");
        $this->line('Orders queued: '.($dryRun ? 0 : $this->ordersQueued));
        $this->line('Orders would queue: '.($dryRun ? $this->ordersQueued : 0));
        $this->line("Orders skipped: {$this->ordersSkipped}");
        $this->line("Orders already complete: {$this->ordersAlreadyComplete}");
        $this->line("Failed dispatches: {$this->failedDispatches}");
        $this->line('Estimated completion: '.$this->estimatedCompletion($dryRun));
        $this->newLine();
        $this->line('API jobs queued: '.($dryRun ? 0 : $this->ordersQueued));
        $this->line('Expected retries: up to 3 per job (4 total attempts with backoff 60s, 300s, 1800s)');
        $this->line('Immediate API calls: 0 (jobs run asynchronously via queue)');

        Log::info('RadiumBox order backfill completed.', [
            'dry_run' => $dryRun,
            'orders_scanned' => $this->ordersScanned,
            'orders_queued' => $dryRun ? 0 : $this->ordersQueued,
            'orders_would_queue' => $dryRun ? $this->ordersQueued : 0,
            'orders_skipped' => $this->ordersSkipped,
            'orders_already_complete' => $this->ordersAlreadyComplete,
            'failed_dispatches' => $this->failedDispatches,
            'estimated_completion' => $this->estimatedCompletion($dryRun),
            'job_class' => RadiumBoxOrderEnrichmentJob::class,
        ]);
    }

    private function estimatedCompletion(bool $dryRun): string
    {
        $jobs = $this->ordersQueued;

        if ($jobs === 0) {
            return 'N/A (no jobs queued)';
        }

        if ($dryRun) {
            return sprintf('~%d minute(s) once %d job(s) are queued (cron worker, ~1 job/min on shared hosting)', $jobs, $jobs);
        }

        return sprintf('~%d minute(s) at ~1 job/min via cron queue worker', $jobs);
    }

    private function logRetryStarted(Order $order, bool $dryRun): void
    {
        Log::info('RadiumBox enrichment retry started.', [
            'order_id' => $order->order_id,
            'order_db_id' => $order->id,
            'dry_run' => $dryRun,
        ]);
    }

    private function logRetrySkipped(Order $order, string $reason): void
    {
        Log::info('RadiumBox enrichment retry skipped.', [
            'order_id' => $order->order_id,
            'order_db_id' => $order->id,
            'reason' => $reason,
        ]);
    }

    private function logRetryFailed(Order $order, string $reason, string $phase): void
    {
        Log::warning('RadiumBox enrichment retry failed.', [
            'order_id' => $order->order_id,
            'order_db_id' => $order->id,
            'phase' => $phase,
            'reason' => $reason,
        ]);
    }
}
