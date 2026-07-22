<?php

namespace App\Services;

use App\Enums\AssignmentOrigin;
use App\Enums\IncidentStatus;
use App\Enums\OperationQueue;
use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ServiceCaseAssignedNotification;
use App\Notifications\ServiceCaseReassignedNotification;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\OperationsAssignmentEligibilityService;
use App\Services\Operations\OperationsQueueClassifier;
use App\Services\Operations\OperationsRoleService;
use App\Support\Operations\AppointmentReminderMessageContext;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class ServiceCaseAssignmentService
{
    private const ROUND_ROBIN_CURSOR_KEY = 'assignment.agent_round_robin_last_user_id';

    public function __construct(
        private readonly AuditLogService $auditLogService,
        private readonly SettingService $settingService,
        private readonly DashboardBroadcastService $dashboardBroadcastService,
        private readonly ServiceCaseOrderAssignmentRoutingService $orderRoutingService,
        private readonly OperationsAssignmentEligibilityService $assignmentEligibilityService,
        private readonly OperationsRoleService $operationsRoleService,
        private readonly DashboardSnapshotStore $dashboardSnapshotStore,
    ) {}

    public function resolveAssignee(?Carbon $at = null): User
    {
        $assignee = $this->resolveAssigneeOrNull($at);

        if ($assignee === null) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'No valid admin assignee is available for service case assignment.',
            ]);
        }

        return $assignee;
    }

    public function resolveAssigneeOrNull(?Carbon $at = null): ?User
    {
        foreach ($this->assigneeCandidateUserIds($at) as $userId) {
            $assignee = $this->findValidAdminAssigneeById($userId);

            if ($assignee !== null) {
                return $assignee;
            }
        }

        return null;
    }

    /**
     * @return list<int>
     */
    public function assigneeCandidateUserIds(?Carbon $at = null): array
    {
        $primary = $this->resolvePrimaryAssigneeUserId($at);
        $fallbacks = array_filter([
            $this->settingService->getInt('assignment.fallback_admin_1_user_id'),
            $this->settingService->getInt('assignment.fallback_admin_2_user_id'),
        ]);

        return array_values(array_unique(array_filter(array_merge([$primary], $fallbacks))));
    }

    public function assignOnCreate(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        if ($incident->assigned_to_user_id !== null) {
            return $incident->fresh(['assignee']);
        }

        $routed = $this->tryAssignViaOrderRouting($incident, $actor, $at);

        if ($routed !== null) {
            return $routed;
        }

        if (config('service_case_assignment.automation_grace_period_enabled', true)) {
            return app(ServiceCaseAutomationGraceService::class)->beginGracePeriod($incident, $actor, $at);
        }

        return $this->assignImmediatelyOnCreate($incident, $actor, $at);
    }

    public function assignToShiftAdminAfterValidation(
        Incident $incident,
        User $actor,
        ?Carbon $at = null,
    ): Incident {
        $incident = $incident->fresh(['assignee', 'supportAppointments', 'order']);

        if ($incident->hasActiveSupportAppointment()) {
            return app(\App\Services\Operations\SupportAppointmentSmartAssignmentService::class)
                ->assignForActiveSupport($incident, $actor);
        }

        if ($incident->order?->isInquiryOrder()) {
            return $this->assignInquiryViaRoundRobin($incident, $actor, $at);
        }

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        $routed = $this->tryAssignViaOrderRouting($incident, $actor, $at);

        if ($routed !== null) {
            return $routed;
        }

        $assignee = $this->resolveAssigneeOrNull($at);

        if ($assignee === null) {
            return $incident;
        }

        return $this->applyAssignment(
            incident: $this->clearAutomationPending($incident, $actor),
            assignee: $assignee,
            actor: $actor,
            event: 'service_case.assigned',
            extraNewValues: [
                ...$this->shiftAdminOverrideContext(),
            ],
        );
    }

    public function assignViaRoundRobinAfterGracePeriod(Incident $incident, User $actor): Incident
    {
        $incident = $incident->fresh(['assignee', 'order']);

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        $incident = $this->clearAutomationPending($incident, $actor);

        $routed = $this->tryAssignViaOrderRouting($incident, $actor);

        if ($routed !== null) {
            return $routed;
        }

        if (! config('service_case_assignment.round_robin_enabled', true)) {
            if ($incident->order?->isInquiryOrder()) {
                return $this->logUnassignedAfterGracePeriod($incident, $actor);
            }

            return $this->applyAssignment(
                incident: $incident,
                assignee: $this->resolveAssignee(),
                actor: $actor,
                event: 'service_case.assigned',
                extraNewValues: [
                    ...$this->shiftAdminOverrideContext(),
                ],
            );
        }

        $assignee = $this->resolveAgentRoundRobin(null, $incident->order);

        if ($assignee === null) {
            return $this->logUnassignedAfterGracePeriod($incident, $actor);
        }

        return $this->applyAssignment(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            event: 'service_case.assigned',
        );
    }

    private function assignImmediatelyOnCreate(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        if (! config('service_case_assignment.round_robin_enabled', true)) {
            if ($incident->order?->isInquiryOrder()) {
                return $this->logUnassignedOnCreate($incident, $actor);
            }

            return $this->applyAssignment(
                incident: $incident,
                assignee: $this->resolveAssignee($at),
                actor: $actor,
                event: 'service_case.assigned',
                extraNewValues: [
                    ...$this->shiftAdminOverrideContext(),
                ],
            );
        }

        $assignee = $this->resolveAgentRoundRobin($at, $incident->order);

        if ($assignee === null) {
            return $this->logUnassignedOnCreate($incident, $actor);
        }

        return $this->applyAssignment(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            event: 'service_case.assigned',
        );
    }

    public function assignInquiryViaRoundRobin(
        Incident $incident,
        User $actor,
        ?Carbon $at = null,
    ): Incident {
        $incident = $incident->fresh(['assignee', 'order']);

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        if (! $incident->order?->isInquiryOrder()) {
            return $incident;
        }

        if (! config('service_case_assignment.round_robin_enabled', true)) {
            return $this->logUnassignedOnCreate($incident, $actor);
        }

        $assignee = $this->resolveAgentRoundRobin($at, $incident->order);

        if ($assignee === null) {
            return $this->logUnassignedOnCreate($incident, $actor);
        }

        return $this->applyAssignment(
            incident: $this->clearAutomationPending($incident, $actor),
            assignee: $assignee,
            actor: $actor,
            event: 'service_case.assigned',
            extraNewValues: [
                'assignment_method' => 'inquiry_round_robin',
            ],
        );
    }

    public function clearAutomationPending(Incident $incident, User $actor): Incident
    {
        if ($incident->automation_pending_until === null) {
            return $incident;
        }

        $incident->update([
            'automation_pending_until' => null,
            'updated_by' => $actor->id,
        ]);

        return $incident->fresh(['assignee', 'order']);
    }

    private function logUnassignedAfterGracePeriod(Incident $incident, User $actor): Incident
    {
        $incident = $this->clearAutomationPending($incident, $actor);

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'service_case.unassigned',
            auditable: $incident,
            oldValues: [
                'assigned_to_user_id' => null,
            ],
            newValues: [
                'assigned_to_user_id' => null,
                'reason' => 'no_active_support_agents',
            ],
        );

        return $incident->fresh(['assignee']);
    }

    public function reassignToSupportAgentViaRoundRobin(
        Incident $incident,
        User $actor,
        ?Carbon $at = null,
    ): Incident {
        $incident = $incident->fresh(['assignee', 'order']);

        $currentAssignee = $incident->assignee;

        if ($currentAssignee !== null && $this->shouldRetainOperationalAssignee($incident)) {
            return $incident;
        }

        if ($currentAssignee !== null && $this->orderRoutingService->isDesignatedAssignee($incident, $currentAssignee)) {
            return $incident;
        }

        if (! config('service_case_assignment.round_robin_enabled', true)) {
            return $incident;
        }

        $assignee = $this->resolveAgentRoundRobin($at, $incident->order);

        if ($assignee === null) {
            return $incident;
        }

        return $this->applyAssignment(
            incident: $this->clearAutomationPending($incident, $actor),
            assignee: $assignee,
            actor: $actor,
            event: 'service_case.reassigned',
            extraNewValues: [
                'reason' => 'validation_failed_support_queue',
            ],
        );
    }

    public function shouldRetainOperationalAssignee(Incident $incident): bool
    {
        $assignee = $incident->assignee;

        if ($assignee === null) {
            return false;
        }

        if ($this->isSupportAgent($assignee)) {
            return true;
        }

        return $this->hasActiveEscalationOwnership($incident);
    }

    public function hasActiveEscalationOwnership(Incident $incident): bool
    {
        $assignee = $incident->assignee;

        if ($assignee === null || ! $assignee->hasRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST)) {
            return false;
        }

        $lastClosedAt = AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.status_changed')
            ->orderByDesc('created_at')
            ->get()
            ->first(fn (AuditLog $log): bool => ($log->new_values['status'] ?? null) === IncidentStatus::Closed->value)
            ?->created_at;

        return AuditLog::query()
            ->where('auditable_type', $incident->getMorphClass())
            ->where('auditable_id', $incident->id)
            ->where('event', 'service_case.escalated')
            ->orderByDesc('created_at')
            ->get()
            ->contains(function (AuditLog $log) use ($assignee, $lastClosedAt): bool {
                if ((int) ($log->new_values['assigned_to_user_id'] ?? 0) !== (int) $assignee->id) {
                    return false;
                }

                if ($lastClosedAt === null) {
                    return true;
                }

                return $log->created_at !== null && $log->created_at->greaterThan($lastClosedAt);
            });
    }

    public function reassign(Incident $incident, User $assignee, User $actor): Incident
    {
        $this->ensureValidAssignee($assignee);

        return $this->applyAssignment(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            event: 'service_case.reassigned',
            assignmentOrigin: AssignmentOrigin::Manual,
            extraNewValues: [
                'assignment_override' => true,
                'override_reason' => 'manual_reassign',
            ],
        );
    }

    public function escalate(Incident $incident, User $assignee, User $actor, string $reason): Incident
    {
        $this->ensureValidAssignee($assignee);

        if (! $assignee->hasRole(RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST)) {
            throw ValidationException::withMessages([
                'action_type' => 'Escalation must be routed to an escalation specialist.',
            ]);
        }

        $previousAssigneeId = $incident->assigned_to_user_id;

        return $this->applyAssignment(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            event: 'service_case.escalated',
            assignmentOrigin: AssignmentOrigin::Manual,
            extraNewValues: [
                'reason' => $reason,
                'previous_assigned_to_user_id' => $previousAssigneeId,
                'escalation_level' => 1,
            ],
        );
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    public function assignWithAuditContext(
        Incident $incident,
        User $assignee,
        User $actor,
        array $auditContext,
        string $event = 'service_case.assigned',
        AssignmentOrigin $assignmentOrigin = AssignmentOrigin::Auto,
    ): Incident {
        $this->ensureValidAssignee($assignee);

        return $this->applyAssignment(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            event: $event,
            extraNewValues: $auditContext,
            assignmentOrigin: $assignmentOrigin,
        );
    }

    /**
     * @param  array<string, mixed>  $auditContext
     */
    public function clearAssigneeForPendingSmartAssignment(
        Incident $incident,
        User $actor,
        array $auditContext = [],
    ): Incident {
        if ($incident->assigned_to_user_id === null) {
            return $incident;
        }

        $oldAssigneeId = $incident->assigned_to_user_id;

        $incident->update([
            'assigned_to_user_id' => null,
            'updated_by' => $actor->id,
        ]);

        $freshIncident = $incident->fresh(['assignee']);

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'service_case.unassigned',
            auditable: $freshIncident,
            oldValues: [
                'assigned_to_user_id' => $oldAssigneeId,
            ],
            newValues: [
                'assigned_to_user_id' => null,
                ...$auditContext,
            ],
        );

        return $freshIncident;
    }

    public function applySupportAssignment(
        Incident $incident,
        User $assignee,
        User $actor,
        string $event = 'service_case.assigned',
        array $extraNewValues = [],
    ): Incident {
        $this->ensureValidAssignee($assignee);

        return $this->applyAssignment(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            event: $event,
            extraNewValues: $extraNewValues,
        );
    }

    public function reassignToShiftAdminAfterValidation(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        $incident = $incident->fresh(['assignee', 'order']);

        if ($incident->order?->isInquiryOrder()) {
            return $incident;
        }

        $currentAssignee = $incident->assignee;

        if ($currentAssignee === null || ! $this->isSupportAgent($currentAssignee)) {
            return $incident;
        }

        if ($this->hasManualSupportOwnership($incident)) {
            return $incident;
        }

        $assignee = $this->resolveAssigneeForIncident($incident, $at);

        if ($assignee === null) {
            return $incident;
        }

        if ($incident->assigned_to_user_id === $assignee->id) {
            return $incident;
        }

        return $this->applyAssignment(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            event: 'service_case.reassigned',
            extraNewValues: [
                'reason' => ServiceCaseAssignmentEligibilityService::AUTOMATIC_REASSIGNMENT_REASON,
                ...$this->shiftAdminOverrideContext(),
            ],
        );
    }

    private function tryAssignViaOrderRouting(Incident $incident, User $actor, ?Carbon $at = null): ?Incident
    {
        $incident = $incident->fresh(['order', 'assignee']);
        $assignee = $this->orderRoutingService->resolveAssignee($incident);

        if ($assignee === null) {
            return null;
        }

        return $this->applyAssignment(
            incident: $this->clearAutomationPending($incident, $actor),
            assignee: $assignee,
            actor: $actor,
            event: 'service_case.assigned',
            extraNewValues: [
                'assignment_method' => 'order_routing',
                'assignment_rule' => 'hardware_order',
                'order_id' => $incident->order?->order_id,
                'assignment_override' => true,
                'override_reason' => 'hardware_routing',
            ],
        );
    }

    private function resolveAssigneeForIncident(Incident $incident, ?Carbon $at = null): ?User
    {
        $incident = $incident->fresh(['order']);

        return $this->orderRoutingService->resolveAssignee($incident)
            ?? $this->resolveAssigneeOrNull($at);
    }

    /**
     * Manual assignment dropdown candidates.
     *
     * Auto-assignment pools are unchanged and still exclude escalation_specialist.
     *
     * @return list<User>
     */
    public function reassignableUsers(?User $actor = null): array
    {
        $actor ??= auth()->user();
        $automationIdentity = app(AutomationIdentityService::class);

        return User::query()
            ->where('is_active', true)
            ->role([
                RolePermissionSeeder::ROLE_AGENT,
                RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
                RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
                RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST,
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
            ])
            ->orderBy('name')
            ->get()
            ->reject(function (User $user) use ($actor, $automationIdentity): bool {
                if ($actor !== null && $user->id === $actor->id) {
                    return true;
                }

                if ($user->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
                    return true;
                }

                return $automationIdentity->isExcludedFromManualAssignment($user);
            })
            ->values()
            ->all();
    }

    /**
     * @return list<User>
     */
    public function reassignableAdmins(): array
    {
        return $this->reassignableUsers();
    }

    /**
     * @return list<User>
     */
    public function activeSupportAgents(?Carbon $at = null, ?Order $order = null): array
    {
        $at ??= now();

        $roles = ($order !== null && $order->isInquiryOrder())
            ? RolePermissionSeeder::INQUIRY_ASSIGNMENT_ROLES
            : RolePermissionSeeder::SUPPORT_TEAM_ROLES;

        return User::query()
            ->where('is_active', true)
            ->role($roles)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $agent): bool => $this->operationsRoleService->isNormalAssignmentPool($agent)
                && $this->assignmentEligibilityService->isEligible($agent, $at))
            ->values()
            ->all();
    }

    private function resolveAgentRoundRobin(?Carbon $at = null, ?Order $order = null): ?User
    {
        return $this->resolveSupportAgentViaRoundRobin($at, $order);
    }

    public function resolveSupportAgentViaRoundRobin(?Carbon $at = null, ?Order $order = null): ?User
    {
        return DB::transaction(function () use ($at, $order): ?User {
            $agents = $this->activeSupportAgents($at, $order);

            if ($agents === []) {
                return null;
            }

            Setting::query()->firstOrCreate(
                ['key' => self::ROUND_ROBIN_CURSOR_KEY],
                ['value' => '0'],
            );

            $cursorRow = Setting::query()
                ->where('key', self::ROUND_ROBIN_CURSOR_KEY)
                ->lockForUpdate()
                ->firstOrFail();

            $lastUserId = (int) ($cursorRow->value ?? 0);
            $nextAgent = null;

            foreach ($agents as $agent) {
                if ($agent->id > $lastUserId) {
                    $nextAgent = $agent;
                    break;
                }
            }

            if ($nextAgent === null) {
                $nextAgent = $agents[0];
            }

            $cursorRow->update(['value' => (string) $nextAgent->id]);
            $this->settingService->forget();

            return $nextAgent;
        });
    }

    private function logUnassignedOnCreate(Incident $incident, User $actor): Incident
    {
        $this->auditLogService->log(
            userId: $actor->id,
            event: 'service_case.unassigned',
            auditable: $incident,
            oldValues: [
                'assigned_to_user_id' => null,
            ],
            newValues: [
                'assigned_to_user_id' => null,
                'reason' => 'no_active_support_agents',
            ],
        );

        return $incident->fresh(['assignee']);
    }

    private function resolvePrimaryAssigneeUserId(?Carbon $at = null): int
    {
        $at = $this->normalizeTime($at ?? now());
        $time = $at->format('H:i');
        $start = $this->settingService->get('assignment.day_shift_start', '09:00');
        $end = $this->settingService->get('assignment.day_shift_end', '18:30');

        if ($time >= $start && $time <= $end) {
            return $this->settingService->getInt('assignment.day_shift_admin_user_id');
        }

        return $this->settingService->getInt('assignment.night_shift_admin_user_id');
    }

    private function applyAssignment(
        Incident $incident,
        User $assignee,
        User $actor,
        string $event,
        array $extraNewValues = [],
        AssignmentOrigin $assignmentOrigin = AssignmentOrigin::Auto,
    ): Incident {
        if ($incident->status === IncidentStatus::Closed) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'Closed service cases cannot be reassigned.',
            ]);
        }

        if ($incident->assigned_to_user_id === $assignee->id
            && $incident->assignment_origin === $assignmentOrigin) {
            if ($assignmentOrigin === AssignmentOrigin::Manual && $incident->pending_smart_assignment) {
                $incident->update([
                    'pending_smart_assignment' => false,
                    'updated_by' => $actor->id,
                ]);
            }

            return $incident->fresh(['assignee']);
        }

        return DB::transaction(function () use ($incident, $assignee, $actor, $event, $extraNewValues, $assignmentOrigin): Incident {
            $oldValues = [
                'assigned_to_user_id' => $incident->assigned_to_user_id,
                'assignment_origin' => $incident->assignment_origin?->value,
            ];

            $updates = [
                'assigned_to_user_id' => $assignee->id,
                'assignment_origin' => $assignmentOrigin->value,
                'updated_by' => $actor->id,
            ];

            // Manual ownership leaves the deferred smart-assignment queue.
            if ($assignmentOrigin === AssignmentOrigin::Manual && $incident->pending_smart_assignment) {
                $updates['pending_smart_assignment'] = false;
            }

            $incident->update($updates);

            $freshIncident = $incident->fresh(['assignee']);

            $this->auditLogService->log(
                userId: $actor->id,
                event: $event,
                auditable: $freshIncident,
                oldValues: $oldValues,
                newValues: [
                    'assigned_to_user_id' => $freshIncident->assigned_to_user_id,
                    'assignment_origin' => $freshIncident->assignment_origin?->value,
                    ...$extraNewValues,
                ],
            );

            $this->sendAssignmentNotifications(
                incident: $freshIncident,
                assignee: $assignee,
                actor: $actor,
                event: $event,
                extraNewValues: $extraNewValues,
            );

            if (in_array($event, ['service_case.assigned', 'service_case.reassigned', 'service_case.escalated'], true)) {
                $this->dashboardBroadcastService->serviceCaseAssigned($freshIncident, $actor);
            }

            // Automation/IRA actors must not open presence sessions via assignment side-effects.
            if (! app(AutomationIdentityService::class)->isAutomationActor($actor)) {
                app(\App\Services\Operations\TeamMemberActivityService::class)->recordCaseAction($actor);
            }

            app(\App\Services\Operations\TeamMemberActivityService::class)->recordStatusChange($assignee);

            $this->dashboardSnapshotStore->forget();

            return $freshIncident;
        });
    }

    private function sendAssignmentNotifications(
        Incident $incident,
        User $assignee,
        User $actor,
        string $event,
        array $extraNewValues = [],
    ): void {
        if (! $this->settingService->getBool('notifications.assignment_enabled', true)) {
            return;
        }

        if (! $assignee->is_active || $assignee->trashed()) {
            return;
        }

        if ($event === 'service_case.assigned') {
            $assignee->notify(new ServiceCaseAssignedNotification($incident, $actor));
        }

        if (in_array($event, ['service_case.reassigned', 'service_case.escalated'], true)) {
            $assignee->notify(new ServiceCaseReassignedNotification($incident, $actor));
        }

        $this->sendAssignmentTelegramNotification(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            event: $event,
            extraNewValues: $extraNewValues,
        );
    }

    /**
     * @param  array<string, mixed>  $extraNewValues
     */
    private function sendAssignmentTelegramNotification(
        Incident $incident,
        User $assignee,
        User $actor,
        string $event,
        array $extraNewValues = [],
    ): void {
        if (($extraNewValues['assignment_method'] ?? null) === 'smart') {
            return;
        }

        $incident = $incident->loadMissing(['order', 'supportAppointments']);
        $order = $incident->order;
        $appointment = $incident->supportAppointments->sortByDesc('preferred_date')->first();

        $context = [
            'incident_id' => $incident->id,
            'appointment_id' => $appointment?->id,
            'assigned_by' => $this->telegramAssignedByLabel($actor, $extraNewValues),
            'task' => AppointmentReminderMessageContext::appointmentTypeLabel($incident),
        ];

        $communicationService = app(IraCommunicationService::class);

        if (in_array($event, ['service_case.reassigned', 'service_case.escalated'], true)) {
            $communicationService->sendReassignment(
                assignee: $assignee,
                customer: $order?->customer_name ?? 'Unknown',
                device: $order?->device_model ?? $order?->product_name ?? 'Unknown',
                time: $appointment?->preferred_time_slot?->label() ?? 'Unscheduled',
                caseReference: $incident->reference_no,
                context: $context,
            );

            return;
        }

        if ($event === 'service_case.assigned') {
            $communicationService->sendManualAssignment(
                assignee: $assignee,
                customer: $order?->customer_name ?? 'Unknown',
                device: $order?->device_model ?? $order?->product_name ?? 'Unknown',
                time: $appointment?->preferred_time_slot?->label() ?? 'Unscheduled',
                caseReference: $incident->reference_no,
                context: $context,
            );
        }
    }

    /**
     * @param  array<string, mixed>  $extraNewValues
     */
    private function telegramAssignedByLabel(User $actor, array $extraNewValues): string
    {
        if (($extraNewValues['assignment_method'] ?? null) === 'smart'
            || app(AutomationIdentityService::class)->isAutomationActor($actor)) {
            return 'IRA';
        }

        $actor->loadMissing('roles');

        $name = $actor->firstName() ?: trim((string) $actor->name);
        $role = $actor->primaryRoleLabel();

        if ($name !== '' && $role !== '') {
            return "{$name} ({$role})";
        }

        return $name !== '' ? $name : 'Unknown';
    }

    private function ensureValidAssignee(User $assignee): void
    {
        if ($assignee->trashed() || ! $assignee->is_active || ! $assignee->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
            RolePermissionSeeder::ROLE_AGENT,
            RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST,
            RolePermissionSeeder::ROLE_CUSTOMER_COORDINATOR,
            RolePermissionSeeder::ROLE_ESCALATION_SPECIALIST,
            RolePermissionSeeder::ROLE_HARDWARE_TEAM,
        ])) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'The selected user must be an active assignable teammate.',
            ]);
        }

        // Superadmin may also hold admin; never allow as a service-case assignee.
        if ($assignee->hasRole(RolePermissionSeeder::ROLE_SUPERADMIN)) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'Superadmin users cannot be assigned service cases.',
            ]);
        }

        $systemEmail = (string) config('cashfree.system_user_email');

        if ($systemEmail !== '' && strcasecmp($assignee->email, $systemEmail) === 0) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'System users cannot be assigned service cases.',
            ]);
        }
    }

    public function isSupportAgent(User $user): bool
    {
        return $user->hasRole(RolePermissionSeeder::ROLE_AGENT)
            && ! $user->hasAnyRole([
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
            ]);
    }

    public function isShiftAdmin(User $user): bool
    {
        return $user->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ]);
    }

    public function hasManualSupportOwnership(Incident $incident): bool
    {
        if ($incident->assignment_origin !== AssignmentOrigin::Manual) {
            return false;
        }

        $assignee = $incident->assignee;

        if ($assignee === null) {
            return false;
        }

        return $this->operationsRoleService->usesSupportQueues($assignee);
    }

    public function isVisibleInAdminReadyQueue(Incident $incident): bool
    {
        return ! $this->hasManualSupportOwnership($incident);
    }

    public function shouldRemoveFromAdminReadyQueue(Incident $incident): bool
    {
        $incident = $incident->loadMissing([
            'order',
            'assignee.roles',
            'activeWaitingState',
            'supportAppointments',
        ]);

        if (app(OperationsQueueClassifier::class)->classify($incident) !== OperationQueue::ActionRequired) {
            return true;
        }

        return ! $this->isVisibleInAdminReadyQueue($incident);
    }

    /**
     * @param  list<int>  $incidentIds
     * @return list<array{incident_id: int}>
     */
    public function adminReadyQueueRemoveRowsForIncidents(array $incidentIds): array
    {
        if ($incidentIds === []) {
            return [];
        }

        $removeRows = [];

        Incident::query()
            ->with(['order.transactionAssigner', 'creator', 'assignee.roles', 'activeWaitingState', 'supportAppointments'])
            ->whereIn('id', $incidentIds)
            ->orderBy('id')
            ->get()
            ->each(function (Incident $incident) use (&$removeRows): void {
                if ($this->shouldRemoveFromAdminReadyQueue($incident)) {
                    $removeRows[] = [
                        'incident_id' => $incident->id,
                    ];
                }
            });

        return $removeRows;
    }

    private function findValidAdminAssigneeById(int $userId): ?User
    {
        if ($userId <= 0) {
            return null;
        }

        $assignee = User::query()->find($userId);

        if ($assignee === null || $assignee->trashed() || ! $assignee->is_active) {
            return null;
        }

        if (! $assignee->hasRole(RolePermissionSeeder::ROLE_ADMIN)) {
            return null;
        }

        return $assignee;
    }

    private function normalizeTime(Carbon $at): Carbon
    {
        return $at->copy()->timezone($this->settingService->get('assignment.timezone', config('app.timezone')));
    }

    /**
     * @return array{assignment_override: true, override_reason: 'shift_admin'}
     */
    private function shiftAdminOverrideContext(): array
    {
        return [
            'assignment_override' => true,
            'override_reason' => 'shift_admin',
        ];
    }
}
