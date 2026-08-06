<?php

namespace App\Services\IncomingEmail;

use App\Enums\Assignment\EmailAssignmentClassification;
use App\Enums\AssignmentOrigin;
use App\Enums\IncomingEmailSmartRoute;
use App\Models\Incident;
use App\Models\IncomingEmailMessage;
use App\Models\Order;
use App\Models\ServiceCaseCloseOutcome;
use App\Models\User;
use App\Services\Assignment\UniversalAssignmentEngine;
use App\Services\AuditLogService;
use App\Services\ServiceCaseAssignmentService;
use App\Services\SettingService;
use Illuminate\Support\Carbon;

class IncomingEmailSmartRoutingAssignmentService
{
    public function __construct(
        private readonly ServiceCaseAssignmentService $assignmentService,
        private readonly UniversalAssignmentEngine $assignmentEngine,
        private readonly IncomingEmailAssignmentService $emailAssignmentService,
        private readonly IncomingEmailSalesAssignmentService $salesAssignmentService,
        private readonly SettingService $settingService,
        private readonly AuditLogService $auditLogService,
    ) {}

    public function assignForRoute(
        Incident $incident,
        IncomingEmailMessage $message,
        IncomingEmailSmartRoute $route,
        User $actor,
        string $routeReason,
        ?Order $order = null,
        ?Carbon $at = null,
    ): Incident {
        $at ??= now();
        $incident = $incident->fresh(['assignee', 'order']);

        $assignmentSource = 'existing_owner';
        $roundRobinUserId = null;

        if ($incident->assigned_to_user_id === null) {
            [$incident, $assignmentSource, $roundRobinUserId] = match ($route) {
                IncomingEmailSmartRoute::ExistingCustomerNewCase => $this->assignPreviousOwnerOrSupportRoundRobin(
                    $incident,
                    $order ?? $incident->order,
                    $actor,
                    $at,
                ),
                IncomingEmailSmartRoute::RefundEnquiry => $this->assignRefundTeam($incident, $actor, $at),
                IncomingEmailSmartRoute::SalesEnquiry => $this->assignSalesRoundRobin($incident, $message, $actor, $at),
                IncomingEmailSmartRoute::SupportEnquiry => $this->assignSupportRoundRobin($incident, $actor, $at),
                default => [$incident, 'none', null],
            };
        }

        $this->auditLogService->log(
            userId: $actor->id,
            event: 'incoming_email.routed',
            auditable: $incident->fresh(['assignee', 'order']),
            newValues: [
                'route' => $route->value,
                'route_label' => $route->label(),
                'reason' => $routeReason,
                'assignment_source' => $assignmentSource,
                'round_robin_user_id' => $roundRobinUserId,
                'assigned_to_user_id' => $incident->fresh()->assigned_to_user_id,
                'mailbox' => $message->mailbox,
                'incoming_email_message_id' => $message->id,
                'incident_id' => $incident->id,
                'routed_at' => now()->toIso8601String(),
            ],
        );

        $this->emailAssignmentService->notifyOwnerOfNewEmail(
            $incident->fresh(['assignee']),
            $message,
        );

        return $incident->fresh(['assignee', 'order']);
    }

    /**
     * @return array{0: Incident, 1: string, 2: ?int}
     */
    private function assignPreviousOwnerOrSupportRoundRobin(
        Incident $incident,
        ?Order $order,
        User $actor,
        Carbon $at,
    ): array {
        $previousOwner = $this->resolvePreviousAccountOwner($order, $incident);

        if ($previousOwner instanceof User) {
            $assigned = $this->assignmentService->assignWithAuditContext(
                incident: $incident,
                assignee: $previousOwner,
                actor: $actor,
                auditContext: [
                    'assignment_method' => 'inbound_email_previous_account_owner',
                    'assignment_reason' => 'previous_account_owner',
                    'intake_channel' => 'email',
                    'previous_owner_user_id' => $previousOwner->id,
                ],
                event: 'service_case.assigned',
                assignmentOrigin: AssignmentOrigin::Support,
            );

            return [$assigned, 'previous_account_owner', null];
        }

        $assignee = $this->assignmentService->resolveSupportAgentViaRoundRobin($at, $order);

        if ($assignee === null) {
            return [$incident, 'support_round_robin_unresolved', null];
        }

        $assigned = $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            auditContext: [
                'assignment_method' => 'inbound_email_support_round_robin',
                'assignment_reason' => 'existing_customer_no_previous_owner',
                'intake_channel' => 'email',
            ],
            event: 'service_case.assigned',
            assignmentOrigin: AssignmentOrigin::Support,
        );

