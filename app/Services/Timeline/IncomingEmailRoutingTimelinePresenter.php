<?php

namespace App\Services\Timeline;

use App\Models\AuditLog;
use App\Models\IncomingEmailMessage;
use App\Models\User;
use Illuminate\Support\Collection;

/**
 * Presentation helpers for inbound-email smart routing timeline cards.
 */
class IncomingEmailRoutingTimelinePresenter
{
    public const ROUTING_BODY_PREFIX = 'Email routed automatically to';

    public const STORY_KEY = 'incoming_email_smart_routed';

    /**
     * @param  Collection<int, IncomingEmailMessage>  $messages
     * @return array<int, AuditLog>
     */
    public function indexForMessages(Collection $messages): array
    {
        $messageIds = $messages->pluck('id')->filter()->map(fn ($id) => (int) $id)->values()->all();

        if ($messageIds === []) {
            return [];
        }

        return AuditLog::query()
            ->where('event', 'incoming_email.routed')
            ->whereIn('new_values->incoming_email_message_id', $messageIds)
            ->orderByDesc('id')
            ->get()
            ->keyBy(fn (AuditLog $audit): int => (int) ($audit->new_values['incoming_email_message_id'] ?? 0))
            ->all();
    }

    public function contextLine(?AuditLog $routingAudit): ?string
    {
        if (! $routingAudit instanceof AuditLog) {
            return null;
        }

        $team = $this->teamLabel($routingAudit);

        if ($team === null) {
            return null;
        }

        $assignee = $this->assigneeLabel($routingAudit);

        if ($assignee !== null) {
            return self::ROUTING_BODY_PREFIX.' '.$team.' · Assigned to '.$assignee;
        }

        return self::ROUTING_BODY_PREFIX.' '.$team;
    }

    /**
     * @return list<string>
     */
    public function actionBadges(?AuditLog $routingAudit): array
    {
        if (! $routingAudit instanceof AuditLog) {
            return [];
        }

        $badges = [];
        $team = $this->teamLabel($routingAudit);

        if ($team !== null) {
            $badges[] = 'Routed to '.$team;
        }

        $assignee = $this->assigneeLabel($routingAudit);

        if ($assignee !== null) {
            $badges[] = 'Assigned to '.$assignee;
        }

        return $badges;
    }

    private function teamLabel(AuditLog $routingAudit): ?string
    {
        $route = (string) ($routingAudit->new_values['route'] ?? '');

        return match ($route) {
            'refund_enquiry' => 'Refund',
            'sales_enquiry' => 'Sales',
            'support_enquiry' => 'Support',
            'existing_customer_new_case' => 'Support',
            default => null,
        };
    }

    private function assigneeLabel(AuditLog $routingAudit): ?string
    {
        $assigneeId = $routingAudit->new_values['assigned_to_user_id']
            ?? $routingAudit->new_values['round_robin_user_id']
            ?? null;

        if (! is_numeric($assigneeId)) {
            return null;
        }

        $assignee = User::query()->find((int) $assigneeId);

        return $assignee instanceof User ? $assignee->firstName() : null;
    }
}
