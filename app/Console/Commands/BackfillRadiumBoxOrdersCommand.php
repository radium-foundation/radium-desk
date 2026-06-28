<?php

namespace App\Console\Commands;

use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentService;
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
#[Description('Queue RadiumBox enrichment jobs for historical orders missing serial number or device model')]
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
            'Scanning qualifying orders in chunks of %d%s.',
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

        if (! filled($order->order_id)) {
            $this->ordersSkipped++;

            return;
        }

        if (! $this->radiumBoxService->needsEnrichment($order)) {
            $this->ordersAlreadyComplete++;

            return;
        }

        if ($limit !== null && $this->ordersQueued >= $limit) {
            return;
        }

        if ($dryRun) {
            $this->ordersQueued++;

            return;
        }

        try {
            $this->enrichmentService->dispatch($order);
            $this->ordersQueued++;
        } catch (Throwable $exception) {
            $this->failedDispatches++;
            $this->ordersSkipped++;

            $this->error(sprintf(
                'Failed to queue order %s (ID %d): %s',
                $order->order_id,
                $order->id,
                $exception->getMessage(),
            ));
        }
    }

    /**
     * @return Builder<Order>
     */
    private function qualifyingOrdersQuery(): Builder
    {
        return Order::query()
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
}
