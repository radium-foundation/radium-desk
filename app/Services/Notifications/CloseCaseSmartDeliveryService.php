<?php

namespace App\Services\Notifications;

use App\Data\NotificationDispatchResult;
use App\Data\NotificationMessage;
use App\Data\NotificationResult;
use App\Enums\NotificationChannelType;
use App\Enums\NotificationType;
use Illuminate\Support\Str;
use Throwable;

class CloseCaseSmartDeliveryService
{
    private const DELIVERY_STRATEGY = 'smart_delivery';

    public function __construct(
        private readonly NotificationDispatcher $notificationDispatcher,
        private readonly NotificationAuditTrailService $auditTrail,
    ) {}

    public function dispatch(
        NotificationType $type,
        NotificationMessage $message,
    ): NotificationDispatchResult {
        $startedAt = microtime(true);
        $results = [];

        $emailResult = $this->attemptChannel($type, $message, NotificationChannelType::Email);
        $results[] = $emailResult;

        if ($emailResult->countsTowardSuccess()) {
            return $this->finalize(
                type: $type,
                message: $message,
                results: $results,
                actualChannel: NotificationChannelType::Email,
                fallbackReason: null,
                startedAt: $startedAt,
            );
        }

        $fallbackReason = $this->resolveEmailFallbackReason($emailResult);

        $whatsappResult = $this->attemptChannel($type, $message, NotificationChannelType::WhatsApp);
        $results[] = $whatsappResult;

        if ($whatsappResult->countsTowardSuccess()) {
            return $this->finalize(
                type: $type,
                message: $message,
                results: $results,
                actualChannel: NotificationChannelType::WhatsApp,
                fallbackReason: $fallbackReason,
                startedAt: $startedAt,
            );
        }

        return $this->finalize(
            type: $type,
            message: $message,
            results: $results,
            actualChannel: null,
            fallbackReason: null,
            startedAt: $startedAt,
        );
    }

    public function formatOperatorResult(NotificationDispatchResult $dispatchResult): string
    {
        if ($dispatchResult->success) {
            return $this->timelineSummary($dispatchResult);
        }

        return "Notification failed\n".$this->formatFailureDetails($dispatchResult->results);
    }

    /**
     * @param  array<int, NotificationResult>  $results
     */
    public function timelineSummary(NotificationDispatchResult $dispatchResult): string
    {
        $metadata = $this->extractSmartDeliveryMetadata($dispatchResult);

        if (filled($metadata['timeline_summary'] ?? null)) {
            return (string) $metadata['timeline_summary'];
        }

        return $this->buildTimelineSummary(
            actualChannel: isset($metadata['actual_channel'])
                ? NotificationChannelType::tryFrom((string) $metadata['actual_channel'])
                : null,
            fallbackReason: $metadata['fallback_reason'] ?? null,
            results: $dispatchResult->results,
        );
    }

    private function attemptChannel(
        NotificationType $type,
        NotificationMessage $message,
        NotificationChannelType $channelType,
    ): NotificationResult {
        $enabledChannels = $this->notificationDispatcher->resolveEnabledChannels($type, [$channelType]);

        if ($enabledChannels === []) {
            return NotificationResult::failure(
                channel: $channelType,
                message: $channelType === NotificationChannelType::Email
                    ? 'Email notifications are not available.'
                    : 'WhatsApp notifications are not available.',
                retryable: false,
                metadata: [
                    'status' => 'channel_unavailable',
                    'notification_type' => $type->value,
                    'incident_id' => $message->incident->id,
                ],
            );
        }

        try {
            return $enabledChannels[0]->send($message);
        } catch (Throwable $exception) {
            return NotificationResult::failure(
                channel: $channelType,
                message: 'Unexpected channel failure: '.$exception->getMessage(),
                retryable: true,
                metadata: [
                    'status' => 'exception',
                    'notification_type' => $type->value,
                    'incident_id' => $message->incident->id,
                ],
            );
        }
    }

