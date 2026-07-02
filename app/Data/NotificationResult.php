<?php

namespace App\Data;

use App\Enums\NotificationChannelType;

readonly class NotificationResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $success,
        public NotificationChannelType $channel,
        public ?string $external_id,
        public ?string $message,
        public bool $retryable,
        public array $metadata = [],
    ) {}

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function success(
        NotificationChannelType $channel,
        ?string $externalId = null,
        ?string $message = null,
        array $metadata = [],
    ): self {
        return new self(
            success: true,
            channel: $channel,
            external_id: $externalId,
            message: $message,
            retryable: false,
            metadata: $metadata,
        );
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    public static function failure(
        NotificationChannelType $channel,
        string $message,
        bool $retryable = false,
        ?string $externalId = null,
        array $metadata = [],
    ): self {
        return new self(
            success: false,
            channel: $channel,
            external_id: $externalId,
            message: $message,
            retryable: $retryable,
            metadata: $metadata,
        );
    }

    public function status(): string
    {
        $metadataStatus = $this->metadata['status'] ?? null;

        if (is_string($metadataStatus) && $metadataStatus !== '') {
            return $metadataStatus;
        }

        return $this->success ? 'sent' : 'failed';
    }

    public function isSkipped(): bool
    {
        return $this->status() === 'not_yet_configured';
    }

    public function countsTowardSuccess(): bool
    {
        return $this->success && ! $this->isSkipped();
    }

    /**
     * @return array{
     *     channel: string,
     *     status: string,
     *     success: bool,
     *     retryable: bool,
     *     message: ?string,
     *     timestamp: string,
     *     duration_ms: int,
     * }
     */
    public function toAuditRecord(string $timestamp, int $durationMs = 0): array
    {
        return [
            'channel' => $this->channel->value,
            'status' => $this->status(),
            'success' => $this->success,
            'retryable' => $this->retryable,
            'message' => $this->message,
            'timestamp' => $timestamp,
            'duration_ms' => $durationMs,
        ];
    }
}
