<?php

namespace App\Infrastructure\DatabaseSync;

final readonly class ConflictReport
{
    /**
     * @param  list<array<string, mixed>>  $conflicts
     */
    public function __construct(
        public string $generationId,
        public string $table,
        public array $conflicts = [],
    ) {}

    public function hasConflicts(): bool
    {
        return $this->conflicts !== [];
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generation_id' => $this->generationId,
            'table' => $this->table,
            'conflicts' => $this->conflicts,
        ];
    }

    public function toJson(): string
    {
        $encoded = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode conflict report.');
        }

        return $encoded;
    }
}
