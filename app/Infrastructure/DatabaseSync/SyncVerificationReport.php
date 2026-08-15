<?php

namespace App\Infrastructure\DatabaseSync;

final readonly class SyncVerificationReport
{
    /**
     * @param  array<string, mixed>  $source
     * @param  array<string, mixed>  $target
     * @param  list<array<string, mixed>>  $tables
     * @param  list<string>  $warnings
     * @param  list<string>  $blockers
     */
    public function __construct(
        public string $generatedAt,
        public string $direction,
        public array $source,
        public array $target,
        public SchemaParityResult $schemaParity,
        public array $tables,
        public array $warnings = [],
        public array $blockers = [],
    ) {}

    public function hasBlockers(): bool
    {
        return $this->blockers !== [] || ! $this->schemaParity->matched;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'generated_at' => $this->generatedAt,
            'direction' => $this->direction,
            'source' => $this->source,
            'target' => $this->target,
            'schema_parity' => $this->schemaParity->toArray(),
            'tables' => $this->tables,
            'warnings' => array_values(array_unique(array_merge($this->warnings, $this->schemaParity->warnings))),
            'blockers' => array_values(array_unique(array_merge($this->blockers, $this->schemaParity->blockers))),
            'dry_run_only' => true,
        ];
    }

    public function toJson(): string
    {
        $encoded = json_encode($this->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);

        if ($encoded === false) {
            throw new \RuntimeException('Unable to encode sync verification report.');
        }

        return $encoded;
    }
}
