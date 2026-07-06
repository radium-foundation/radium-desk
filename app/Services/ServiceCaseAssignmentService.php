<?php

namespace App\Services;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Setting;
use App\Models\User;
use App\Notifications\ServiceCaseAssignedNotification;
use App\Notifications\ServiceCaseReassignedNotification;
use App\Services\Operations\IraCommunicationService;
use App\Services\Operations\OperationsAssignmentEligibilityService;
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
        );
    }

    public function assignViaRoundRobinAfterGracePeriod(Incident $incident, User $actor): Incident
    {
        $incident = $incident->fresh(['assignee']);

        if ($incident->assigned_to_user_id !== null) {
            return $incident;
        }

        $incident = $this->clearAutomationPending($incident, $actor);

        $routed = $this->tryAssignViaOrderRouting($incident, $actor);

        if ($routed !== null) {
            return $routed;
        }

        if (! config('service_case_assignment.round_robin_enabled', true)) {
            return $this->applyAssignment(
                incident: $incident,
                assignee: $this->resolveAssignee(),
                actor: $actor,
                event: 'service_case.assigned',
            );
        }

        $assignee = $this->resolveAgentRoundRobin();

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
            return $this->applyAssignment(
                incident: $incident,
                assignee: $this->resolveAssignee($at),
                actor: $actor,
                event: 'service_case.assigned',
            );
        }

        $assignee = $this->resolveAgentRoundRobin($at);

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

    private function clearAutomationPending(Incident $incident, User $actor): Incident
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

        if ($currentAssignee !== null && $this->isSupportAgent($currentAssignee)) {
            return $incident;
        }

        if ($currentAssignee !== null && $this->orderRoutingService->isDesignatedAssignee($incident, $currentAssignee)) {
            return $incident;
        }

        if (! config('service_case_assignment.round_robin_enabled', true)) {
            return $incident;
        }

        $assignee = $this->resolveAgentRoundRobin($at);

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

    public function reassign(Incident $incident, User $assignee, User $actor): Incident
    {
        $this->ensureValidAssignee($assignee);

        return $this->applyAssignment(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            event: 'service_case.reassigned',
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
    ): Incident {
        $this->ensureValidAssignee($assignee);

        return $this->applyAssignment(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            event: $event,
            extraNewValues: $auditContext,
        );
    }

    public function reassignToShiftAdminAfterValidation(Incident $incident, User $actor, ?Carbon $at = null): Incident
    {
        $incident = $incident->fresh(['assignee']);

        $currentAssignee = $incident->assignee;

        if ($currentAssignee === null || ! $this->isSupportAgent($currentAssignee)) {
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
     * @return list<User>
     */
    public function reassignableUsers(): array
    {
        return User::query()
            ->where('is_active', true)
            ->role([
                RolePermissionSeeder::ROLE_ADMIN,
                RolePermissionSeeder::ROLE_SUPERADMIN,
                RolePermissionSeeder::ROLE_AGENT,
            ])
            ->orderBy('name')
            ->get()
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
    public function activeSupportAgents(?Carbon $at = null): array
    {
        $at ??= now();

        return User::query()
            ->where('is_active', true)
            ->role(RolePermissionSeeder::ROLE_AGENT)
            ->orderBy('id')
            ->get()
            ->filter(fn (User $agent): bool => $this->assignmentEligibilityService->isEligible($agent, $at))
            ->values()
            ->all();
    }

    private function resolveAgentRoundRobin(?Carbon $at = null): ?User
    {
        return DB::transaction(function () use ($at): ?User {
            $agents = $this->activeSupportAgents($at);

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
    ): Incident {
        if ($incident->status === IncidentStatus::Closed) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'Closed service cases cannot be reassigned.',
            ]);
        }

        return DB::transaction(function () use ($incident, $assignee, $actor, $event, $extraNewValues): Incident {
            $oldValues = [
                'assigned_to_user_id' => $incident->assigned_to_user_id,
            ];

            $incident->update([
                'assigned_to_user_id' => $assignee->id,
                'updated_by' => $actor->id,
            ]);

            $freshIncident = $incident->fresh(['assignee']);

            $this->auditLogService->log(
                userId: $actor->id,
                event: $event,
                auditable: $freshIncident,
                oldValues: $oldValues,
                newValues: [
                    'assigned_to_user_id' => $freshIncident->assigned_to_user_id,
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

            if ($event === 'service_case.reassigned') {
                $this->dashboardBroadcastService->serviceCaseAssigned($freshIncident, $actor);
            }

            app(\App\Services\Operations\TeamMemberActivityService::class)->recordCaseAction($actor);
            app(\App\Services\Operations\TeamMemberActivityService::class)->recordStatusChange($assignee);

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

        if ($event === 'service_case.reassigned') {
            $assignee->notify(new ServiceCaseReassignedNotification($incident, $actor));
        }

        $this->sendAssignmentTelegramNotification(
            incident: $incident,
            assignee: $assignee,
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
        ];

        $communicationService = app(IraCommunicationService::class);

        if ($event === 'service_case.reassigned') {
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

    private function ensureValidAssignee(User $assignee): void
    {
        if ($assignee->trashed() || ! $assignee->is_active || ! $assignee->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
            RolePermissionSeeder::ROLE_AGENT,
            RolePermissionSeeder::ROLE_HARDWARE_TEAM,
        ])) {
            throw ValidationException::withMessages([
                'assigned_to_user_id' => 'The selected user must be an active admin or agent.',
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

    private function findValidAdminAssigneeById(int $userId): ?User
    {
        if ($userId <= 0) {
            return null;
        }

        $assignee = User::query()->find($userId);

        if ($assignee === null || $assignee->trashed() || ! $assignee->is_active) {
            return null;
        }

        if (! $assignee->hasAnyRole([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ])) {
            return null;
        }

        return $assignee;
    }

    private function normalizeTime(Carbon $at): Carbon
    {
        return $at->copy()->timezone($this->settingService->get('assignment.timezone', config('app.timezone')));
    }
}
