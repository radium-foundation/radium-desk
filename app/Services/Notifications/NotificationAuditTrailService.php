<?php

namespace App\Services\Notifications;

use App\Data\NotificationDispatchResult;
use App\Data\NotificationMessage;
use App\Models\AuditLog;
use App\Services\AuditLogService;
use Throwable;

class NotificationAuditTrailService
{
    public const EVENT_DISPATCHED = 'notification.dispatched';

    public const EVENT_SKIPPED = 'notification.skipped';

    public function __construct(
        private readonly AuditLogService $auditLogService,
    ) {}

    public function recordSkipped(NotificationMessage $message, string $reason): AuditLog
    {
        $idempotencyKey = $this->skipIdempotencyKey($message, $reason);

        $existing = AuditLog::query()
            ->where('auditable_type', $message->incident->getMorphClass())
            ->where('auditable_id', $message->incident->id)
            ->where('event', self::EVENT_SKIPPED)
            ->where('new_values->skip_idempotency_key', $idempotencyKey)
            ->latest('id')
            ->first();

        if ($existing instanceof AuditLog) {
            return $existing;
        }

        return $this->auditLogService->log(
            userId: $message->actor?->id,
            event: self::EVENT_SKIPPED,
            auditable: $message->incident,
            newValues: [
                'notification_type' => $message->type->value,
                'source' => $message->metadata['source'] ?? null,
                'trigger_source' => $message->metadata['trigger_source'] ?? null,
                'automation_action' => $message->metadata['automation_action'] ?? null,
                'policy_key' => $message->metadata['policy_key'] ?? null,
                'action_key' => $message->metadata['action_key'] ?? null,
                'blocking_appointment_id' => $message->metadata['blocking_appointment_id'] ?? null,
                'skip_reason' => $reason,
                'skip_idempotency_key' => $idempotencyKey,
            ],
            request: $message->httpRequest,
        );
    }

    private function skipIdempotencyKey(NotificationMessage $message, string $reason): string
    {
        $parts = [
            $message->type->value,
            $reason,
            (string) ($message->metadata['source'] ?? ''),
            (string) ($message->metadata['automation_action'] ?? ''),
            (string) ($message->metadata['policy_key'] ?? ''),
            (string) ($message->metadata['action_key'] ?? ''),
            (string) ($message->metadata['blocking_appointment_id'] ?? ''),
        ];

        return hash('sha256', implode('|', $parts));
    }

    public function recordUnhandledFailure(
        NotificationMessage $message,
        Throwable $exception,
    ): AuditLog {
        return $this->record(
            message: $message,
            dispatchResult: new NotificationDispatchResult(
                success: false,
                results: [],
                message: 'Notification dispatch failed: '.$exception->getMessage(),
            ),
            channelRecords: [],
        );
    }

    /**
     * @param  array<int, array{
     *     channel: string,
     *     status: string,
     *     success: bool,
     *     retryable: bool,
     *     message: ?string,
     *     timestamp: string,
     *     duration_ms: int,
     * }>  $channelRecords
     */
    public function record(
        NotificationMessage $message,
        NotificationDispatchResult $dispatchResult,
        array $channelRecords,
    ): AuditLog {
        return $this->auditLogService->log(
            userId: $message->actor?->id,
            event: self::EVENT_DISPATCHED,
            auditable: $message->incident,
            newValues: [
                'notification_type' => $message->type->value,
                'source' => $message->metadata['source'] ?? null,
                'trigger_source' => $message->metadata['trigger_source'] ?? null,
                'serial_correction' => $message->metadata['serial_correction'] ?? null,
                'communication_action_key' => $message->metadata['communication_action_key'] ?? null,
                'communication_action_label' => $message->metadata['communication_action_label'] ?? null,
                'communication_target' => $message->metadata['communication_target'] ?? null,
                'delivery_channel' => $message->metadata['delivery_channel'] ?? null,
                'delivery_strategy' => $message->metadata['delivery_strategy'] ?? null,
                'preferred_channel' => $message->metadata['preferred_channel'] ?? null,
                'actual_channel' => $message->metadata['actual_channel'] ?? null,
                'fallback_reason' => $message->metadata['fallback_reason'] ?? null,
                'timeline_summary' => $message->metadata['timeline_summary'] ?? null,
                'aggregate_success' => $dispatchResult->success,
                'aggregate_message' => $dispatchResult->message,
                'channel_results' => $channelRecords,
            ],
            request: $message->httpRequest,
        );
    }
}
