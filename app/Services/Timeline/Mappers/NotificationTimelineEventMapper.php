<?php

namespace App\Services\Timeline\Mappers;

use App\Data\TimelineActor;
use App\Data\TimelineEvent;
use App\Enums\NotificationChannelType;
use App\Enums\TimelineActorKind;
use App\Enums\TimelineEventType;
use App\Models\AuditLog;
use App\Services\AutomationIdentityService;
use App\Services\Notifications\NotificationAuditTrailService;
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
        if ($auditLog->event !== NotificationAuditTrailService::EVENT_DISPATCHED
            || $auditLog->created_at === null) {
            return collect();
        }

        $actor = $this->automationIdentity->resolve($auditLog->user);
        $notificationType = (string) ($auditLog->new_values['notification_type'] ?? 'Notification');
        $events = collect();

        foreach ($auditLog->new_values['channel_results'] ?? [] as $index => $record) {
            if (! is_array($record)) {
                continue;
            }

            $mapped = $this->mapChannelResult(
                auditLog: $auditLog,
                record: $record,
                actor: $actor,
                notificationType: $notificationType,
                index: (int) $index,
            );

            if ($mapped !== null) {
                $events->push($mapped);
            }
        }

        if ($events->isEmpty()) {
            $events->push(new TimelineEvent(
                type: TimelineEventType::Notification,
                occurredAt: $auditLog->created_at,
                title: 'Notification dispatched',
                actor: $actor,
                dedupeKey: "notification:audit:{$auditLog->id}",
                detail: (string) ($auditLog->new_values['aggregate_message'] ?? 'No channel results recorded.'),
                statusLabel: ($auditLog->new_values['aggregate_success'] ?? false) ? 'Sent' : 'Failed',
                statusVariant: ($auditLog->new_values['aggregate_success'] ?? false) ? 'success' : 'danger',
                filterTags: ['notifications'],
            ));
        }

        return $events;
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function mapChannelResult(
        AuditLog $auditLog,
        array $record,
        TimelineActor $actor,
        string $notificationType,
        int $index,
    ): ?TimelineEvent {
        $channel = NotificationChannelType::tryFrom((string) ($record['channel'] ?? ''));

        if ($channel === null || $auditLog->created_at === null) {
            return null;
        }

        $status = strtolower((string) ($record['status'] ?? ''));
        $success = (bool) ($record['success'] ?? false);
        $channelLabel = $channel === NotificationChannelType::Email ? 'Email' : 'WhatsApp';
        $title = $this->channelTitle($channelLabel, $status, $success, $notificationType);
        $eventType = $channel === NotificationChannelType::Email
            ? TimelineEventType::Email
            : TimelineEventType::WhatsApp;

        [$statusLabel, $statusVariant] = $this->statusPresentation($status, $success);
        $detail = $this->channelDetail($record, $status);
        $summaryFields = [
            ['label' => 'Type', 'value' => $this->notificationTypeLabel($notificationType)],
            ['label' => 'Channel', 'value' => $channelLabel],
        ];

        $serialCorrection = $auditLog->new_values['serial_correction'] ?? null;

        if (is_array($serialCorrection)) {
            if (filled($serialCorrection['old_serial'] ?? null)) {
                $summaryFields[] = ['label' => 'Previous serial', 'value' => (string) $serialCorrection['old_serial']];
            }

            if (filled($serialCorrection['confidence'] ?? null)) {
                $summaryFields[] = ['label' => 'Confidence', 'value' => (string) $serialCorrection['confidence']];
            }
        }

        return new TimelineEvent(
            type: $eventType,
            occurredAt: $auditLog->created_at,
            title: $title,
            actor: new TimelineActor(
                displayName: $actor->displayName,
                subtitle: $actor->subtitle,
                isAutomation: $actor->isAutomation,
                kind: $actor->isAutomation ? TimelineActorKind::Automation : TimelineActorKind::Agent,
            ),
            dedupeKey: "notification:audit:{$auditLog->id}:{$index}",
            detail: $detail,
            statusLabel: $statusLabel,
            statusVariant: $statusVariant,
            summaryFields: $summaryFields,
            filterTags: ['notifications'],
        );
    }

    private function notificationTypeLabel(string $notificationType): string
    {
        return match ($notificationType) {
            'request_correct_serial' => 'Request correct serial',
            'request_serial_number' => 'Request serial number',
            default => str_replace('_', ' ', $notificationType),
        };
    }

    private function channelTitle(string $channelLabel, string $status, bool $success, string $notificationType = ''): string
    {
        $prefix = $notificationType === 'request_correct_serial'
            ? 'Serial correction'
            : $channelLabel;

        return match (true) {
            $status === 'queued' => "{$prefix} queued",
            $status === 'delivered' => "{$prefix} delivered",
            $status === 'sent' && $success => $notificationType === 'request_correct_serial'
                ? 'Serial correction request sent'
                : "{$channelLabel} sent",
            $status === 'retry' => "{$channelLabel} retry",
            ! $success => "{$channelLabel} failed",
            default => "{$channelLabel} sent",
        };
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function statusPresentation(string $status, bool $success): array
    {
        return match (true) {
            $status === 'queued' => ['Queued', 'pending'],
            $status === 'delivered' => ['Delivered', 'success'],
            $status === 'sent' && $success => ['Sent', 'success'],
            $status === 'retry' => ['Retry', 'warning'],
            ! $success => ['Failed', 'danger'],
            default => ['Sent', 'success'],
        };
    }

    /**
     * @param  array<string, mixed>  $record
     */
    private function channelDetail(array $record, string $status): ?string
    {
        $message = trim((string) ($record['message'] ?? ''));

        if ($message !== '') {
            return $message;
        }

        if (! in_array($status, ['delivered', 'sent', 'queued', 'retry'], true)) {
            return 'Delivery status unavailable.';
        }

        return null;
    }
}
