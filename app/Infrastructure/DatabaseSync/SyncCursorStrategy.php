<?php

namespace App\Infrastructure\DatabaseSync;

enum SyncCursorStrategy: string
{
    case BigintIdUpdatedAt = 'bigint_id+updated_at';
    case BigintIdInsertOnly = 'bigint_id_insert_only';
    case CompositePk = 'composite_pk';
    case UuidPk = 'uuid_pk';
    case StringPk = 'string_pk';
    case CreatedAtPk = 'created_at+pk';
    case FullReplace = 'full_replace';

    /**
     * @return list<string>
     */
    public static function values(): array
    {
        return array_map(
            static fn (self $strategy): string => $strategy->value,
            self::cases(),
        );
    }

    public static function tryFromConfig(string $value): ?self
    {
        return self::tryFrom($value);
    }

    public function supportsMaxPrimaryKey(): bool
    {
        return match ($this) {
            self::BigintIdUpdatedAt,
            self::BigintIdInsertOnly,
            self::CreatedAtPk => true,
            default => false,
        };
    }

    public function supportsMaxUpdatedAt(): bool
    {
        return $this === self::BigintIdUpdatedAt;
    }

    public function supportsMaxCreatedAt(): bool
    {
        return match ($this) {
            self::BigintIdUpdatedAt,
            self::BigintIdInsertOnly,
            self::CreatedAtPk,
            self::UuidPk => true,
            default => false,
        };
    }
}
