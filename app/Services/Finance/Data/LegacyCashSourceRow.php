<?php

namespace App\Services\Finance\Data;

use App\Support\Finance\LegacyCashContract;
use InvalidArgumentException;

readonly class LegacyCashSourceRow
{
    public function __construct(
        public int $id,
        public string $createdBy,
        public string $amountRaw,
        public string $type,
        public ?string $amountType,
        public ?string $description,
        public string $createdAt,
        public ?string $updatedAt,
        public ?string $adminName,
    ) {}

    /**
     * @param  array<string, mixed>  $row
     */
    public static function fromArray(array $row): self
    {
        $id = (int) ($row['id'] ?? 0);
        if ($id <= 0) {
            throw new InvalidArgumentException('Legacy expense id is required.');
        }

        $type = strtolower(trim((string) ($row['type'] ?? '')));
        if (! in_array($type, ['credit', 'debit'], true)) {
            throw new InvalidArgumentException('Legacy expense '.$id.' has invalid type.');
        }

        $amountRaw = trim((string) ($row['amount'] ?? ''));
        if ($amountRaw === '' || ! is_numeric($amountRaw)) {
            throw new InvalidArgumentException('Legacy expense '.$id.' has a non-numeric amount.');
        }

        $createdAt = trim((string) ($row['created_at'] ?? ''));
        if ($createdAt === '') {
            throw new InvalidArgumentException('Legacy expense '.$id.' is missing created_at.');
        }

        $adminName = $row['admin_name'] ?? $row['name'] ?? null;

        return new self(
            id: $id,
            createdBy: trim((string) ($row['created_by'] ?? '')),
            amountRaw: $amountRaw,
            type: $type,
            amountType: self::nullableString($row['amount_type'] ?? null),
            description: self::nullableString($row['description'] ?? null),
            createdAt: $createdAt,
            updatedAt: self::nullableString($row['updated_at'] ?? null),
            adminName: self::nullableString($adminName),
        );
    }

    public function amount(): string
    {
        return LegacyCashContract::money($this->amountRaw);
    }

    public function signedRupees(): string
    {
        $amount = $this->amount();

        return $this->type === 'credit' ? $amount : LegacyCashContract::money(-1 * (float) $amount);
    }

    private static function nullableString(mixed $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $text = trim((string) $value);

        return $text === '' ? null : $text;
    }
}
