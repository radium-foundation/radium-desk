<?php

namespace App\Data\Bonvoice;

use App\Enums\BonvoiceClickToCallFailureCode;

readonly class BonvoiceClickToCallResult
{
    public function __construct(
        public bool $success,
        public ?string $eventId = null,
        public ?string $correlationId = null,
        public ?string $message = null,
        public ?string $errorMessage = null,
        public ?BonvoiceClickToCallFailureCode $failureCode = null,
        public ?int $httpStatus = null,
        public bool $retriable = false,
    ) {}

    public static function success(string $eventId, string $message): self
    {
        return new self(
            success: true,
            eventId: $eventId,
            correlationId: $eventId,
            message: $message,
        );
    }

    public static function failure(
        string $errorMessage,
        BonvoiceClickToCallFailureCode $failureCode,
        ?string $eventId = null,
        ?string $correlationId = null,
        ?int $httpStatus = null,
        bool $retriable = false,
    ): self {
        return new self(
            success: false,
            eventId: $eventId,
            correlationId: $correlationId ?? $eventId,
            errorMessage: $errorMessage,
            failureCode: $failureCode,
            httpStatus: $httpStatus,
            retriable: $retriable,
        );
    }
}
