<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'central_wallet_ledger_entries';

    /**
     * @var list<string>
     */
    private const UNIQUE_COLUMNS = ['business_reference', 'entry_type'];

    /** Canonical unique index from {@see 2026_10_13_220000_add_unique_business_reference_to_central_wallet_ledger_entries}. */
    private const CANONICAL_INDEX = 'cw_ledger_business_ref_type_uq';

    /** Redundant name introduced in PR #399; only dropped on down() when present. */
    private const LEGACY_INDEX = 'cw_ledger_business_ref_entry_type_uq';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        $this->assertLegacyIndexNameIsCompatible();

        if ($this->hasEquivalentUniqueIndex()) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unique(self::UNIQUE_COLUMNS, self::CANONICAL_INDEX);
        });
    }

    public function down(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            return;
        }

        if ($this->hasNamedIndex(self::LEGACY_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropUnique(self::LEGACY_INDEX);
            });
        }
    }

    private function assertLegacyIndexNameIsCompatible(): void
    {
        if (! $this->hasNamedIndex(self::LEGACY_INDEX)) {
            return;
        }

        $definition = $this->namedIndexDefinition(self::LEGACY_INDEX);
        if ($definition === null) {
            return;
        }

        if ($this->isEquivalentUniqueIndex($definition)) {
            return;
        }

        throw new RuntimeException(
            'Incompatible unique index '.self::LEGACY_INDEX.' on '.self::TABLE
            .': expected unique ('.implode(', ', self::UNIQUE_COLUMNS).').',
        );
    }

    private function hasEquivalentUniqueIndex(): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if ($this->isEquivalentUniqueIndex($index)) {
                return true;
            }
        }

        if ($this->hasNonUniqueIndexOnColumns()) {
            throw new RuntimeException(
                'Non-unique index on ('.implode(', ', self::UNIQUE_COLUMNS).') blocks '
                .self::CANONICAL_INDEX.' on '.self::TABLE.'.',
            );
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $index
     */
    private function isEquivalentUniqueIndex(array $index): bool
    {
        return ($index['unique'] ?? false) === true
            && ($index['columns'] ?? []) === self::UNIQUE_COLUMNS;
    }

    private function hasNonUniqueIndexOnColumns(): bool
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['unique'] ?? false) !== true
                && ($index['columns'] ?? []) === self::UNIQUE_COLUMNS) {
                return true;
            }
        }

        return false;
    }

    private function hasNamedIndex(string $indexName): bool
    {
        return $this->namedIndexDefinition($indexName) !== null;
    }

    /**
     * @return array<string, mixed>|null
     */
    private function namedIndexDefinition(string $indexName): ?array
    {
        foreach (Schema::getIndexes(self::TABLE) as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return $index;
            }
        }

        return null;
    }
};
