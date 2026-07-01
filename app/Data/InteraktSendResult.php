<?php

namespace App\Data;

readonly class InteraktSendResult
{
    public function __construct(
        public bool $success,
        public ?string $messageId = null,
        public ?string $errorMessage = null,
        public ?int $httpStatus = null,
        public bool $retriable = false,
    ) {}

    public static function success(string $messageId): self
    {
        return new self(success: true, messageId: $messageId);
    }

    public static function failure(string $errorMessage, ?int $httpStatus = null, bool $retriable = false): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            httpStatus: $httpStatus,
            retriable: $retriable,
        );
    }
}
