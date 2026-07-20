<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Jobs\SendServiceReferenceDriverGuideJob;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Notifications\TransactionCompletedNotification;
use App\Services\Operations\TeamMemberActivityService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Throwable;

class OrderTransactionService
{
    public const DASHBOARD_REFRESH_WARNING = 'Reference numbers assigned successfully. Dashboard refresh failed. Please refresh the page.';

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly ServiceCaseStatusService $serviceCaseStatusService,
        private readonly DashboardService $dashboardService,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
        private readonly CustomerVerificationService $customerVerificationService,
    ) {}

    public function assignTransactionId(
        Order $order,
        string $transactionId,
        User $actor,
        bool $broadcast = true,
    ): Order {
        if ($order->isTransactionLocked()) {
            if (trim($transactionId) === trim($order->transaction_id ?? '')) {
                return $order->fresh(['transactionAssigner']);
            }

            throw ValidationException::withMessages([
                'transaction_id' => 'This order is already completed and locked.',
            ]);
        }

        if ($order->isInquiryOrder()) {
            throw ValidationException::withMessages([
                'transaction_id' => 'Inquiry cases cannot be assigned a service reference.',
            ]);
        }

        $transactionId = trim($transactionId);

        if ($transactionId === '') {
            throw ValidationException::withMessages([
                'transaction_id' => 'Transaction ID is required.',
            ]);
        }

        $this->customerVerificationService->assertCanCompleteService($order, $actor);
        $this->assertNoActiveBusinessHoldOnOrder($order);

        $this->dashboardBroadcastService->beginKpiCoalesce($actor);

        try {
            $freshOrder = DB::transaction(function () use ($order, $transactionId, $actor, $broadcast): Order {
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

                app(TeamMemberActivityService::class)
                    ->recordCaseAction($actor);

                $this->scheduleServiceReferenceAssignedCommunication($freshOrder, $transactionId, $actor);

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

                // Close without per-case dashboard fan-out; a single post-commit
                // transactionAssigned broadcast + coalesced KPI refresh follows.
                $this->serviceCaseStatusService->closeActiveServiceCasesForOrder(
                    order: $freshOrder,
                    actor: $actor,
                    broadcast: false,
                );

                $orderId = $freshOrder->id;
                $actorId = $actor->id;

                DB::afterCommit(function () use ($orderId, $transactionId, $actorId, $broadcast): void {
                    $committedOrder = Order::query()
                        ->with(['transactionAssigner'])
                        ->find($orderId);
                    $committedActor = User::query()->find($actorId);

                    if ($committedOrder === null || $committedActor === null) {
                        $this->dashboardBroadcastService->cancelKpiCoalesce();

                        return;
                    }

                    $this->notifyTransactionCompleted($committedOrder, $transactionId, $committedActor);

                    if ($broadcast) {
                        Incident::query()
                            ->with(['order.transactionAssigner', 'creator', 'assignee'])
                            ->where('order_id', $committedOrder->id)
                            ->get()
                            ->each(fn (Incident $incident) => $this->dashboardBroadcastService->transactionAssigned(
                                $incident,
                                $committedActor,
                            ));
                    }

                    $this->dashboardBroadcastService->flushKpiCoalesce();
                });

                return $freshOrder;
            });
        } catch (Throwable $exception) {
            $this->dashboardBroadcastService->cancelKpiCoalesce();

            throw $exception;
        }

        return $freshOrder;
    }

    private function scheduleServiceReferenceAssignedCommunication(
        Order $order,
        string $serviceReference,
        User $actor,
    ): void {
        $orderId = $order->id;
        $actorId = $actor->id;

        DB::afterCommit(function () use ($orderId, $serviceReference, $actorId): void {
            SendServiceReferenceDriverGuideJob::dispatch($orderId, $serviceReference, $actorId)
                ->afterCommit();
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
     *     batch_id: string,
     *     rows: array<int, array{incident_id: int, html: string}>,
     *     succeeded_incident_ids: list<int>,
     *     failed_incidents: list<array{incident_id: int, message: string}>,
     *     post_processing_warnings: list<string>
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

        /** @var array<int, string> $orderFailureMessages */
        $orderFailureMessages = [];

        $incidentsByOrderId = $pendingIncidents->groupBy(
            fn (Incident $incident): int => (int) $incident->order_id,
        );

        $batchId = (string) Str::uuid();
        $batchStartedAt = now();
        $batchTimerStartedAt = microtime(true);

        Log::info('bulk_assign.batch.started', [
            'batch_id' => $batchId,
            'total_orders' => count($ordersToUpdate),
            'started_at' => $batchStartedAt->toIso8601String(),
        ]);

        foreach ($ordersToUpdate as $orderId) {
            $order = Order::query()->find($orderId);

            if ($order === null || ! $actor->can('assignTransaction', $order)) {
                continue;
            }

            $primaryIncident = $incidentsByOrderId->get($orderId)?->first();

            Log::info('bulk_assign.order.started', [
                'batch_id' => $batchId,
                'order_id' => $order->order_id,
                'case_id' => $primaryIncident?->id,
                'reference_number' => $primaryIncident?->reference_no,
            ]);

            try {
                $this->assignTransactionId(
                    $order,
                    $transactionId,
                    $actor,
                    broadcast: false,
                );

                Log::info('bulk_assign.order.committed', [
                    'batch_id' => $batchId,
                    'order_id' => $order->order_id,
                    'case_id' => $primaryIncident?->id,
                    'reference_number' => $primaryIncident?->reference_no,
                ]);
            } catch (Throwable $exception) {
                $failureMessage = $exception instanceof ValidationException
                    ? (string) (collect($exception->errors())->flatten()->first() ?? $exception->getMessage())
                    : $exception->getMessage();

                $orderFailureMessages[$orderId] = $failureMessage;

                Log::error('bulk_assign.order.failed', [
                    'batch_id' => $batchId,
                    'order_id' => $order->order_id,
                    'case_id' => $primaryIncident?->id,
                    'reference_number' => $primaryIncident?->reference_no,
                    'exception' => $exception::class,
                    'message' => $failureMessage,
                    'stack_trace' => $exception->getTraceAsString(),
                ]);
            }
        }

        Log::info('bulk_assign.batch.finished', [
            'batch_id' => $batchId,
            'total_orders' => count($ordersToUpdate),
            'started_at' => $batchStartedAt->toIso8601String(),
            'finished_at' => now()->toIso8601String(),
            'duration_ms' => (int) round((microtime(true) - $batchTimerStartedAt) * 1000),
        ]);

        $refreshedIncidents = Incident::query()
            ->with(['order.transactionAssigner', 'creator', 'assignee'])
            ->whereIn('id', $incidentIds)
            ->get()
            ->keyBy('id');

        /** @var list<string> $postProcessingWarnings */
        $postProcessingWarnings = [];

        $rows = [];

        foreach ($incidentIds as $incidentId) {
            $incident = $refreshedIncidents->get($incidentId);

            if ($incident === null) {
                continue;
            }

            try {
                $rows[] = [
                    'incident_id' => $incident->id,
                    'html' => view(
                        'dashboard.partials.service-case-row',
                        $this->dashboardService->serviceCaseRowViewData($incident, $actor),
                    )->render(),
                ];
            } catch (Throwable $exception) {
                $this->logPostProcessingFailure($batchId, 'replace_rows.render', $exception);

                if (! in_array(self::DASHBOARD_REFRESH_WARNING, $postProcessingWarnings, true)) {
                    $postProcessingWarnings[] = self::DASHBOARD_REFRESH_WARNING;
                }
            }
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

            if (! $this->customerVerificationService->canCompleteService($incident->order)) {
                $failedIncidents[] = [
                    'incident_id' => $incidentId,
                    'message' => 'Customer verification required before completing service.',
                ];

                continue;
            }

            if (isset($orderFailureMessages[$incident->order->id])) {
                $failedIncidents[] = [
                    'incident_id' => $incidentId,
                    'message' => $orderFailureMessages[$incident->order->id],
                ];

                continue;
            }

            $failedIncidents[] = [
                'incident_id' => $incidentId,
                'message' => 'Unable to assign transaction ID.',
            ];
        }

        if ($succeededIncidentIds !== []) {
            try {
                $this->dashboardBroadcastService->transactionsAssigned($succeededIncidentIds, $actor);
            } catch (Throwable $exception) {
                $this->logPostProcessingFailure($batchId, 'transactionsAssigned', $exception);

                if (! in_array(self::DASHBOARD_REFRESH_WARNING, $postProcessingWarnings, true)) {
                    $postProcessingWarnings[] = self::DASHBOARD_REFRESH_WARNING;
                }
            }
        }

        return [
            'count' => count($succeededIncidentIds),
            'transaction_id' => $transactionId,
            'batch_id' => $batchId,
            'rows' => $rows,
            'succeeded_incident_ids' => $succeededIncidentIds,
            'failed_incidents' => $failedIncidents,
            'post_processing_warnings' => $postProcessingWarnings,
        ];
    }

    private function logPostProcessingFailure(string $batchId, string $operation, Throwable $exception): void
    {
        Log::error('bulk_assign.post_processing.failed', [
            'batch_id' => $batchId,
            'operation' => $operation,
            'exception' => $exception::class,
            'message' => $exception->getMessage(),
            'stack_trace' => $exception->getTraceAsString(),
        ]);
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

    private function assertNoActiveBusinessHoldOnOrder(Order $order): void
    {
        $order->loadMissing([
            'incidents' => fn ($query) => $query->where('status', '!=', IncidentStatus::Closed),
        ]);

        foreach ($order->incidents as $incident) {
            app(BusinessHoldService::class)->assertOperationsAllowed(
                $incident,
                'assigned a service reference',
            );
        }
    }
}
