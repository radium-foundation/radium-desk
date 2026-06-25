<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Notifications\TransactionCompletedNotification;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderTransactionService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function assignTransactionId(Order $order, string $transactionId, User $actor): Order
    {
        if ($order->isTransactionLocked()) {
            throw ValidationException::withMessages([
                'transaction_id' => 'This order is already completed and locked.',
            ]);
        }

        $transactionId = trim($transactionId);

        if ($transactionId === '') {
            throw ValidationException::withMessages([
                'transaction_id' => 'Transaction ID is required.',
            ]);
        }

        return DB::transaction(function () use ($order, $transactionId, $actor): Order {
            $oldValues = [
                'transaction_id' => $order->transaction_id,
                'completed_at' => $order->completed_at?->toIso8601String(),
            ];

            $order->update([
                'transaction_id' => $transactionId,
                'completed_at' => now(),
                'transaction_assigned_by' => $actor->id,
                'updated_by' => $actor->id,
            ]);

            $freshOrder = $order->fresh(['transactionAssigner']);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'transaction.assigned',
                auditable: $freshOrder,
                oldValues: $oldValues,
                newValues: [
                    'transaction_id' => $freshOrder->transaction_id,
                    'completed_at' => $freshOrder->completed_at?->toIso8601String(),
                ],
            );

            $this->notifyTransactionCompleted($freshOrder, $transactionId, $actor);

            return $freshOrder;
        });
    }

    private function notifyTransactionCompleted(Order $order, string $transactionId, User $actor): void
    {
        $recipients = Incident::query()
            ->with(['creator', 'assignee'])
            ->where('order_id', $order->id)
            ->get()
            ->flatMap(fn (Incident $incident) => collect([$incident->creator, $incident->assignee]))
            ->filter(fn (?User $user): bool => $user !== null && $user->is_active && ! $user->trashed())
            ->unique('id');

        foreach ($recipients as $recipient) {
            $recipient->notify(new TransactionCompletedNotification($order, $transactionId, $actor));
        }
    }

    /**
     * @param  list<int>  $incidentIds
     * @return array{count: int, transaction_id: string, rows: array<int, array{incident_id: int, html: string}>}
     */
    public function assignTransactionIdToIncidents(array $incidentIds, string $transactionId, User $actor): array
    {
        $transactionId = trim($transactionId);

        if ($transactionId === '') {
            throw ValidationException::withMessages([
                'transaction_id' => 'Transaction ID is required.',
            ]);
        }

        $incidents = Incident::query()
            ->with(['order.transactionAssigner', 'creator', 'assignee'])
            ->whereIn('id', $incidentIds)
            ->get();

        $pendingIncidents = $incidents->filter(
            fn (Incident $incident): bool => $incident->order !== null && ! $incident->order->isTransactionLocked()
        );

        $ordersToUpdate = $pendingIncidents
            ->pluck('order.id')
            ->unique()
            ->values();

        foreach ($ordersToUpdate as $orderId) {
            $order = Order::query()->find($orderId);

            if ($order === null || ! $actor->can('assignTransaction', $order)) {
                continue;
            }

            $this->assignTransactionId($order, $transactionId, $actor);
        }

        $refreshedIncidents = Incident::query()
            ->with(['order.transactionAssigner', 'creator', 'assignee'])
            ->whereIn('id', $incidentIds)
            ->get()
            ->keyBy('id');

        $canManageBulk = $actor->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);

        $rows = [];

        foreach ($incidentIds as $incidentId) {
            $incident = $refreshedIncidents->get($incidentId);

            if ($incident === null) {
                continue;
            }

            $rows[] = [
                'incident_id' => $incident->id,
                'html' => view('dashboard.partials.service-case-row', [
                    'serviceCase' => $incident,
                    'canManageTransactions' => $canManageBulk,
                    'canSelectRows' => $canManageBulk,
                ])->render(),
            ];
        }

        return [
            'count' => $pendingIncidents->count(),
            'transaction_id' => $transactionId,
            'rows' => $rows,
        ];
    }

    public function unlockTransaction(Order $order, User $actor, ?string $reason = null): Order
    {
        if (! $order->isTransactionLocked()) {
            throw ValidationException::withMessages([
                'transaction_id' => 'This order is not locked.',
            ]);
        }

        return DB::transaction(function () use ($order, $actor, $reason): Order {
            $oldValues = [
                'transaction_id' => $order->transaction_id,
                'completed_at' => $order->completed_at?->toIso8601String(),
            ];

            $order->update([
                'transaction_id' => null,
                'completed_at' => null,
                'transaction_assigned_by' => null,
                'updated_by' => $actor->id,
            ]);

            $this->auditLogService->log(
                userId: $actor->id,
                event: 'transaction.unlocked',
                auditable: $order->fresh(),
                oldValues: $oldValues,
                newValues: [
                    'reason' => $reason,
                ],
            );

            return $order->fresh();
        });
    }
}
