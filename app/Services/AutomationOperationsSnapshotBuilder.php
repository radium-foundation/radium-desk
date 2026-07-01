<?php

namespace App\Services;

use App\Data\AutomationOperationsDashboardData;
use App\Data\OrderIdentityValidationAnalysis;
use App\Enums\OrderIdentityValidationFailureGroup;
use App\Enums\ServiceCaseAutomationStatus;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\Cashfree\CashfreeWebhookReliabilityMetrics;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class AutomationOperationsSnapshotBuilder
{
    private const RECENT_EVENT_LIMIT = 100;

    /**
     * @var list<string>
     */
    private const BASE_AUTOMATION_EVENTS = [
        ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED,
        'service_case.automation_pending',
        ServiceCaseAutomationMonitorService::EVENT_RADIUMBOX_VERIFIED,
        ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED,
        ServiceCaseAutomationMonitorService::EVENT_WAITING_MANUAL_CORRECTION,
        'service_case.assigned',
        'service_case.reassigned',
    ];

    public function __construct(
        private readonly ServiceCaseAutomationHealthService $healthService,
        private readonly ServiceCaseAutomationStatusService $statusService,
        private readonly AutomationOperationsValidationCollector $validationCollector,
        private readonly OrderIdentityRepairService $repairService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $syncStore,
        private readonly CashfreeWebhookReliabilityMetrics $cashfreeReliabilityMetrics,
    ) {}

    public function build(): AutomationOperationsDashboardData
    {
        $activeIncidents = $this->healthService->activeIncidents();
        $statusByIncidentId = $this->statusesFor($activeIncidents);
        $analysis = $this->validationCollector->collect($statusByIncidentId);

        return new AutomationOperationsDashboardData(
            healthCounts: array_merge(
                $this->healthService->countsFor($activeIncidents, $statusByIncidentId),
                $this->cashfreeReliabilityMetrics->dashboardCounts(),
            ),
            waitingForCustomerSerialQueue: $this->waitingForCustomerSerialQueue($activeIncidents, $statusByIncidentId),
            duplicateSerialConflicts: $this->duplicateSerialConflicts($analysis->failures),
            radiumBoxNotFoundQueue: $this->radiumBoxNotFoundQueue($analysis->failures),
            recentAutomationEvents: $this->recentAutomationEvents(),
            repairStatistics: $this->repairService->statistics($analysis),
            validationByProduct: $analysis->failuresByProduct,
            validationByValidatorRule: $analysis->failuresByValidatorRule,
            validationByCategory: $analysis->failuresByGroup,
        );
    }

    /**
     * @param  Collection<int, Incident>  $activeIncidents
     * @return array<int, ServiceCaseAutomationStatus>
     */
    private function statusesFor(Collection $activeIncidents): array
    {
        $statusByIncidentId = [];

        foreach ($activeIncidents as $incident) {
            $statusByIncidentId[$incident->id] = $this->statusService->statusFor($incident);
        }

        return $statusByIncidentId;
    }

    /**
     * @param  Collection<int, Incident>  $activeIncidents
     * @param  array<int, ServiceCaseAutomationStatus>  $statusByIncidentId
     * @return list<array<string, mixed>>
     */
    private function waitingForCustomerSerialQueue(
        Collection $activeIncidents,
        array $statusByIncidentId,
    ): array {
        return $activeIncidents
            ->filter(fn (Incident $incident): bool => ($statusByIncidentId[$incident->id] ?? null)
                === ServiceCaseAutomationStatus::WaitingForCustomerSerial)
            ->sortBy(fn (Incident $incident) => $incident->created_at?->timestamp ?? 0)
            ->values()
            ->map(function (Incident $incident): array {
                $order = $incident->order;

                return [
                    'case_reference' => $incident->display_reference,
                    'case_url' => route('incidents.show', $incident),
                    'order_id' => $order?->order_id,
                    'order_url' => $order !== null ? route('orders.show', $order) : null,
                    'customer_name' => $order?->customer_name ?: '—',
                    'product' => $this->productLabel($order?->device_model, $order?->product_name),
                    'agent_name' => $incident->assignee?->name ?? 'Unassigned',
                    'age' => $incident->created_at?->diffForHumans() ?? '—',
                ];
            })
            ->all();
    }

    /**
     * @param  list<OrderIdentityValidationAnalysis>  $failures
     * @return list<array<string, mixed>>
     */
    private function duplicateSerialConflicts(array $failures): array
    {
        $duplicateFailures = array_values(array_filter(
            $failures,
            fn (OrderIdentityValidationAnalysis $failure): bool => $failure->failureGroup
                === OrderIdentityValidationFailureGroup::DuplicateSerial,
        ));

        if ($duplicateFailures === []) {
            return [];
        }

        $serials = collect($duplicateFailures)
            ->pluck('serialNumber')
            ->map(fn (?string $serial): string => trim((string) $serial))
            ->filter()
            ->unique()
            ->values();

        $ordersBySerial = Order::query()
            ->whereIn('serial_number', $serials)
            ->get(['id', 'order_id', 'serial_number'])
            ->groupBy(fn (Order $order): string => strtoupper(trim((string) $order->serial_number)));

        $rows = [];

        foreach ($duplicateFailures as $failure) {
            $serial = trim((string) $failure->serialNumber);
            $matchingOrders = $ordersBySerial->get(strtoupper($serial), collect());

            $conflictingOrder = $matchingOrders
                ->first(fn (Order $order): bool => $order->id !== $failure->internalId);

            $rows[] = [
                'serial' => $serial,
                'current_order_id' => $failure->externalOrderId,
                'current_order_url' => route('orders.show', $failure->internalId),
                'conflicting_order_id' => $conflictingOrder?->order_id,
                'conflicting_order_url' => $conflictingOrder !== null
                    ? route('orders.show', $conflictingOrder)
                    : null,
                'product' => $this->productLabel($failure->deviceModel, $failure->productName),
            ];
        }

        return $rows;
    }

    /**
     * @param  list<OrderIdentityValidationAnalysis>  $failures
     * @return list<array<string, mixed>>
     */
    private function radiumBoxNotFoundQueue(array $failures): array
    {
        $filtered = array_values(array_filter(
            $failures,
            fn (OrderIdentityValidationAnalysis $failure): bool => $failure->failureGroup
                === OrderIdentityValidationFailureGroup::RadiumBoxNotFound,
        ));

        if ($filtered === []) {
            return [];
        }

        $orders = Order::query()
            ->whereIn('id', collect($filtered)->pluck('internalId'))
            ->get(['id', 'customer_name'])
            ->keyBy('id');

        return array_map(
            function (OrderIdentityValidationAnalysis $failure) use ($orders): array {
                $order = $orders->get($failure->internalId);
                $lastAttempt = $this->syncStore->lastAttemptAt($failure->internalId);

                return [
                    'order_id' => $failure->externalOrderId,
                    'order_url' => route('orders.show', $failure->internalId),
                    'customer_name' => $order?->customer_name ?: '—',
                    'product' => $this->productLabel($failure->deviceModel, $failure->productName),
                    'last_attempt' => $lastAttempt !== null
                        ? display_app_datetime(Carbon::parse($lastAttempt))
                        : '—',
                    'failure_reason' => $failure->failureReason ?? 'Order was not found in RadiumBox.',
                ];
            },
            $filtered,
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentAutomationEvents(): array
    {
        $candidateLogs = AuditLog::query()
            ->whereIn('event', self::BASE_AUTOMATION_EVENTS)
            ->latest('created_at')
            ->limit(250)
            ->get();

        $assigneeIds = $candidateLogs
            ->map(fn (AuditLog $log): mixed => $log->new_values['assigned_to_user_id'] ?? null)
            ->filter()
            ->unique()
            ->values();

        $assignees = User::query()
            ->with('roles')
            ->whereIn('id', $assigneeIds)
            ->get()
            ->keyBy('id');

        $incidentIds = $candidateLogs
            ->filter(fn (AuditLog $log): bool => $log->auditable_type === (new Incident)->getMorphClass())
            ->pluck('auditable_id')
            ->unique()
            ->values();

        $incidents = Incident::query()
            ->with('order')
            ->whereIn('id', $incidentIds)
            ->get()
            ->keyBy('id');

        return $candidateLogs
            ->filter(fn (AuditLog $log): bool => $this->isDashboardAutomationEvent($log, $assignees))
            ->take(self::RECENT_EVENT_LIMIT)
            ->map(function (AuditLog $log) use ($incidents): array {
                $incident = $log->auditable_type === (new Incident)->getMorphClass()
                    ? $incidents->get($log->auditable_id)
                    : null;

                return [
                    'occurred_at' => $log->created_at,
                    'label' => $this->automationEventLabel($log),
                    'case_reference' => $incident?->display_reference,
                    'case_url' => $incident !== null ? route('incidents.show', $incident) : null,
                    'order_id' => $incident?->order?->order_id,
                    'order_url' => $incident?->order !== null
                        ? route('orders.show', $incident->order)
                        : null,
                ];
            })
            ->values()
            ->all();
    }

    /**
     * @param  Collection<int, User>  $assignees
     */
    private function isDashboardAutomationEvent(AuditLog $log, Collection $assignees): bool
    {
        return match ($log->event) {
            'service_case.assigned' => $this->isRoundRobinAssignment($log, $assignees),
            'service_case.reassigned' => ($log->new_values['reason'] ?? null)
                === ServiceCaseAssignmentEligibilityService::AUTOMATIC_REASSIGNMENT_REASON,
            default => true,
        };
    }

    /**
     * @param  Collection<int, User>  $assignees
     */
    private function isRoundRobinAssignment(AuditLog $log, Collection $assignees): bool
    {
        $assigneeId = $log->new_values['assigned_to_user_id'] ?? null;

        if ($assigneeId === null) {
            return false;
        }

        $assignee = $assignees->get((int) $assigneeId);

        if ($assignee === null) {
            return false;
        }

        return $assignee->hasRole(RolePermissionSeeder::ROLE_AGENT)
            && ! $assignee->hasAnyRole([
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ]);
    }

    private function automationEventLabel(AuditLog $log): string
    {
        return match ($log->event) {
            ServiceCaseAutomationMonitorService::EVENT_PAYMENT_RECEIVED => 'Payment received',
            'service_case.automation_pending' => 'Automation pending',
            ServiceCaseAutomationMonitorService::EVENT_RADIUMBOX_VERIFIED => 'RadiumBox synced',
            ServiceCaseAutomationMonitorService::EVENT_VALIDATION_PASSED => 'Validation passed',
            'service_case.assigned' => 'Round Robin assignment',
            'service_case.reassigned' => 'Shift Admin reassignment',
            ServiceCaseAutomationMonitorService::EVENT_WAITING_MANUAL_CORRECTION => 'Waiting for customer serial',
            default => str_replace(['service_case.', 'automation.', '_'], ['', '', ' '], $log->event),
        };
    }

    private function productLabel(?string $deviceModel, ?string $productName): string
    {
        if (filled(trim((string) $deviceModel))) {
            return trim((string) $deviceModel);
        }

        if (filled(trim((string) $productName))) {
            return trim((string) $productName);
        }

        return 'Unknown';
    }
}
