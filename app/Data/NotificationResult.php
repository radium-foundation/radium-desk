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
}
