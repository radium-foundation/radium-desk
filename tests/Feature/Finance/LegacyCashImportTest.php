<?php

namespace Tests\Feature\Finance;

use App\Enums\FinanceJournalSourceType;
use App\Models\CashBookEntry;
use App\Models\FinanceAccount;
use App\Models\FinanceCashAccount;
use App\Models\FinanceJournal;
use App\Models\FinanceLegacyCashEntry;
use App\Models\FinanceLegacyCashUserMap;
use App\Models\User;
use App\Services\Finance\AccountBalanceReadModel;
use App\Services\Finance\Data\JournalLineDraft;
use App\Services\Finance\Data\LegacyCashImportResult;
use App\Services\Finance\JournalPostingService;
use App\Services\Finance\LegacyCashImportService;
use App\Services\Finance\LegacyCashSourceReader;
use App\Services\Finance\OpeningBalanceService;
use App\Support\Finance\LegacyCashContract;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Validation\ValidationException;
use Tests\Support\LegacyCashApprovedHistory;
use Tests\TestCase;

class LegacyCashImportTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $gunjan;

    private User $rafaquat;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        User::factory()->count(8)->create(['name' => 'Decoy Staff']);

        $this->gunjan = User::factory()->create([
            'name' => 'Gunjan Kumar',
            'is_active' => false,
        ]);
        $this->rafaquat = User::factory()->create([
            'name' => 'Rafaquat',
            'is_active' => true,
        ]);
        User::factory()->create([
            'name' => 'Avinash',
            'is_active' => true,
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_legacy_cash_schema_exists(): void
    {
        $this->assertTrue(Schema::hasTable('finance_legacy_cash_entries'));
        $this->assertTrue(Schema::hasTable('finance_legacy_cash_user_maps'));
        $this->assertTrue(Schema::hasColumns('finance_legacy_cash_entries', [
            'legacy_source',
            'legacy_database',
            'legacy_table',
            'legacy_transaction_id',
            'idempotency_key',
            'original_created_at',
            'original_amount_raw',
            'amount',
            'entry_type',
            'amount_type',
            'description',
            'legacy_created_by',
            'legacy_admin_name',
            'desk_user_id',
            'import_status',
            'review_status',
            'review_reason',
            'imported_at',
        ]));
    }

    public function test_approved_users_map_by_name_not_numeric_id(): void
    {
        $this->assertNotSame(4, $this->gunjan->id);
        $this->assertNotSame(6, $this->rafaquat->id);

        $result = $this->import(LegacyCashApprovedHistory::mappingSample(), dryRun: false);

        $this->assertSame(4, $result->sourceRows);
        $this->assertSame(2, $result->mappedRows);
        $this->assertSame(2, $result->unmappedRows);

        $gunjanRow = FinanceLegacyCashEntry::query()->where('legacy_transaction_id', 10)->firstOrFail();
        $rafaquatRow = FinanceLegacyCashEntry::query()->where('legacy_transaction_id', 11)->firstOrFail();
        $avinashRow = FinanceLegacyCashEntry::query()->where('legacy_transaction_id', 12)->firstOrFail();
        $reviewRow = FinanceLegacyCashEntry::query()->where('legacy_transaction_id', 157)->firstOrFail();

        $this->assertSame($this->gunjan->id, $gunjanRow->desk_user_id);
        $this->assertSame('4', $gunjanRow->legacy_created_by);
        $this->assertSame('Gunjan Kumar', $gunjanRow->legacy_admin_name);
        $this->assertSame($this->rafaquat->id, $rafaquatRow->desk_user_id);
        $this->assertSame('6', $rafaquatRow->legacy_created_by);
        $this->assertNull($avinashRow->desk_user_id);
        $this->assertSame('10', $avinashRow->legacy_created_by);
        $this->assertTrue($reviewRow->needsReview());
        $this->assertStringContainsString('UNKNOWN', (string) $reviewRow->review_reason);

        $this->assertSame(
            FinanceLegacyCashUserMap::STATUS_MAPPED,
            FinanceLegacyCashUserMap::query()->where('legacy_admin_id', '4')->value('mapping_status'),
        );
        $this->assertSame(
            FinanceLegacyCashUserMap::STATUS_UNMAPPED,
            FinanceLegacyCashUserMap::query()->where('legacy_admin_id', '10')->value('mapping_status'),
        );
    }

    public function test_ambiguous_rafaquat_name_is_left_unmapped(): void
    {
        User::factory()->create(['name' => 'Rafaquat Rana']);

        $this->import([
            [
                'id' => 20,
                'created_by' => '6',
                'amount' => '10',
                'type' => 'debit',
                'amount_type' => 'Office expenses',
                'description' => 'Ambiguous mapping fixture',
                'created_at' => '2024-03-01 09:00:00',
                'updated_at' => '2024-03-01 09:00:00',
                'admin_name' => 'Rafaquat Rana',
            ],
        ], dryRun: false);

        $row = FinanceLegacyCashEntry::query()->where('legacy_transaction_id', 20)->firstOrFail();
        $this->assertNull($row->desk_user_id);
        $this->assertSame(
            FinanceLegacyCashUserMap::STATUS_AMBIGUOUS,
            FinanceLegacyCashUserMap::query()->where('legacy_admin_id', '6')->value('mapping_status'),
        );
    }

    public function test_approved_totals_reconcile_and_excluded_ids_remain_absent(): void
    {
        $result = $this->import(LegacyCashApprovedHistory::rows(), dryRun: false);

        $this->assertTrue($result->matchesApprovedTotals());
        $this->assertSame(1765, FinanceLegacyCashEntry::query()->count());
        $this->assertSame(675, FinanceLegacyCashEntry::query()->where('entry_type', 'credit')->count());
        $this->assertSame(1090, FinanceLegacyCashEntry::query()->where('entry_type', 'debit')->count());
        $this->assertSame(
            LegacyCashContract::EXPECTED_CREDIT_TOTAL,
            LegacyCashContract::money((string) FinanceLegacyCashEntry::query()->where('entry_type', 'credit')->sum('amount')),
        );
        $this->assertSame(
            LegacyCashContract::EXPECTED_DEBIT_TOTAL,
            LegacyCashContract::money((string) FinanceLegacyCashEntry::query()->where('entry_type', 'debit')->sum('amount')),
        );
        $this->assertSame(0, FinanceLegacyCashEntry::query()->whereIn('legacy_transaction_id', LegacyCashContract::EXCLUDED_LEGACY_IDS)->count());
        $this->assertSame(4, FinanceLegacyCashEntry::query()->where('review_status', FinanceLegacyCashEntry::REVIEW_NEEDS_REVIEW)->count());
        $this->assertSame(0, CashBookEntry::query()->count());
        $this->assertSame(0, FinanceJournal::query()->count());
    }

    public function test_rerun_is_idempotent(): void
    {
        $rows = LegacyCashApprovedHistory::mappingSample();
        $this->import($rows, dryRun: false);
        $second = $this->import($rows, dryRun: false);

        $this->assertSame(0, $second->imported);
        $this->assertSame(4, $second->skippedExisting);
        $this->assertSame(4, FinanceLegacyCashEntry::query()->count());
        $this->assertSame(0, CashBookEntry::query()->count());
        $this->assertSame(0, FinanceJournal::query()->count());
    }

    public function test_dry_run_writes_nothing(): void
    {
        $result = $this->import(LegacyCashApprovedHistory::mappingSample(), dryRun: true);

        $this->assertTrue($result->dryRun);
        $this->assertSame(0, FinanceLegacyCashEntry::query()->count());
        $this->assertSame(0, FinanceLegacyCashUserMap::query()->count());
        $this->assertSame(0, FinanceJournal::query()->count());
    }

    public function test_hard_deleted_source_ids_are_rejected(): void
    {
        $this->expectException(ValidationException::class);

        $this->import([
            [
                'id' => 1,
                'created_by' => '10',
                'amount' => '10',
                'type' => 'debit',
                'amount_type' => 'Office expenses',
                'description' => 'Must not be fabricated',
                'created_at' => '2024-01-01 09:00:00',
                'updated_at' => '2024-01-01 09:00:00',
                'admin_name' => 'Avinash',
            ],
        ], dryRun: false);
    }

    public function test_opening_journal_is_isolated_from_legacy_rows_and_generic_cash_opening(): void
    {
        $cash = FinanceAccount::query()->where('code', '1000')->firstOrFail();
        $unrelated = app(JournalPostingService::class)->post(
            sourceType: FinanceJournalSourceType::ManualAdjustment,
            sourceId: null,
            idempotencyKey: 'unrelated:pre-legacy',
            memo: 'Existing Desk finance entry',
            entryDate: '2026-08-25',
            lines: [
                JournalLineDraft::debit($cash->id, 50.00, 'Existing cash'),
                JournalLineDraft::credit(
                    FinanceAccount::query()->where('code', '3000')->value('id'),
                    50.00,
                    'Existing equity',
                ),
            ],
            actor: $this->admin,
        );

        $balanceBefore = app(AccountBalanceReadModel::class)->compute($cash->id);

        $this->import(LegacyCashApprovedHistory::rows(), dryRun: false);

        $this->assertSame(1, FinanceJournal::query()->count());
        $this->assertSame($unrelated->id, FinanceJournal::query()->value('id'));
        $this->assertSame(0, CashBookEntry::query()->count());
        $this->assertSame(
            $balanceBefore,
            app(AccountBalanceReadModel::class)->compute($cash->id),
        );

        $opening = app(OpeningBalanceService::class)->postLegacyAdminCashOpening(
            FinanceCashAccount::query()->where('name', 'Main Cash Drawer')->firstOrFail(),
            $this->admin,
        );
        $again = app(OpeningBalanceService::class)->postLegacyAdminCashOpening(
            FinanceCashAccount::query()->where('name', 'Main Cash Drawer')->firstOrFail(),
            $this->admin,
        );

        $this->assertSame($opening->id, $again->id);
        $this->assertSame(LegacyCashContract::OPENING_MEMO, $opening->memo);
        $this->assertSame(LegacyCashContract::OPENING_IDEMPOTENCY_KEY, $opening->idempotency_key);
        $this->assertSame(LegacyCashContract::EXPECTED_NET, $opening->totalDebits());
        $this->assertSame(2, FinanceJournal::query()->count());
        $this->assertSame(0, CashBookEntry::query()->count());
        $this->assertSame(0, FinanceJournal::query()->where('idempotency_key', 'like', 'legacy:radiumbox_prod:expenses:%')->count());
        $this->assertSame(0, FinanceJournal::query()->where('idempotency_key', 'like', 'opening:cash:%')->count());
        $this->assertSame(
            LegacyCashContract::money($balanceBefore + (float) LegacyCashContract::OPENING_AMOUNT),
            LegacyCashContract::money((string) app(AccountBalanceReadModel::class)->compute($cash->id)),
        );
        $this->assertTrue(FinanceJournal::query()->whereKey($unrelated->id)->exists());
    }

    public function test_artisan_command_dry_run_and_apply_from_json(): void
    {
        $path = sys_get_temp_dir().'/legacy-cash-'.uniqid('', true).'.json';
        file_put_contents($path, json_encode(LegacyCashApprovedHistory::mappingSample(), JSON_THROW_ON_ERROR));

        $this->artisan('finance:import-legacy-cash', ['--json' => $path])
            ->assertSuccessful()
            ->expectsOutputToContain('Dry-run only');

        $this->assertSame(0, FinanceLegacyCashEntry::query()->count());

        $this->artisan('finance:import-legacy-cash', [
            '--json' => $path,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(4, FinanceLegacyCashEntry::query()->count());

        $this->artisan('finance:import-legacy-cash', [
            '--json' => $path,
            '--apply' => true,
        ])->assertSuccessful();

        $this->assertSame(4, FinanceLegacyCashEntry::query()->count());

        unlink($path);
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     */
    private function import(array $rows, bool $dryRun): LegacyCashImportResult
    {
        $hydrated = app(LegacyCashSourceReader::class)->hydrate($rows);

        return app(LegacyCashImportService::class)->import($hydrated, $dryRun);
    }
}
