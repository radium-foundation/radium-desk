<?php

namespace App\Services\IncomingEmail;

use App\Enums\Assignment\AssignmentCapability;
use App\Enums\AssignmentOrigin;
use App\Enums\IraMemoryDecisionKind;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\IraMemory;
use App\Models\User;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use App\Support\Assignment\AssignmentCapabilityResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

/**
 * Resilient Sales Lead ownership for inbound email.
 *
 * Sales Queue RR → Sales Admin fallback. Never leaves owner null.
 * When Smart Routing is enabled, IRA Memory / learning assign may override RR.
 */
class IncomingEmailSalesAssignmentService
{
    public const STRATEGY_SALES_QUEUE_ROUND_ROBIN = 'sales_queue_round_robin';

    public const DECISION_IRA_MEMORY = 'ira_memory';

    public const DECISION_SALES_RR = 'sales_rr';

    public const DECISION_SALES_FALLBACK = 'sales_fallback';

    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly AssignmentCapabilityResolver $capabilityResolver,
        private readonly SettingService $settingService,
    ) {}

    /**
     * Assign an unassigned Sales Lead case. Always returns with an owner when
     * any active admin/agent can be resolved.
     *
     * @return array{0: Incident, 1: string, 2: ?int} [incident, assignment_source, round_robin_user_id]
     */
    public function assignSalesLead(
        Incident $incident,
        User $actor,
        ?IncomingEmailMessage $message = null,
        bool $allowIraMemoryOverride = false,
        ?Carbon $at = null,
    ): array {
        $at ??= now();
        $incident = $incident->fresh(['assignee', 'order']);

        if ($incident->assigned_to_user_id !== null) {
            return [$incident, 'existing_owner', null];
        }

        if ($allowIraMemoryOverride && $message instanceof IncomingEmailMessage) {
            $iraOwner = $this->resolveIraMemoryOwner($message);

            if ($iraOwner instanceof User) {
                $assigned = $this->assignWithContext(
                    incident: $incident,
                    assignee: $iraOwner,
                    actor: $actor,
                    decisionSource: self::DECISION_IRA_MEMORY,
                    reason: 'ira_memory_override',
                    fallbackUsed: false,
                    method: 'inbound_email_ira_memory',
                );

                return [$assigned, self::DECISION_IRA_MEMORY, null];
            }
        }

        $rrAssignee = $this->resolveSalesRoundRobinAgent($at);

        if ($rrAssignee instanceof User) {
            $assigned = $this->assignWithContext(
                incident: $incident,
                assignee: $rrAssignee,
                actor: $actor,
                decisionSource: self::DECISION_SALES_RR,
                reason: 'sales_round_robin',
                fallbackUsed: false,
                method: 'inbound_email_sales_round_robin',
            );

            return [$assigned, 'sales_round_robin', $rrAssignee->id];
        }

        $fallback = $this->resolveSalesAdminFallback($at, $actor);

        if ($fallback === null) {
            Log::error('incoming_email.sales_assignment_unresolved', [
                'incident_id' => $incident->id,
                'incoming_email_message_id' => $message?->id,
            ]);

            return [$incident, 'sales_round_robin_unresolved', null];
        }

        $assigned = $this->assignWithContext(
            incident: $incident,
            assignee: $fallback,
            actor: $actor,
            decisionSource: self::DECISION_SALES_FALLBACK,
            reason: 'sales_rr_unavailable',
            fallbackUsed: true,
            method: 'inbound_email_sales_fallback',
            overrideReason: self::DECISION_SALES_FALLBACK,
        );

        return [$assigned, self::DECISION_SALES_FALLBACK, null];
    }

    private function resolveIraMemoryOwner(IncomingEmailMessage $message): ?User
    {
        $candidateIds = array_values(array_filter([
            $message->learning_owner_user_id,
            $message->suggested_assignee_user_id,
            $this->assigneeIdFromMatchedIraMemory($message),
        ], static fn ($id): bool => is_numeric($id) && (int) $id > 0));

        foreach ($candidateIds as $userId) {
            $user = User::query()->find((int) $userId);

            if ($user instanceof User && $user->is_active && ! $user->trashed()) {
                return $user;
            }
        }

        return null;
    }

    private function assigneeIdFromMatchedIraMemory(IncomingEmailMessage $message): ?int
    {
        if ($message->matched_ira_memory_id === null) {
            return null;
        }

        $memory = $message->relationLoaded('matchedIraMemory')
            ? $message->matchedIraMemory
            : IraMemory::query()->find($message->matched_ira_memory_id);

        if (! $memory instanceof IraMemory) {
            return null;
        }

        if ($memory->decision_kind !== IraMemoryDecisionKind::Assign) {
            return null;
        }

        $value = trim((string) $memory->decision_value);

        return is_numeric($value) && (int) $value > 0 ? (int) $value : null;
    }

    private function resolveSalesRoundRobinAgent(?Carbon $at): ?User
    {
        $userIds = $this->userIdsFromSetting(
            (string) config('inbound_email.assignment_settings.sales_round_robin_user_ids'),
        );

        if ($userIds === []) {
            return null;
        }

        $cursorKey = (string) config('inbound_email.assignment_settings.sales_round_robin_cursor');

        return $this->assignmentService->resolveAgentViaRoundRobinFromPool(
            userIds: $userIds,
            cursorSettingKey: $cursorKey,
            at: $at,
        );
    }

    private function resolveSalesAdminFallback(?Carbon $at, User $actor): ?User
    {
        $salesAdmin = $this->capabilityResolver->resolve(
            AssignmentCapability::SalesLeadHandler,
            $at,
        );

        if ($salesAdmin instanceof User && $salesAdmin->is_active && ! $salesAdmin->trashed()) {
            return $salesAdmin;
        }

        $shiftAdmin = $this->assignmentService->resolveAssigneeOrNull($at);

        if ($shiftAdmin instanceof User) {
            return $shiftAdmin;
        }

        if ($actor->is_active && ! $actor->trashed()) {
            return $actor;
        }

        return null;
    }

    private function assignWithContext(
        Incident $incident,
        User $assignee,
        User $actor,
        string $decisionSource,
        string $reason,
        bool $fallbackUsed,
        string $method,
        ?string $overrideReason = null,
    ): Incident {
        $auditContext = [
            'assignment_method' => $method,
            'assignment_reason' => $reason,
            'assignment_strategy' => self::STRATEGY_SALES_QUEUE_ROUND_ROBIN,
            'fallback_used' => $fallbackUsed,
            'reason' => $reason,
            'decision_source' => $decisionSource,
            'intake_channel' => 'email',
        ];

        if ($overrideReason !== null) {
            $auditContext['override_reason'] = $overrideReason;
        }

        return $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            auditContext: $auditContext,
            event: 'service_case.assigned',
            assignmentOrigin: AssignmentOrigin::Sales,
        );
    }

    /**
     * @return list<int>
     */
    private function userIdsFromSetting(string $settingKey): array
    {
        $raw = trim($this->settingService->get($settingKey, ''));

        if ($raw === '') {
            return [];
        }

        return array_values(array_unique(array_filter(array_map(
            static fn (string $part): int => (int) trim($part),
            explode(',', $raw),
        ), static fn (int $id): bool => $id > 0)));
    }
}
