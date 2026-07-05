<?php

namespace App\Data\Operations;

readonly class IraOperationalRecommendation
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function __construct(
        public string $key,
        public string $message,
        public ?string $actionUrl = null,
        public array $context = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'key' => $this->key,
            'message' => $this->message,
            'action_url' => $this->actionUrl,
            'context' => $this->context,
        ];
    }
}
