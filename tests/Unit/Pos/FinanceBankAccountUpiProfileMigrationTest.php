<?php

namespace Tests\Unit\Pos;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class FinanceBankAccountUpiProfileMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_explicit_upi_profile_foreign_key_name_fits_mariadb_identifier_limit(): void
    {
        $defaultName = 'finance_bank_account_upi_profiles_finance_bank_account_id_foreign';
        $explicitName = 'fba_upi_profiles_account_fk';

        $this->assertGreaterThan(64, strlen($defaultName));
        $this->assertLessThanOrEqual(64, strlen($explicitName));
        $this->assertLessThanOrEqual(64, strlen('fba_upi_profiles_account_unique'));
        $this->assertLessThanOrEqual(64, strlen('fba_upi_profiles_enabled_idx'));
    }

    public function test_upi_profile_migration_creates_intended_columns_and_named_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('finance_bank_account_upi_profiles'));
        $this->assertTrue(Schema::hasColumns('finance_bank_account_upi_profiles', [
            'id',
            'finance_bank_account_id',
            'vpa',
            'payee_name',
            'is_enabled',
            'created_at',
            'updated_at',
        ]));

        $indexes = collect(Schema::getIndexes('finance_bank_account_upi_profiles'));

        $this->assertTrue(
            $indexes->contains(
                fn (array $index): bool => $index['name'] === 'fba_upi_profiles_account_unique'
                    && $index['unique'] === true
                    && $index['columns'] === ['finance_bank_account_id']
            ),
            'Expected unique index fba_upi_profiles_account_unique on finance_bank_account_id.'
        );
        $this->assertTrue(
            $indexes->contains(
                fn (array $index): bool => $index['name'] === 'fba_upi_profiles_enabled_idx'
                    && $index['columns'] === ['is_enabled', 'finance_bank_account_id']
            ),
            'Expected enabled index fba_upi_profiles_enabled_idx.'
        );

        $foreigns = collect(Schema::getForeignKeys('finance_bank_account_upi_profiles'));
        $accountForeign = $foreigns->first(
            fn (array $foreign): bool => $foreign['columns'] === ['finance_bank_account_id']
        );

        $this->assertIsArray($accountForeign);
        $this->assertSame(['id'], $accountForeign['foreign_columns']);
        $this->assertSame('finance_bank_accounts', $accountForeign['foreign_table']);
        $this->assertSame('cascade', $accountForeign['on_delete'] ?? $accountForeign['onDelete'] ?? 'cascade');
        if (($accountForeign['name'] ?? '') === 'fba_upi_profiles_account_fk') {
            $this->assertLessThanOrEqual(64, strlen($accountForeign['name']));
        }
    }
}
