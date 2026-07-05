<?php

namespace App\Data\Operations;

readonly class IraOperationalRisk
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $key,
        public string $title,
        public \App\Enums\IraRiskCategory $category,
        public \App\Enums\AI\AIRiskLevel $severity,
        public string $message,
        public array $context = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'title' => $this->title,
            'category' => $this->category->value,
            'category_label' => $this->category->label(),
            'severity' => $this->severity->value,
            'severity_label' => $this->severity->label(),
            'message' => $this->message,
            'context' => $this->context,
        ];
    }
}