    /**
     * @param  array<int, NotificationResult>  $results
     */
    private function finalize(
        NotificationType $type,
        NotificationMessage $message,
        array $results,
        ?NotificationChannelType $actualChannel,
        ?string $fallbackReason,
        float $startedAt,
    ): NotificationDispatchResult {
        $dispatchResult = NotificationDispatchResult::fromResults($results);
        $timelineSummary = $this->buildTimelineSummary($actualChannel, $fallbackReason, $results);
        $enrichedMessage = $this->enrichMessage($message, $actualChannel, $fallbackReason, $timelineSummary);
        $channelRecords = $this->buildChannelRecords($results);

        $this->auditTrail->record($enrichedMessage, $dispatchResult, $channelRecords);

        return new NotificationDispatchResult(
            success: $dispatchResult->success,
            results: $results,
            message: $timelineSummary,
            metadata: [
                'delivery_strategy' => self::DELIVERY_STRATEGY,
                'preferred_channel' => NotificationChannelType::Email->value,
                'actual_channel' => $actualChannel?->value,
                'fallback_reason' => $fallbackReason,
                'timeline_summary' => $timelineSummary,
                'duration_ms' => (int) round((microtime(true) - $startedAt) * 1000),
                'notification_type' => $type->value,
                'incident_id' => $message->incident->id,
            ],
        );
    }

    private function enrichMessage(
        NotificationMessage $message,
        ?NotificationChannelType $actualChannel,
        ?string $fallbackReason,
        string $timelineSummary,
    ): NotificationMessage {
        return new NotificationMessage(
            type: $message->type,
            customer: $message->customer,
            incident: $message->incident,
            subject: $message->subject,
            template: $message->template,
            variables: $message->variables,
            attachments: $message->attachments,
            metadata: array_merge($message->metadata, [
                'delivery_strategy' => self::DELIVERY_STRATEGY,
                'preferred_channel' => NotificationChannelType::Email->value,
                'actual_channel' => $actualChannel?->value,
                'fallback_reason' => $fallbackReason,
                'timeline_summary' => $timelineSummary,
            ]),
            actor: $message->actor,
            httpRequest: $message->httpRequest,
        );
    }

    /**
     * @param  array<int, NotificationResult>  $results
     * @return array<int, array{
     *     channel: string,
     *     status: string,
     *     success: bool,
     *     retryable: bool,
     *     message: ?string,
     *     timestamp: string,
     *     duration_ms: int,
     * }>
     */
    private function buildChannelRecords(array $results): array
    {
        $timestamp = now()->toIso8601String();

        return array_map(
            fn (NotificationResult $result): array => $result->toAuditRecord($timestamp),
            $results,
        );
    }

    private function resolveEmailFallbackReason(NotificationResult $emailResult): string
    {
        $status = (string) ($emailResult->metadata['status'] ?? '');
        $message = Str::lower(trim((string) ($emailResult->message ?? '')));

        if (in_array($status, [
            'missing_customer_email',
            'channel_unavailable',
            'mail_disabled',
            'not_yet_configured',
        ], true)) {
            return 'email_unavailable';
        }

        if (Str::contains($message, ['not available', 'not configured', 'disabled'])) {
            return 'email_unavailable';
        }

        return 'email_delivery_failed';
    }

    /**
     * @param  array<int, NotificationResult>  $results
     */
    private function buildTimelineSummary(
        ?NotificationChannelType $actualChannel,
        ?string $fallbackReason,
        array $results,
    ): string {
        if ($actualChannel === null) {
            return "Notification failed:\n".$this->formatFailureDetails($results);
        }

        if ($actualChannel === NotificationChannelType::Email) {
            return 'Notification sent via Email.';
        }

        $reasonLabel = match ($fallbackReason) {
            'email_delivery_failed' => 'Email delivery failed',
            default => 'Email unavailable',
        };

        return "Notification sent via WhatsApp ({$reasonLabel}).";
    }

    /**
     * @param  array<int, NotificationResult>  $results
     */
    private function formatFailureDetails(array $results): string
    {
        return collect($results)
            ->reject(fn (NotificationResult $result): bool => $result->isSkipped())
            ->map(function (NotificationResult $result): string {
                $message = trim((string) ($result->message ?? 'Delivery failed.'));

                return sprintf('- %s: %s', $result->channel->label(), $message);
            })
            ->implode("\n");
    }

    /**
     * @return array<string, mixed>
     */
    private function extractSmartDeliveryMetadata(NotificationDispatchResult $dispatchResult): array
    {
        return is_array($dispatchResult->metadata ?? null) ? $dispatchResult->metadata : [];
    }
}
