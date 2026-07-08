<?php

namespace App\Services\Notifications;

use App\Data\NotificationDispatchResult;
use App\Data\NotificationResult;
use App\Enums\NotificationChannelType;

class NotificationDeliverySummaryFormatter
{
    public function format(NotificationDispatchResult $result): string
    {
        $lines = [$this->headline($result)];

        foreach ($result->results as $channelResult) {
            $lines[] = $this->formatChannelLine($channelResult);
        }

        return implode("\n", $lines);
    }

    public function formatOperatorResult(NotificationDispatchResult $result, ?string $suffix = null): string
    {
        $lines = [$this->headline($result)];

        foreach ($result->results as $channelResult) {
            if ($channelResult->isSkipped()) {
                continue;
            }

            $lines[] = $this->formatOperatorChannelLine($channelResult);
        }

        if (filled($suffix)) {
            $lines[] = $suffix;
        }

        return implode("\n", $lines);
    }

    public function failureMessage(NotificationDispatchResult $result): string
    {
        if ($result->results === []) {
            return $result->message ?? 'No notification channels are available.';
        }

        return $this->format($result);
    }

    private function headline(NotificationDispatchResult $result): string
    {
        if (! $result->success) {
            return 'Notification failed';
        }

        $hasChannelFailure = collect($result->results)->contains(
            fn (NotificationResult $channelResult): bool => ! $channelResult->isSkipped() && ! $channelResult->success,
        );

        return $hasChannelFailure ? 'Notification sent with warnings' : 'Notification sent';
    }

    private function formatChannelLine(NotificationResult $result): string
    {
        $label = $this->channelLabel($result->channel);

        if ($result->isSkipped()) {
            return sprintf('⏭ %s (%s)', $label, $this->skippedReason($result));
        }

        if ($result->countsTowardSuccess()) {
            return sprintf('✓ %s', $label);
        }

        $message = trim((string) ($result->message ?? 'Delivery failed.'));

        return sprintf('✗ %s: %s', $label, $message);
    }

    private function formatOperatorChannelLine(NotificationResult $result): string
    {
        $label = $this->channelLabel($result->channel);

        if ($result->countsTowardSuccess()) {
            return sprintf('✓ %s delivered', $label);
        }

        $message = $this->operatorFailureMessage($result);

        return sprintf("✗ %s\n%s", $label, $message);
    }

    private function operatorFailureMessage(NotificationResult $result): string
    {
        $message = trim((string) ($result->message ?? ''));

        if (($result->metadata['status'] ?? null) === 'transport_failure') {
            return 'Transport unavailable';
        }

        return $message !== '' ? $message : 'Delivery failed.';
    }

    private function channelLabel(NotificationChannelType $channel): string
    {
        return $channel->label();
    }

    private function skippedReason(NotificationResult $result): string
    {
        $message = trim((string) ($result->message ?? ''));

        if ($message === '' || strcasecmp($message, 'Not Yet Configured') === 0) {
            return 'Not configured';
        }

        return $message;
    }
}
