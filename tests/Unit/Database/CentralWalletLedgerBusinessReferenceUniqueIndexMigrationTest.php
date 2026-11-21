<?php

namespace Tests\Unit\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class CentralWalletLedgerBusinessReferenceUniqueIndexMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const TABLE = 'central_wallet_ledger_entries';

    private const MIGRATION = '2026_11_21_170000_add_unique_business_reference_credit_to_central_wallet_ledger_entries';

    private const OCTOBER_MIGRATION = '2026_10_13_220000_add_unique_business_reference_to_central_wallet_ledger_entries';

    private const COLUMNS = ['business_reference', 'entry_type'];

    private const CANONICAL_INDEX = 'cw_ledger_business_ref_type_uq';

    private const LEGACY_INDEX = 'cw_ledger_business_ref_entry_type_uq';

    public function test_fresh_install_has_single_equivalent_unique_index(): void
    {
        $this->assertTrue($this->hasEquivalentUniqueIndex());
        $this->assertTrue($this->hasNamedIndex(self::CANONICAL_INDEX));
        $this->assertSame(1, $this->equivalentUniqueIndexCount());
    }

    public function test_repeat_up_is_idempotent_when_october_index_present(): void
    {
        $before = Schema::getIndexes(self::TABLE);

        $this->runNovemberMigrationUp();
        $this->runNovemberMigrationUp();

        $this->assertSame($before, Schema::getIndexes(self::TABLE));
        $this->assertTrue($this->hasNamedIndex(self::CANONICAL_INDEX));
        $this->assertSame(1, $this->equivalentUniqueIndexCount());
    }

    public function test_up_reuses_equivalent_index_under_legacy_name(): void
    {
        $this->dropUniqueByName(self::CANONICAL_INDEX);
        $this->createRawUniqueIndex(self::LEGACY_INDEX);

        $this->runNovemberMigrationUp();

        $this->assertTrue($this->hasNamedIndex(self::LEGACY_INDEX));
        $this->assertFalse($this->hasNamedIndex(self::CANONICAL_INDEX));
        $this->assertSame(1, $this->equivalentUniqueIndexCount());
    }

    public function test_up_creates_canonical_index_when_absent(): void
    {
        $this->dropUniqueByName(self::CANONICAL_INDEX);
        $this->assertFalse($this->hasEquivalentUniqueIndex());

        $this->runNovemberMigrationUp();

        $this->assertTrue($this->hasNamedIndex(self::CANONICAL_INDEX));
        $this->assertTrue($this->hasEquivalentUniqueIndex());
    }

    public function test_up_fails_when_legacy_index_has_incompatible_columns(): void
    {
        $this->dropUniqueByName(self::CANONICAL_INDEX);
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unique(['business_reference'], self::LEGACY_INDEX);
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(self::LEGACY_INDEX);

        $this->runNovemberMigrationUp();
    }

    public function test_up_fails_when_non_unique_index_blocks_required_constraint(): void
    {
        $this->dropUniqueByName(self::CANONICAL_INDEX);
        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->index(self::COLUMNS, 'cw_ledger_probe_non_unique_idx');
        });

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Non-unique index');

        $this->runNovemberMigrationUp();
    }

    public function test_down_preserves_pre_existing_canonical_index(): void
    {
        $this->assertTrue($this->hasNamedIndex(self::CANONICAL_INDEX));

        $this->runNovemberMigrationDown();

        $this->assertTrue($this->hasNamedIndex(self::CANONICAL_INDEX));
        $this->assertTrue($this->hasEquivalentUniqueIndex());
    }

    public function test_down_drops_only_legacy_redundant_index(): void
    {
        $this->dropUniqueByName(self::CANONICAL_INDEX);
        $this->createRawUniqueIndex(self::LEGACY_INDEX);

        $this->runNovemberMigrationUp();
        $this->assertTrue($this->hasNamedIndex(self::LEGACY_INDEX));

        $this->runNovemberMigrationDown();

        $this->assertFalse($this->hasNamedIndex(self::LEGACY_INDEX));
        $this->assertFalse($this->hasEquivalentUniqueIndex());
    }

    public function test_down_does_not_drop_index_created_by_this_migration_on_canonical_name(): void
    {
        $this->dropUniqueByName(self::CANONICAL_INDEX);
        DB::table('migrations')->where('migration', self::OCTOBER_MIGRATION)->delete();

        $this->runNovemberMigrationUp();
        $this->assertTrue($this->hasNamedIndex(self::CANONICAL_INDEX));

        $this->runNovemberMigrationDown();

        $this->assertTrue($this->hasNamedIndex(self::CANONICAL_INDEX));
    }

    private function runNovemberMigrationUp(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $migration->up();
    }

    private function runNovemberMigrationDown(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $migration->down();
    }

    private function hasNamedIndex(string $name): bool
    {
        return collect(Schema::getIndexes(self::TABLE))->contains(
            fn (array $index): bool => ($index['name'] ?? null) === $name,
        );
    }

    private function hasEquivalentUniqueIndex(): bool
    {
        return $this->equivalentUniqueIndexCount() > 0;
    }

    private function equivalentUniqueIndexCount(): int
    {
        return collect(Schema::getIndexes(self::TABLE))
            ->filter(fn (array $index): bool => ($index['unique'] ?? false) === true
                && ($index['columns'] ?? []) === self::COLUMNS)
            ->count();
    }

    private function dropUniqueByName(string $indexName): void
    {
        if (! $this->hasNamedIndex($indexName)) {
            return;
        }

        Schema::table(self::TABLE, function (Blueprint $table) use ($indexName): void {
            $table->dropUnique($indexName);
        });
    }

    private function createRawUniqueIndex(string $indexName): void
    {
        Schema::table(self::TABLE, function (Blueprint $table) use ($indexName): void {
            $table->unique(self::COLUMNS, $indexName);
        });
    }
}
