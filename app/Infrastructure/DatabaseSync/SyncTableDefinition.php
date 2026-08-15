<?php

namespace App\Infrastructure\DatabaseSync;

use InvalidArgumentException;

final readonly class SyncTableDefinition
{
    /**
     * @param  list<string>  $primaryKey
     * @param  list<list<string>>  $uniqueIndexes
     * @param  list<string>  $dependsOn
     */
    public function __construct(
        public string $name,
        public int $tier,
        public SyncCursorStrategy $strategy,
        public array $primaryKey,
        public ?string $updatedAtColumn,
        public ?string $createdAtColumn,
        public int $syncOrder,
        public array $uniqueIndexes = [],
        public array $dependsOn = [],
        public bool $softDeletes = false,
    ) {}

    /**
     * @return list<string>
     */
    public function flattenedUniqueKeys(): array
    {
        $keys = [];

        foreach ($this->uniqueIndexes as $index) {
            foreach ($index as $column) {
                $keys[] = $column;
            }
        }

        return array_values(array_unique($keys));
    }

    /**
     * @param  array<string, mixed>  $config
     */
    public static function fromConfig(string $name, array $config): self
    {
        $strategyValue = $config['strategy'] ?? null;

        if (! is_string($strategyValue) || $strategyValue === '') {
            throw new InvalidArgumentException("Table [{$name}] is missing a cursor strategy.");
        }

        $strategy = SyncCursorStrategy::tryFromConfig($strategyValue);

        if ($strategy === null) {
            throw new InvalidArgumentException("Table [{$name}] has an invalid cursor strategy [{$strategyValue}].");
        }

        $tier = $config['tier'] ?? null;

        if (! is_int($tier) || $tier < 1) {
            throw new InvalidArgumentException("Table [{$name}] must declare a positive integer tier.");
        }

        $syncOrder = $config['sync_order'] ?? null;

        if (! is_int($syncOrder) || $syncOrder < 1) {
            throw new InvalidArgumentException("Table [{$name}] must declare a positive integer sync_order.");
        }

        $primaryKey = self::stringList($config['primary_key'] ?? null, $name, 'primary_key');

        if ($primaryKey === []) {
            throw new InvalidArgumentException("Table [{$name}] must declare at least one primary key column.");
        }

        self::validateStrategyColumns($name, $strategy, $primaryKey, $config);

        return new self(
            name: $name,
            tier: $tier,
            strategy: $strategy,
            primaryKey: $primaryKey,
            updatedAtColumn: self::nullableString($config['updated_at'] ?? null),
            createdAtColumn: self::nullableString($config['created_at'] ?? null),
            syncOrder: $syncOrder,
            uniqueIndexes: self::uniqueIndexes($name, $config),
            dependsOn: self::stringList($config['depends_on'] ?? [], $name, 'depends_on'),
            softDeletes: (bool) ($config['soft_deletes'] ?? false),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'name' => $this->name,
            'tier' => $this->tier,
            'strategy' => $this->strategy->value,
            'primary_key' => $this->primaryKey,
            'updated_at' => $this->updatedAtColumn,
            'created_at' => $this->createdAtColumn,
            'sync_order' => $this->syncOrder,
            'unique_indexes' => $this->uniqueIndexes,
            'unique_keys' => $this->flattenedUniqueKeys(),
            'depends_on' => $this->dependsOn,
            'soft_deletes' => $this->softDeletes,
        ];
    }

    /**
     * @param  array<string, mixed>  $config
     * @param  list<string>  $primaryKey
     */
    private static function validateStrategyColumns(
        string $name,
        SyncCursorStrategy $strategy,
        array $primaryKey,
        array $config,
    ): void {
        $updatedAt = self::nullableString($config['updated_at'] ?? null);
        $createdAt = self::nullableString($config['created_at'] ?? null);

        match ($strategy) {
            SyncCursorStrategy::BigintIdUpdatedAt => self::requireColumns(
                $name,
                [
                    'primary_key' => count($primaryKey) === 1 && $primaryKey[0] === 'id',
                    'updated_at' => $updatedAt === 'updated_at',
                ],
            ),
            SyncCursorStrategy::BigintIdInsertOnly => self::requireColumns(
                $name,
                [
                    'primary_key' => count($primaryKey) === 1 && $primaryKey[0] === 'id',
                    'created_at' => $createdAt === 'created_at',
                ],
            ),
            SyncCursorStrategy::UuidPk => self::requireColumns(
                $name,
                [
                    'primary_key' => count($primaryKey) === 1 && $primaryKey[0] === 'id',
                    'created_at' => $createdAt === 'created_at',
                ],
            ),
            SyncCursorStrategy::StringPk => self::requireColumns(
                $name,
                ['primary_key' => count($primaryKey) === 1],
            ),
            SyncCursorStrategy::CompositePk => self::requireColumns(
                $name,
                ['primary_key' => count($primaryKey) >= 2],
            ),
            SyncCursorStrategy::CreatedAtPk => self::requireColumns(
                $name,
                [
                    'created_at' => $createdAt === 'created_at',
                    'primary_key' => $primaryKey !== [],
                ],
            ),
            SyncCursorStrategy::FullReplace => null,
        };
    }

    /**
     * @param  array<string, bool>  $requirements
     */
    private static function requireColumns(string $name, array $requirements): void
    {
        foreach ($requirements as $field => $valid) {
            if (! $valid) {
                throw new InvalidArgumentException("Table [{$name}] has invalid {$field} for its cursor strategy.");
            }
        }
    }

    /**
     * @param  array<string, mixed>  $config
     * @return list<list<string>>
     */
    private static function uniqueIndexes(string $name, array $config): array
    {
        if (isset($config['unique_indexes'])) {
            return self::nestedStringList($config['unique_indexes'], $name, 'unique_indexes');
        }

        $legacyKeys = self::stringList($config['unique_keys'] ?? [], $name, 'unique_keys');

        if ($legacyKeys === []) {
            return [];
        }

        return self::normalizeLegacyUniqueKeys($name, $legacyKeys);
    }

    /**
     * @param  list<string>  $legacyKeys
     * @return list<list<string>>
     */
    private static function normalizeLegacyUniqueKeys(string $name, array $legacyKeys): array
    {
        $composite = [
            'permissions' => [['name', 'guard_name']],
            'roles' => [['name', 'guard_name']],
            'bonvoice_call_events' => [['call_id', 'leg']],
            'incoming_email_ignore_stats' => [['stat_date', 'reason']],
            'workforce_attendance_days' => [['user_id', 'work_date']],
            'workforce_short_attendance_reviews' => [['user_id', 'work_date']],
            'executive_metric_snapshots' => [['metric_key', 'snapshot_time', 'granularity']],
            'user_metric_snapshots' => [['user_id', 'snapshot_date']],
        ];

        $multi = [
            'orders' => [['order_id'], ['cashfree_payment_id'], ['serial_number']],
            'finance_journals' => [['journal_no'], ['idempotency_key']],
            'incoming_email_messages' => [['rfc_message_id'], ['provider', 'provider_message_id']],
        ];

        if (isset($composite[$name])) {
            return $composite[$name];
        }

        if (isset($multi[$name])) {
            return $multi[$name];
        }

        return array_map(static fn (string $key): array => [$key], $legacyKeys);
    }

    /**
     * @return list<list<string>>
     */
    private static function nestedStringList(mixed $value, string $name, string $field): array
    {
        if (! is_array($value)) {
            throw new InvalidArgumentException("Table [{$name}] field [{$field}] must be an array of string lists.");
        }

        $indexes = [];

        foreach ($value as $index => $columns) {
            if (! is_array($columns)) {
                throw new InvalidArgumentException("Table [{$name}] field [{$field}] must contain string lists.");
            }

            $normalized = [];

            foreach ($columns as $column) {
                if (! is_string($column) || $column === '') {
                    throw new InvalidArgumentException("Table [{$name}] field [{$field}] must contain non-empty strings.");
                }

                $normalized[] = $column;
            }

            if ($normalized !== []) {
                $indexes[] = $normalized;
            }
        }

        return $indexes;
    }

    /**
     * @return list<string>
     */
    private static function stringList(mixed $value, string $name, string $field): array
    {
        if ($value === null) {
            return [];
        }

        if (! is_array($value)) {
            throw new InvalidArgumentException("Table [{$name}] field [{$field}] must be an array of strings.");
        }

        $items = [];

        foreach ($value as $item) {
            if (! is_string($item) || $item === '') {
                throw new InvalidArgumentException("Table [{$name}] field [{$field}] must contain non-empty strings.");
            }

            $items[] = $item;
        }

        return $items;
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed !== '' ? $trimmed : null;
    }
}
