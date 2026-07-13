<?php

namespace App\Services\Timeline\Mappers;

use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\CommunicationActionLifecycleStatus;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Models\AuditLog;

class CommunicationActionLifecycleTimelineEventMapper
{
    public function fromAuditLog(AuditLog $auditLog): ?TimelineEvent
    {
        if ($auditLog->created_at === null) {
            return null;
        }

        $status = CommunicationActionLifecycleStatus::tryFrom(
            (string) ($auditLog->new_values['status'] ?? ''),
        );

        if ($status === null) {
            return null;
        }

        if ($status === CommunicationActionLifecycleStatus::Opened
            || $status === CommunicationActionLifecycleStatus::Completed) {
            return null;
        }

        $actionLabel = trim((string) ($auditLog->new_values['action_label'] ?? 'Communication action'));
        $actionKey = (string) ($auditLog->new_values['action_key'] ?? 'unknown');
        $operatorName = trim((string) ($auditLog->new_values['operator_name'] ?? $auditLog->user?->name ?? 'Agent'));
        $channels = array_values(array_filter(
            (array) ($auditLog->new_values['channels'] ?? []),
            fn (mixed $channel): bool => is_string($channel) && $channel !== '',
        ));

        return match ($status) {
            CommunicationActionLifecycleStatus::Sent => new TimelineEvent(
                type: TimelineEventType::Notification,
                occurredAt: $auditLog->created_at,
                title: $actionLabel,
                actor: new TimelineActor(
                    displayName: $operatorName,
                    subtitle: 'Agent action',
                    isAutomation: false,
                    kind: TimelineActorKind::Agent,
                ),
                dedupeKey: 'communication-action-lifecycle:'.$auditLog->id,
                detail: 'Communication action sent to customer.',
                statusLabel: $status->label(),
                statusVariant: 'success',
                summaryFields: $channels !== []
                    ? [['label' => 'Channels', 'value' => implode(', ', $channels)]]
                    : [],
                filterTags: ['notifications', 'communication-action', $actionKey],
            ),
            CommunicationActionLifecycleStatus::Skipped => new TimelineEvent(
                type: TimelineEventType::Notification,
                occurredAt: $auditLog->created_at,
                title: $actionLabel.' skipped',
                actor: new TimelineActor(
                    displayName: $operatorName,
                    subtitle: 'Agent action',
                    isAutomation: false,
                    kind: TimelineActorKind::Agent,
                ),
                dedupeKey: 'communication-action-lifecycle:'.$auditLog->id,
                detail: 'Operator chose not to send this communication.',
                statusLabel: $status->label(),
                statusVariant: 'neutral',
                summaryFields: filled($auditLog->new_values['skip_reason'] ?? null)
                    ? [['label' => 'Reason', 'value' => (string) $auditLog->new_values['skip_reason']]]
                    : [],
                filterTags: ['notifications', 'communication-action', $actionKey],
            ),
            default => null,
        };
    }
}
