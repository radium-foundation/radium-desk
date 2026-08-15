<?php

namespace App\Infrastructure\DatabaseSync;

final readonly class SchemaParityResult
{
    /**
     * @param  list<string>  $warnings
     * @param  list<string>  $blockers
     * @param  array<string, int>  $sourceMigrations
     * @param  array<string, int>  $targetMigrations
     */
    public function __construct(
        public bool $matched,
        public array $warnings = [],
        public array $blockers = [],
        public array $sourceMigrations = [],
        public array $targetMigrations = [],
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'matched' => $this->matched,
            'warnings' => $this->warnings,
            'blockers' => $this->blockers,
            'source_migration_count' => count($this->sourceMigrations),
            'target_migration_count' => count($this->targetMigrations),
        ];
    }
}
