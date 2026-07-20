<?php

namespace App\Data\Bonvoice;

readonly class BonvoiceClickToCallResult
{
    public function __construct(
        public bool $success,
        public ?string $eventId = null,
        public ?string $message = null,
        public ?string $errorMessage = null,
        public ?int $httpStatus = null,
        public bool $retriable = false,
    ) {}

    public static function success(string $eventId, string $message): self
    {
        return new self(
            success: true,
            eventId: $eventId,
            message: $message,
        );
    }

    public static function failure(
        string $errorMessage,
        ?int $httpStatus = null,
        bool $retriable = false,
    ): self {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            httpStatus: $httpStatus,
            retriable: $retriable,
        );
    }
}
