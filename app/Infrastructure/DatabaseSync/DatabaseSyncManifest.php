<?php

namespace App\Infrastructure\DatabaseSync;

use InvalidArgumentException;

class DatabaseSyncManifest
{
    /** @var list<string> */
    private const EXCLUDED_TABLES = [
        'incoming_email_learning_rules',
        'communication_templates',
        'communication_template_versions',
        'communication_template_usages',
        'cache',
        'cache_locks',
        'sessions',
        'jobs',
        'job_batches',
        'failed_jobs',
        'password_reset_tokens',
        'migrations',
    ];

    /** @var array<string, SyncTableDefinition> */
    private array $tablesByName = [];

    public readonly RemoteEndpointProfile $source;

    public readonly RemoteEndpointProfile $target;

    public readonly string $direction;

    public function __construct(?array $config = null)
    {
        $config ??= config('database-sync');

        if (! is_array($config)) {
            throw new InvalidArgumentException('Database sync configuration is missing.');
        }

        $direction = $config['direction'] ?? null;

        if ($direction !== 'hostinger_to_vps') {
            throw new InvalidArgumentException('Database sync direction must be hostinger_to_vps.');
        }

        $this->direction = $direction;
        $this->source = RemoteEndpointProfile::fromConfig('source', $this->requireArray($config, 'source'));
        $this->target = RemoteEndpointProfile::fromConfig('target', $this->requireArray($config, 'target'));

        $tables = $this->requireArray($config, 'tables');

        foreach ($tables as $tableName => $tableConfig) {
            if (! is_string($tableName) || $tableName === '') {
                throw new InvalidArgumentException('Database sync table names must be non-empty strings.');
            }

            if (! is_array($tableConfig)) {
                throw new InvalidArgumentException("Database sync table [{$tableName}] must be an array.");
            }

            if (in_array($tableName, self::EXCLUDED_TABLES, true)) {
                throw new InvalidArgumentException("Table [{$tableName}] is excluded from database sync and must not appear in the manifest.");
            }

            if (isset($this->tablesByName[$tableName])) {
                throw new InvalidArgumentException("Duplicate database sync table definition [{$tableName}].");
            }

            $this->tablesByName[$tableName] = SyncTableDefinition::fromConfig($tableName, $tableConfig);
        }

        $this->validateDependencies();
    }

    /**
     * @return list<SyncTableDefinition>
     */
    public function tablesInSyncOrder(): array
    {
        $tables = array_values($this->tablesByName);

        usort(
            $tables,
            static function (SyncTableDefinition $left, SyncTableDefinition $right): int {
                return $left->syncOrder <=> $right->syncOrder
                    ?: $left->name <=> $right->name;
            },
        );

        return $tables;
    }

    /**
     * @return list<SyncTableDefinition>
     */
    public function tablesForTier(?int $tier): array
    {
        if ($tier === null) {
            return $this->tablesInSyncOrder();
        }

        return array_values(array_filter(
            $this->tablesInSyncOrder(),
            static fn (SyncTableDefinition $table): bool => $table->tier === $tier,
        ));
    }

    public function find(string $tableName): ?SyncTableDefinition
    {
        return $this->tablesByName[$tableName] ?? null;
    }

    /**
     * @return list<string>
     */
    public function tableNames(): array
    {
        return array_keys($this->tablesByName);
    }

    /**
     * @return list<SyncTableDefinition>
     */
    public function filterTables(?string $tableName, ?int $tier): array
    {
        if ($tableName !== null && $tableName !== '') {
            $definition = $this->find($tableName);

            if ($definition === null) {
                throw new InvalidArgumentException("Unknown database sync table [{$tableName}].");
            }

            if ($tier !== null && $definition->tier !== $tier) {
                throw new InvalidArgumentException("Table [{$tableName}] is not in tier [{$tier}].");
            }

            return [$definition];
        }

        return $this->tablesForTier($tier);
    }

    private function validateDependencies(): void
    {
        foreach ($this->tablesByName as $table) {
            foreach ($table->dependsOn as $dependency) {
                if (! isset($this->tablesByName[$dependency])) {
                    throw new InvalidArgumentException("Table [{$table->name}] depends on unknown table [{$dependency}].");
                }

                $dependencyDefinition = $this->tablesByName[$dependency];

                if ($dependencyDefinition->syncOrder > $table->syncOrder) {
                    throw new InvalidArgumentException(
                        "Table [{$table->name}] sync_order must be greater than dependency [{$dependency}].",
                    );
                }
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return array<string, mixed>
     */
    private function requireArray(array $config, string $key): array
    {
        $value = $config[$key] ?? null;

        if (! is_array($value)) {
            throw new InvalidArgumentException("Database sync configuration is missing [{$key}].");
        }

        return $value;
    }
}