        return [$assigned, 'support_round_robin', $assignee->id];
    }

    /**
     * @return array{0: Incident, 1: string, 2: ?int}
     */
    private function assignRefundTeam(Incident $incident, User $actor, Carbon $at): array
    {
        $userIds = $this->userIdsFromSetting(
            (string) config('inbound_email.assignment_settings.refund_team_user_ids'),
        );
        $cursorKey = (string) config('inbound_email.assignment_settings.refund_round_robin_cursor');

        $assignee = $this->assignmentService->resolveAgentViaRoundRobinFromPool(
            userIds: $userIds,
            cursorSettingKey: $cursorKey,
            at: $at,
        );

        if ($assignee === null) {
            return [$incident, 'refund_team_unresolved', null];
        }

        $assigned = $this->assignmentService->assignWithAuditContext(
            incident: $incident,
            assignee: $assignee,
            actor: $actor,
            auditContext: [
                'assignment_method' => 'inbound_email_refund_team_round_robin',
                'assignment_reason' => 'refund_team',
                'intake_channel' => 'email',
            ],
            event: 'service_case.assigned',
            assignmentOrigin: AssignmentOrigin::Refund,
        );

        return [$assigned, 'refund_team_round_robin', $assignee->id];
    }

    /**
     * @return array{0: Incident, 1: string, 2: ?int}
     */
    private function assignSalesRoundRobin(
        Incident $incident,
        IncomingEmailMessage $message,
        User $actor,
        Carbon $at,
    ): array {
        // Smart Routing path: IRA Memory may override Sales RR.
        return $this->salesAssignmentService->assignSalesLead(
            incident: $incident,
            actor: $actor,
            message: $message,
            allowIraMemoryOverride: true,
            at: $at,
        );
    }

    /**
     * @return array{0: Incident, 1: string, 2: ?int}
     */
    private function assignSupportRoundRobin(Incident $incident, User $actor, Carbon $at): array
    {
        $assigned = $this->assignmentEngine->assignForEmailClassification(
            incident: $incident,
            actor: $actor,
            classification: EmailAssignmentClassification::NewSupportCase,
            at: $at,
        );

        return [
            $assigned,
            'support_round_robin',
            $assigned->assigned_to_user_id,
        ];
    }

    private function resolvePreviousAccountOwner(?Order $order, ?Incident $excludeIncident = null): ?User
    {
        if ($order === null) {
            return null;
        }

        $query = Incident::query()
            ->where('order_id', $order->id)
            ->orderByDesc('id');

        if ($excludeIncident instanceof Incident) {
            $query->whereKeyNot($excludeIncident->id);
        }

        $latestIncident = $query->first();

        if (! $latestIncident instanceof Incident) {
            return null;
        }

        $preferredUserId = $latestIncident->assigned_to_user_id ?? $this->stickyOwnerUserId($latestIncident);

        if ($preferredUserId === null || $preferredUserId <= 0) {
            return null;
        }

        $user = User::query()->find($preferredUserId);

        if (! $user instanceof User || ! $user->is_active || $user->trashed()) {
            return null;
        }

        return $user;
    }

    private function stickyOwnerUserId(Incident $incident): ?int
    {
        $outcome = ServiceCaseCloseOutcome::query()
            ->where('incident_id', $incident->id)
            ->orderByDesc('id')
            ->first();

        $sticky = $outcome?->metadata['sticky_agent_user_id'] ?? null;

        return is_numeric($sticky) ? (int) $sticky : null;
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
