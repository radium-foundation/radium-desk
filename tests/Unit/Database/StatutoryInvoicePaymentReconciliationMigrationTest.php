<?php

namespace Tests\Unit\Database;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class StatutoryInvoicePaymentReconciliationMigrationTest extends TestCase
{
    use RefreshDatabase;

    private const MIGRATION = '2026_09_24_130000_create_statutory_invoice_payment_reconciliations_table';

    private const TABLE = 'statutory_invoice_payment_reconciliations';

    /**
     * @var list<string>
     */
    private const SHORT_NAMES = [
        'si_pay_recon_invoice_fk',
        'si_pay_recon_payment_fk',
        'si_pay_recon_alloc_fk',
        'si_pay_recon_user_fk',
        'si_pay_recon_invoice_uq',
        'si_pay_recon_idempotency_uq',
        'si_pay_recon_completed_idx',
        'cp_source_idx',
    ];

    /**
     * @var list<string>
     */
    private const LONG_LARAVEL_NAMES = [
        'statutory_invoice_payment_reconciliations_statutory_invoice_id_foreign',
        'statutory_invoice_payment_reconciliations_customer_payment_id_foreign',
        'statutory_invoice_payment_reconciliations_payment_allocation_id_foreign',
        'statutory_invoice_payment_reconciliations_statutory_invoice_id_unique',
    ];

    public function test_laravel_default_names_exceed_mysql_limit_and_short_names_do_not(): void
    {
        foreach (self::LONG_LARAVEL_NAMES as $name) {
            $this->assertGreaterThan(64, strlen($name), $name);
        }

        foreach (self::SHORT_NAMES as $name) {
            $this->assertLessThanOrEqual(64, strlen($name), $name);
        }

        $this->assertSame(
            64,
            strlen('statutory_invoice_payment_reconciliations_idempotency_key_unique'),
        );
    }

