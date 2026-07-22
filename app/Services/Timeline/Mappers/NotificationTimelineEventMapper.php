<?php

namespace App\Services\Timeline\Mappers;

use App\Data\TimelineEvent;
use App\Enums\NotificationChannelType;
use App\Enums\TimelineEventType;
use App\Models\AuditLog;
use App\Services\AutomationIdentityService;
use App\Services\Notifications\NotificationAuditTrailService;
use App\Support\Timeline\TimelineActorPresenter;
use App\Support\Timeline\TimelineCommunicationTitleMapper;
use Illuminate\Support\Collection;

class NotificationTimelineEventMapper
{
    public function __construct(
        private readonly AutomationIdentityService $automationIdentity,
    ) {}

    /**
     * @return Collection<int, TimelineEvent>
     */
    public function fromAuditLog(AuditLog $auditLog): Collection
    {
        if ($auditLog->event === NotificationAuditTrailService::EVENT_SKIPPED
            && $auditLog->created_at !== null) {
            return $this->mapSkipped($auditLog);
        }

        if ($auditLog->event !== NotificationAuditTrailService::EVENT_DISPATCHED
            || $auditLog->created_at === null) {
            return collect();
        }

        $actor = TimelineActorPresenter::for(
            $this->automationIdentity->resolve($auditLog->user),
        )->normalizedActor();
        $notificationType = (string) ($auditLog->new_values['notification_type'] ?? 'Notification');
        $channels = $this->buildCommunicationChannels($auditLog);
        $aggregateSuccess = (bool) ($auditLog->new_values['aggregate_success'] ?? false);
        $summaryFields = $this->buildExpandedMetadata($auditLog, $notificationType, $channels);
        $title = TimelineCommunicationTitleMapper::titleFor($notificationType);
        $detail = filled($auditLog->new_values['timeline_summary'] ?? null)
            ? (string) $auditLog->new_values['timeline_summary']
            : $this->buildExpandedDetail($auditLog, $channels);

        return collect([
            new TimelineEvent(
                type: TimelineEventType::Notification,
                occurredAt: $auditLog->created_at,
                title: $title,
                actor: $actor,
                dedupeKey: "notification:audit:{$auditLog->id}",
                detail: $detail,
                statusLabel: null,
                statusVariant: null,
                summaryFields: $summaryFields,
                filterTags: ['notifications'],
                communicationChannels: $channels,
                indicatorVariant: $aggregateSuccess ? 'success' : 'danger',
                storyKey: TimelineCommunicationTitleMapper::storyKeyFor($notificationType, (int) $auditLog->id),
            ),
        ]);
    }

    /**
     * @return list<array{label: string, success: bool, detail?: string}>
     */
    private function buildCommunicationChannels(AuditLog $auditLog): array
    {
        $channels = [];

        foreach ($auditLog->new_values['channel_results'] ?? [] as $record) {
            if (! is_array($record)) {
                continue;
            }

            $channel = NotificationChannelType::tryFrom((string) ($record['channel'] ?? ''));

            if ($channel === null) {
                continue;
            }

            $success = (bool) ($record['success'] ?? false);
            $status = strtolower((string) ($record['status'] ?? ''));
            $detail = trim((string) ($record['message'] ?? ''));

            if ($detail === '' && ! in_array($status, ['delivered', 'sent', 'queued', 'retry'], true)) {
                $detail = 'Delivery status unavailable.';
            }

            $channels[] = array_filter([
                'label' => $channel === NotificationChannelType::Email ? 'Email' : 'WhatsApp',
                'success' => $success,
                'detail' => $detail !== '' ? $detail : null,
            ]);
        }

        if ($channels !== []) {
            return $channels;
        }

        return [[
            'label' => 'Notification',
            'success' => (bool) ($auditLog->new_values['aggregate_success'] ?? false),
            'detail' => (string) ($auditLog->new_values['aggregate_message'] ?? 'No channel results recorded.'),
        ]];
    }

