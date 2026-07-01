<?php

namespace App\Data;

readonly class WhatsAppTemplateConfiguration
{
    /**
     * @param  list<array<string, mixed>>  $bodyParameters
     * @param  list<array<string, mixed>>  $headerParameters
     */
    public function __construct(
        public string $name,
        public string $languageCode,
        public string $displayName,
        public string $purpose,
        public ?string $internalNote,
        public array $bodyParameters = [],
        public array $headerParameters = [],
    ) {}
}
