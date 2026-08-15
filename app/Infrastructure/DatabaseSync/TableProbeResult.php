<?php

namespace App\Infrastructure\DatabaseSync;

final readonly class TableProbeResult
{
    public function __construct(
        public string $endpoint,
        public string $table,
        public ?int $count = null,
        public int|string|null $minPrimaryKey = null,
        public int|string|null $maxPrimaryKey = null,
        public ?string $maxUpdatedAt = null,
        public ?string $maxCreatedAt = null,
        public ?string $error = null,
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromPayload(string $endpoint, string $table, array $payload): self
    {
        return new self(
            endpoint: $endpoint,
            table: $table,
            count: isset($payload['count']) && is_numeric($payload['count']) ? (int) $payload['count'] : null,
            minPrimaryKey: $payload['min_primary_key'] ?? null,
            maxPrimaryKey: $payload['max_primary_key'] ?? null,
            maxUpdatedAt: is_string($payload['max_updated_at'] ?? null) ? $payload['max_updated_at'] : null,
            maxCreatedAt: is_string($payload['max_created_at'] ?? null) ? $payload['max_created_at'] : null,
            error: is_string($payload['error'] ?? null) ? $payload['error'] : null,
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'endpoint' => $this->endpoint,
            'table' => $this->table,
            'count' => $this->count,
            'min_primary_key' => $this->minPrimaryKey,
            'max_primary_key' => $this->maxPrimaryKey,
            'max_updated_at' => $this->maxUpdatedAt,
            'max_created_at' => $this->maxCreatedAt,
            'error' => $this->error,
        ];
    }

    public function successful(): bool
    {
        return $this->error === null;
    }
}
