<?php

namespace App\Data\Interakt;

readonly class TemplateConfigurationStatus
{
    public function __construct(
        public string $templateKey,
        public ?string $templateName,
        public ?string $languageCode,
        public bool $valid,
        public bool $enabled = true,
        public ?string $warning = null,
        public ?string $error = null,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'template_key' => $this->templateKey,
            'template_name' => $this->templateName,
            'language_code' => $this->languageCode,
            'valid' => $this->valid,
            'enabled' => $this->enabled,
            'warning' => $this->warning,
            'error' => $this->error,
        ];
    }
}
