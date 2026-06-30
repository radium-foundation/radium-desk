<?php

namespace App\Console\Commands;

use App\Data\OrderIdentityRepairBatchOptions;
use App\Data\OrderIdentityRepairProgress;
use App\Data\OrderIdentityRepairSummary;
use App\Services\OrderIdentityRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('orders:repair-identity
    {--dry-run : Show repair candidates without applying changes}
    {--force : Run without confirmation prompt}
    {--limit= : Maximum number of repair candidates to process}
    {--offset=0 : Skip this many repair candidates before processing}
    {--resume : Resume from the last cached repair position}
    {--active-only : Process only orders with active service cases}')]
#[Description('Repair historical order identity using RadiumBox enrichment')]
class OrderIdentityRepairCommand extends Command
{
    public function __construct(
        private readonly OrderIdentityRepairService $repairService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $options = new OrderIdentityRepairBatchOptions(
            limit: $this->resolveLimit(),
            offset: $this->resolveOffset(),
            dryRun: (bool) $this->option('dry-run'),
            activeOnly: (bool) $this->option('active-only'),
            resume: (bool) $this->option('resume'),
        );

        if ($options->dryRun) {
            $this->info('Dry run — no changes will be written.');
        }

        if ($options->resume) {
            $lastPosition = $this->repairService->lastResumePosition();
            $this->info($lastPosition === null
                ? 'Resume requested, but no saved position was found. Starting from the beginning.'
                : 'Resuming after order database ID '.$lastPosition.'.');
        }

        $pendingCount = $this->repairService->countBatchTotal($options);

        if ($pendingCount === 0) {
            $this->info('No orders require identity repair in this batch.');

            return self::SUCCESS;
        }

        if (! $options->dryRun && ! $this->option('force') && ! $this->confirm(sprintf(
            'You are about to process %d repair candidate(s). Continue?',
            $pendingCount,
        ))) {
            $this->info('Repair cancelled.');

            return self::SUCCESS;
        }

        $summary = $this->repairService->repairWithOptions(
            $options,
            fn (OrderIdentityRepairProgress $progress) => $this->displayProgress($progress),
        );

        $this->displaySummary($summary, $options->dryRun);

        Log::info('Legacy identity repair command completed.', [
            'dry_run' => $options->dryRun,
            'force' => (bool) $this->option('force'),
            'active_only' => $options->activeOnly,
            'limit' => $options->limit,
            'offset' => $options->offset,
            'resume' => $options->resume,
            ...get_object_vars($summary),
            'failed_orders' => array_map(
                fn ($failure) => [
                    'order_id' => $failure->orderId,
                    'message' => $failure->message,
                    'category' => $failure->category->value,
                ],
                $summary->failedOrders,
            ),
        ]);

        return self::SUCCESS;
    }

    private function displayProgress(OrderIdentityRepairProgress $progress): void
    {
        $this->newLine();
        $this->line('Processed: '.$progress->processed.' / '.$progress->batchTotal);
        $this->line('Repaired: '.$progress->repaired);
        $this->line('Already valid: '.$progress->alreadyValid);
        $this->line('Failed: '.$progress->failed);
        $this->line('Rate limited: '.$progress->rateLimited);
        $this->line('Remaining: '.$progress->remaining);
    }

    private function displaySummary(OrderIdentityRepairSummary $summary, bool $dryRun): void
    {
        $this->newLine();
        $this->info('Legacy identity repair summary');
        $this->line('Orders scanned: '.$summary->ordersScanned);
        $this->line('Processed: '.$summary->ordersProcessed);
        $this->line('Repaired: '.$summary->ordersRepaired);
        $this->line('Already valid: '.$summary->ordersAlreadyValid);
        $this->line('Skipped: '.$summary->ordersSkipped);
        $this->line('Rate limited: '.$summary->rateLimited);
        $this->line('Duplicate serials: '.$summary->duplicateSerials);
        $this->line('Not found: '.$summary->notFound);
        $this->line('Validation failed: '.$summary->validationFailed);
        $this->line('Unexpected failures: '.$summary->unexpectedFailures);
        $this->line('Assignments escalated: '.$summary->assignmentsEscalated);
        $this->line('Assignments unchanged: '.$summary->assignmentsUnchanged);
        $this->line('Elapsed time: '.$summary->elapsedSeconds.'s');

        if ($summary->repairedOrderIds !== []) {
            $this->newLine();
            $this->info($dryRun ? 'Orders that would be repaired:' : 'Repaired orders:');

            foreach ($summary->repairedOrderIds as $orderId) {
                $this->line('- '.$orderId);
            }
        }

        if ($summary->failedOrders !== []) {
            $this->newLine();
            $this->line('Failed Orders');
            $this->line('--------------');

            foreach ($summary->failedOrders as $failure) {
                $this->line($failure->orderId);
                $this->line('Reason: '.$failure->displayReason());
                $this->newLine();
            }
        }
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

    private function resolveOffset(): int
    {
        $offset = $this->option('offset');

        if ($offset === null || $offset === '') {
            return 0;
        }

        return max(0, (int) $offset);
    }
}
