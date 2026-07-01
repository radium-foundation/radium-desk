<?php

namespace App\Console\Commands;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\ServiceCaseStatusService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Throwable;

#[Signature('service-cases:sync-closed-status {--dry-run : Show what would be changed without updating anything}')]
#[Description('Close unfinished service cases for orders that already have a transaction ID')]
class SyncCompletedOrdersServiceCases extends Command
{
    public function __construct(
        private readonly ServiceCaseStatusService $serviceCaseStatusService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');

        $completedOrderIds = Order::query()
            ->whereNotNull('transaction_id')
            ->where('transaction_id', '!=', '')
            ->orderBy('id')
            ->pluck('id');

        $ordersScanned = $completedOrderIds->count();

        $incidents = Incident::query()
            ->whereIn('order_id', $completedOrderIds)
            ->where('status', '!=', IncidentStatus::Closed)
            ->with(['order' => fn ($query) => $query->select(
                'id',
                'order_id',
                'transaction_assigned_by',
                'updated_by',
                'created_by',
            )])
            ->orderBy('order_id')
            ->orderBy('id')
            ->get();

        $skipped = $ordersScanned - $incidents->pluck('order_id')->unique()->count();

        $serviceCasesUpdated = 0;
        $failures = 0;

        /** @var list<array{order_id: string, reference: string, status: string, action: string}> $rows */
        $rows = [];

        foreach ($incidents as $incident) {
            $order = $incident->order;

            if ($order === null) {
                continue;
            }

            $currentStatus = $incident->status->label();
            $action = 'Would Close';

            if (! $dryRun) {
                $action = $this->closeServiceCase($order, $incident, $serviceCasesUpdated, $failures);
            }

            $rows[] = [
                'order_id' => $order->order_id,
                'reference' => $incident->display_reference,
                'status' => $currentStatus,
                'action' => $action,
            ];
        }

        if ($rows !== []) {
            $this->table(
                ['Order ID', 'Service Case Reference', 'Current Status', 'Action'],
                collect($rows)->map(fn (array $row): array => [
                    $row['order_id'],
                    $row['reference'],
                    $row['status'],
                    $row['action'],
                ])->all(),
            );
        }

        $this->newLine();
        $this->info('Summary');
        $this->line("Orders scanned: {$ordersScanned}");
        $this->line('Service Cases updated: '.($dryRun ? 0 : $serviceCasesUpdated));
        $this->line("Skipped: {$skipped}");
        $this->line("Failures: {$failures}");

        return self::SUCCESS;
    }

    private function closeServiceCase(Order $order, Incident $incident, int &$serviceCasesUpdated, int &$failures): string
    {
        $actor = $this->resolveActor($order);

        if ($actor === null) {
            $failures++;
            $this->error(sprintf(
                'Failed to close %s for order %s: no actor user available.',
                $incident->display_reference,
                $order->order_id,
            ));

            return 'Failed';
        }

        try {
            $this->serviceCaseStatusService->updateStatus($incident, IncidentStatus::Closed, $actor);
            $serviceCasesUpdated++;

            return 'Closed';
        } catch (Throwable $exception) {
            $failures++;
            $this->error(sprintf(
                'Failed to close %s for order %s: %s',
                $incident->display_reference,
                $order->order_id,
                $exception->getMessage(),
            ));

            return 'Failed';
        }
    }

    private function resolveActor(Order $order): ?User
    {
        $userId = $order->transaction_assigned_by
            ?? $order->updated_by
            ?? $order->created_by;

        if ($userId === null) {
            return null;
        }

        return User::query()->find($userId);
    }
}
