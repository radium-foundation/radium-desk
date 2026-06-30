<?php

namespace App\Console\Commands;

use App\Services\OrderIdentityRepairService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('orders:repair-identity
    {--dry-run : Show repair candidates without applying changes}
    {--force : Run without confirmation prompt}
    {--limit= : Maximum number of orders to process}
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
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');
        $activeOnly = (bool) $this->option('active-only');
        $limit = $this->resolveLimit();

        if ($dryRun) {
            $this->info('Dry run — no changes will be written.');
        }

        $pendingCount = $this->repairService->countPendingRepairs($activeOnly);

        if ($pendingCount === 0) {
            $this->info('No orders require identity repair.');

            return self::SUCCESS;
        }

        if (! $dryRun && ! $force && ! $this->confirm(sprintf(
            'You are about to update %d historical order(s). Continue?',
            $pendingCount,
        ))) {
            $this->info('Repair cancelled.');

            return self::SUCCESS;
        }

        $summary = $this->repairService->repair($limit, $dryRun, $activeOnly);

        $this->newLine();
        $this->info('Legacy identity repair summary');
        $this->line('Orders scanned: '.$summary->ordersScanned);
        $this->line('Orders repaired: '.$summary->ordersRepaired);
        $this->line('Orders skipped: '.$summary->ordersSkipped);
        $this->line('Orders already valid: '.$summary->ordersAlreadyValid);
        $this->line('Orders failed: '.$summary->ordersFailed);
        $this->line('Assignments escalated: '.$summary->assignmentsEscalated);
        $this->line('Assignments to agent: '.$summary->assignmentsToAgent);
        $this->line('Assignments unchanged: '.$summary->assignmentsUnchanged);

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

        Log::info('Legacy identity repair command completed.', [
            'dry_run' => $dryRun,
            'force' => $force,
            'active_only' => $activeOnly,
            'limit' => $limit,
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

    private function resolveLimit(): ?int
    {
        $limit = $this->option('limit');

        if ($limit === null || $limit === '') {
            return null;
        }

        $parsed = (int) $limit;

        return $parsed > 0 ? $parsed : null;
    }
}
