<?php

namespace App\Data;

readonly class GlobalSearchResult
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public string $type,
        public int $entityId,
        public string $url,
        public array $payload,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return array_merge([
            'type' => $this->type,
            'entity_id' => $this->entityId,
            'url' => $this->url,
        ], $this->payload);
    }
}
