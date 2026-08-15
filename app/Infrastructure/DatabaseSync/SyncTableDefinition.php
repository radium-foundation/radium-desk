<?php

namespace App\Infrastructure\DatabaseSync;

use InvalidArgumentException;

final readonly class SyncTableDefinition
{
    /**
     * @param  list<string>  $primaryKey
     * @param  list<string>  $uniqueKeys
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
        public array $uniqueKeys = [],
        public array $dependsOn = [],
        public bool $softDeletes = false,
    ) {}

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
            uniqueKeys: self::stringList($config['unique_keys'] ?? [], $name, 'unique_keys'),
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
            'unique_keys' => $this->uniqueKeys,
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
