<?php

namespace App\Data\Automation;

readonly class ActionHandlerResult
{
    /**
     * @param  array<string, mixed>  $metadata
     */
    public function __construct(
        public bool $success,
        public ?string $externalId = null,
        public ?string $errorMessage = null,
        public array $metadata = [],
    ) {}

    public static function success(?string $externalId = null, array $metadata = []): self
    {
        return new self(
            success: true,
            externalId: $externalId,
            metadata: $metadata,
        );
    }

    public static function failure(string $errorMessage, array $metadata = []): self
    {
        return new self(
            success: false,
            errorMessage: $errorMessage,
            metadata: $metadata,
        );
    }
}