    /**
     * @param  list<array{label: string, success: bool, detail?: string}>  $channels
     * @return list<array{label: string, value: string}>
     */
    private function buildExpandedMetadata(AuditLog $auditLog, string $notificationType, array $channels): array
    {
        $metadata = [];

        if (($auditLog->new_values['delivery_strategy'] ?? null) === 'smart_delivery') {
            if (filled($auditLog->new_values['preferred_channel'] ?? null)) {
                $metadata[] = [
                    'label' => 'Preferred channel',
                    'value' => ucfirst((string) $auditLog->new_values['preferred_channel']),
                ];
            }

            if (filled($auditLog->new_values['actual_channel'] ?? null)) {
                $metadata[] = [
                    'label' => 'Actual channel',
                    'value' => ucfirst((string) $auditLog->new_values['actual_channel']),
                ];
            }

            if (filled($auditLog->new_values['fallback_reason'] ?? null)) {
                $metadata[] = [
                    'label' => 'Fallback reason',
                    'value' => $this->formatFallbackReason((string) $auditLog->new_values['fallback_reason']),
                ];
            }
        }

        foreach ($channels as $channel) {
            $status = ($channel['success'] ?? false) ? 'Delivered' : 'Failed';
            $metadata[] = [
                'label' => $channel['label'],
                'value' => isset($channel['detail']) && $channel['detail'] !== ''
                    ? "{$status} — {$channel['detail']}"
                    : $status,
            ];
        }

        $serialCorrection = $auditLog->new_values['serial_correction'] ?? null;

        if (is_array($serialCorrection)) {
            if (filled($serialCorrection['old_serial'] ?? null)) {
                $metadata[] = ['label' => 'Previous serial', 'value' => (string) $serialCorrection['old_serial']];
            }

            if (filled($serialCorrection['confidence'] ?? null)) {
                $metadata[] = ['label' => 'Confidence', 'value' => (string) $serialCorrection['confidence']];
            }
        }

        if ($this->automationIdentity->resolve($auditLog->user)->isAutomation) {
            $metadata[] = ['label' => 'Origin', 'value' => 'IRA automation'];
        }

        return $metadata;
    }

    /**
     * @param  list<array{label: string, success: bool, detail?: string}>  $channels
     */
    private function buildExpandedDetail(AuditLog $auditLog, array $channels): ?string
    {
        $lines = collect($channels)
            ->map(function (array $channel): string {
                $status = ($channel['success'] ?? false) ? 'success' : 'failed';
                $detail = $channel['detail'] ?? null;

                return $detail !== null && $detail !== ''
                    ? "{$channel['label']}: {$status} — {$detail}"
                    : "{$channel['label']}: {$status}";
            })
            ->all();

        if ($lines === []) {
            return (string) ($auditLog->new_values['aggregate_message'] ?? null) ?: null;
        }

        return implode("\n", $lines);
    }

    private function formatFallbackReason(string $fallbackReason): string
    {
        return match ($fallbackReason) {
            'email_delivery_failed' => 'Email delivery failed',
            'email_unavailable' => 'Email unavailable',
            default => ucfirst(str_replace('_', ' ', $fallbackReason)),
        };
    }

    private function mapSkipped(AuditLog $auditLog): Collection
    {
        $actor = TimelineActorPresenter::for(
            $this->automationIdentity->resolve($auditLog->user),
        )->normalizedActor();
        $notificationType = (string) ($auditLog->new_values['notification_type'] ?? 'notification');
        $skipReason = (string) ($auditLog->new_values['skip_reason'] ?? 'Notification skipped.');

        return collect([
            new TimelineEvent(
                type: TimelineEventType::Notification,
                occurredAt: $auditLog->created_at,
                title: TimelineCommunicationTitleMapper::titleFor($notificationType).' skipped',
                actor: $actor,
                dedupeKey: "notification-skipped:audit:{$auditLog->id}",
                detail: $skipReason,
                statusLabel: 'Skipped',
                statusVariant: 'warning',
                summaryFields: [
                    ['label' => 'Reason', 'value' => $skipReason],
                ],
                filterTags: ['notifications'],
                communicationChannels: [],
                indicatorVariant: 'warning',
                storyKey: TimelineCommunicationTitleMapper::storyKeyFor($notificationType, (int) $auditLog->id).':skipped',
            ),
        ]);
    }
}
