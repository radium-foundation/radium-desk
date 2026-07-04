<?php

namespace App\Services;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Notifications\TransactionCompletedNotification;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class OrderTransactionService
{
    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly ServiceCaseStatusService $serviceCaseStatusService,
        private readonly DashboardService $dashboardService,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
        private readonly CustomerVerificationService $customerVerificationService,
        private readonly ServiceReferenceIntegrityService $serviceReferenceIntegrityService,
    ) {}

    public function assignTransactionId(Order $order, string $transactionId, User $actor, bool $broadcast = true): Order
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

        $this->serviceReferenceIntegrityService->assertNotAlreadyAssigned($transactionId, $order);
        $this->customerVerificationService->assertCanCompleteService($order, $actor);

        return DB::transaction(function () use ($order, $transactionId, $actor, $broadcast): Order {
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
                event: 'service_reference.assigned',
                auditable: $freshOrder,
                oldValues: $oldValues,
                newValues: [
                    'transaction_id' => $freshOrder->transaction_id,
                    'completed_at' => $freshOrder->completed_at?->toIso8601String(),
                ],
            );

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

            $this->serviceCaseStatusService->closeActiveServiceCasesForOrder($freshOrder, $actor);

            $this->notifyTransactionCompleted($freshOrder, $transactionId, $actor);

            if ($broadcast) {
                Incident::query()
                    ->with(['order.transactionAssigner', 'creator', 'assignee'])
                    ->where('order_id', $freshOrder->id)
                    ->get()
                    ->each(fn (Incident $incident) => $this->dashboardBroadcastService->transactionAssigned($incident, $actor));
            }

            return $freshOrder;
        });
    }

    private function notifyTransactionCompleted(Order $order, string $transactionId, User $actor): void
    {
        if (! app(SettingService::class)->getBool('notifications.transaction_enabled', true)) {
            return;
        }

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
     * @return array{
     *     count: int,
     *     transaction_id: string,
     *     rows: array<int, array{incident_id: int, html: string}>,
     *     succeeded_incident_ids: list<int>,
     *     failed_incidents: list<array{incident_id: int, message: string}>
     * }
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
            ->values()
            ->all();

        foreach ($ordersToUpdate as $orderId) {
            $order = Order::query()->find($orderId);

            if ($order === null || ! $actor->can('assignTransaction', $order)) {
                continue;
            }

            try {
                $this->assignTransactionId($order, $transactionId, $actor, broadcast: false);
            } catch (ValidationException $exception) {
                // Allow partial bulk success; per-incident failure details are resolved below.
            }
        }

        $refreshedIncidents = Incident::query()
            ->with(['order.transactionAssigner', 'creator', 'assignee'])
            ->whereIn('id', $incidentIds)
            ->get()
            ->keyBy('id');

        $rows = [];

        foreach ($incidentIds as $incidentId) {
            $incident = $refreshedIncidents->get($incidentId);

            if ($incident === null) {
                continue;
            }

            $rows[] = [
                'incident_id' => $incident->id,
                'html' => view(
                    'dashboard.partials.service-case-row',
                    $this->dashboardService->serviceCaseRowViewData($incident, $actor),
                )->render(),
            ];
        }

        $succeededIncidentIds = [];
        $failedIncidents = [];

        foreach ($incidentIds as $incidentId) {
            $incident = $refreshedIncidents->get($incidentId);

            if ($incident === null) {
                $failedIncidents[] = [
                    'incident_id' => $incidentId,
                    'message' => 'Service case not found.',
                ];

                continue;
            }

            if ($incident->order !== null
                && $incident->order->transaction_id === $transactionId
                && $incident->order->isTransactionLocked()) {
                $succeededIncidentIds[] = $incidentId;

                continue;
            }

            if ($incident->order === null) {
                $failedIncidents[] = [
                    'incident_id' => $incidentId,
                    'message' => 'This service case has no order.',
                ];

                continue;
            }

            if ($incident->order->isTransactionLocked()) {
                $failedIncidents[] = [
                    'incident_id' => $incidentId,
                    'message' => 'This order is already completed and locked.',
                ];

                continue;
            }

            if (! $actor->can('assignTransaction', $incident->order)) {
                $failedIncidents[] = [
                    'incident_id' => $incidentId,
                    'message' => 'This action is unauthorized.',
                ];

                continue;
            }

            $conflictingOrder = $this->serviceReferenceIntegrityService->findConflictingOrder(
                $transactionId,
                $incident->order->id,
            );

            if ($conflictingOrder !== null) {
                $failedIncidents[] = [
                    'incident_id' => $incidentId,
                    'message' => sprintf(
                        'This service reference is already linked to order %s.',
                        $conflictingOrder->order_id,
                    ),
                ];

                continue;
            }

            if (! $this->customerVerificationService->canCompleteService($incident->order)) {
                $failedIncidents[] = [
                    'incident_id' => $incidentId,
                    'message' => 'Customer verification required before completing service.',
                ];

                continue;
            }

            $failedIncidents[] = [
                'incident_id' => $incidentId,
                'message' => 'Unable to assign transaction ID.',
            ];
        }

        if ($succeededIncidentIds !== []) {
            $this->dashboardBroadcastService->transactionsAssigned($succeededIncidentIds, $actor);
        }

        return [
            'count' => count($succeededIncidentIds),
            'transaction_id' => $transactionId,
            'rows' => $rows,
            'succeeded_incident_ids' => $succeededIncidentIds,
            'failed_incidents' => $failedIncidents,
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