    public function test_case_a_fresh_install_creates_intended_schema_with_short_names(): void
    {
        $this->assertTrue(Schema::hasTable(self::TABLE));
        $this->assertTrue(Schema::hasColumn('customer_payments', 'source'));
        $this->assertTrue(Schema::hasColumn('customer_payments', 'bank_name'));
        $this->assertTrue(Schema::hasColumn('customer_payments', 'bank_branch'));

        $this->assertForeignKey(
            self::TABLE,
            'statutory_invoice_id',
            'statutory_invoices',
            'si_pay_recon_invoice_fk',
            'cascade',
        );
        $this->assertForeignKey(
            self::TABLE,
            'customer_payment_id',
            'customer_payments',
            'si_pay_recon_payment_fk',
            'set null',
        );
        $this->assertForeignKey(
            self::TABLE,
            'payment_allocation_id',
            'payment_allocations',
            'si_pay_recon_alloc_fk',
            'set null',
        );
        $this->assertForeignKey(
            self::TABLE,
            'recorded_by',
            'users',
            'si_pay_recon_user_fk',
            'set null',
        );

        $indexes = collect(Schema::getIndexes(self::TABLE));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['name'] === 'si_pay_recon_invoice_uq'
                && ($index['unique'] ?? false) === true
                && ($index['columns'] ?? []) === ['statutory_invoice_id'],
        ));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['name'] === 'si_pay_recon_idempotency_uq'
                && ($index['unique'] ?? false) === true
                && ($index['columns'] ?? []) === ['idempotency_key'],
        ));
        $this->assertTrue($indexes->contains(
            fn (array $index): bool => $index['name'] === 'si_pay_recon_completed_idx'
                && ($index['columns'] ?? []) === ['completed_at'],
        ));

        $this->assertTrue(collect(Schema::getIndexes('customer_payments'))->contains(
            fn (array $index): bool => $index['name'] === 'cp_source_idx'
                && ($index['columns'] ?? []) === ['source'],
        ));
    }

    public function test_case_b_partial_deploy_recovery_adds_missing_foreign_keys(): void
    {
        $this->simulateOrphanReconciliationTableWithoutForeignKeys();

        $this->runMigrationUp();

        $this->assertForeignKey(
            self::TABLE,
            'statutory_invoice_id',
            'statutory_invoices',
            'si_pay_recon_invoice_fk',
            'cascade',
        );
        $this->assertForeignKey(
            self::TABLE,
            'payment_allocation_id',
            'payment_allocations',
            'si_pay_recon_alloc_fk',
            'set null',
        );
    }

    public function test_case_c_already_applied_schema_is_idempotent_on_repeat_up(): void
    {
        $beforeForeignKeys = Schema::getForeignKeys(self::TABLE);
        $beforeIndexes = Schema::getIndexes(self::TABLE);

        $this->runMigrationUp();

        $this->assertSame($beforeForeignKeys, Schema::getForeignKeys(self::TABLE));
        $this->assertSame($beforeIndexes, Schema::getIndexes(self::TABLE));
    }

    public function test_case_d_non_empty_table_stops_safely(): void
    {
        $invoiceId = DB::table('statutory_invoices')->insertGetId([
            'invoice_number' => 'INV-MIGRATION-GUARD-1',
            'document_type' => 'tax_invoice',
            'status' => 'issued',
            'channel' => 'desk_pos',
            'source_type' => 'inventory_sale',
            'source_id' => 'POS-MIG-GUARD-1',
            'idempotency_key' => 'inv-migration-guard-1',
            'taxable_value' => 100,
            'tax_total' => 18,
            'invoice_value' => 118,
            'issued_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table(self::TABLE)->insert([
            'statutory_invoice_id' => $invoiceId,
            'outcome' => 'paid',
            'verified_amount' => 118,
            'source' => 'historical_pos_backfill',
            'idempotency_key' => 'guard-non-empty',
            'completed_at' => now(),
            'locked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        try {
            $this->runMigrationUp();
            $this->fail('Expected migration recovery to stop on non-empty reconciliation table.');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('contains existing rows', $exception->getMessage());
        }
    }

    public function test_case_e_existing_customer_payment_columns_are_not_duplicated(): void
    {
        $this->assertTrue(Schema::hasColumn('customer_payments', 'source'));
        $this->assertTrue(Schema::hasColumn('customer_payments', 'bank_name'));
        $this->assertTrue(Schema::hasColumn('customer_payments', 'bank_branch'));

        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        $this->runMigrationUp();

        $this->assertTrue(Schema::hasColumn('customer_payments', 'source'));
        $this->assertTrue(Schema::hasColumn('customer_payments', 'bank_name'));
        $this->assertTrue(Schema::hasColumn('customer_payments', 'bank_branch'));
        $this->assertSame(1, collect(Schema::getIndexes('customer_payments'))
            ->where('name', 'cp_source_idx')
            ->count());
    }

    private function simulateOrphanReconciliationTableWithoutForeignKeys(): void
    {
        Schema::dropIfExists(self::TABLE);
        DB::table('migrations')->where('migration', self::MIGRATION)->delete();

        Schema::create(self::TABLE, function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('statutory_invoice_id');
            $table->string('outcome', 32);
            $table->decimal('verified_amount', 12, 2)->default(0);
            $table->string('payment_method', 64)->nullable();
            $table->date('payment_date')->nullable();
            $table->string('bank_name', 120)->nullable();
            $table->string('bank_branch', 120)->nullable();
            $table->string('reference', 128)->nullable();
            $table->text('verification_remark')->nullable();
            $table->string('source', 40)->default('historical_pos_backfill');
            $table->unsignedBigInteger('customer_payment_id')->nullable();
            $table->unsignedBigInteger('payment_allocation_id')->nullable();
            $table->unsignedBigInteger('recorded_by')->nullable();
            $table->string('idempotency_key', 120);
            $table->timestamp('completed_at');
            $table->timestamp('locked_at');
            $table->timestamps();
        });
    }

    private function runMigrationUp(): void
    {
        $migration = require database_path('migrations/'.self::MIGRATION.'.php');
        $migration->up();
    }

    private function assertForeignKey(
        string $table,
        string $column,
        string $foreignTable,
        string $expectedName,
        string $expectedOnDelete,
    ): void {
        $foreign = collect(Schema::getForeignKeys($table))->first(
            fn (array $definition): bool => ($definition['columns'] ?? []) === [$column],
        );

        $this->assertIsArray($foreign, "Missing foreign key on {$table}.{$column}");
        $this->assertSame(['id'], $foreign['foreign_columns'] ?? null);
        $this->assertSame($foreignTable, $foreign['foreign_table'] ?? null);

        if (($foreign['name'] ?? null) !== null) {
            $this->assertSame($expectedName, $foreign['name']);
            $this->assertLessThanOrEqual(64, strlen((string) $foreign['name']));
        }

        $onDelete = $foreign['on_delete'] ?? $foreign['onDelete'] ?? null;
        $this->assertSame($expectedOnDelete, $onDelete);
    }
}
