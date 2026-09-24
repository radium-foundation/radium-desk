<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    private const TABLE = 'statutory_invoice_payment_reconciliations';

    private const FK_INVOICE = 'si_pay_recon_invoice_fk';

    private const FK_PAYMENT = 'si_pay_recon_payment_fk';

    private const FK_ALLOCATION = 'si_pay_recon_alloc_fk';

    private const FK_USER = 'si_pay_recon_user_fk';

    private const UQ_INVOICE = 'si_pay_recon_invoice_uq';

    private const UQ_IDEMPOTENCY = 'si_pay_recon_idempotency_uq';

    private const IDX_COMPLETED = 'si_pay_recon_completed_idx';

    private const CP_SOURCE_IDX = 'cp_source_idx';

    /**
     * @var list<string>
     */
    private const EXPECTED_COLUMNS = [
        'id',
        'statutory_invoice_id',
        'outcome',
        'verified_amount',
        'payment_method',
        'payment_date',
        'bank_name',
        'bank_branch',
        'reference',
        'verification_remark',
        'source',
        'customer_payment_id',
        'payment_allocation_id',
        'recorded_by',
        'idempotency_key',
        'completed_at',
        'locked_at',
        'created_at',
        'updated_at',
    ];

    public function up(): void
    {
        $this->ensureCustomerPaymentSourceColumn();

        if (! Schema::hasTable(self::TABLE)) {
            $this->createReconciliationTable();

            return;
        }

        $this->recoverPartialReconciliationTable();
    }

    public function down(): void
    {
        if (Schema::hasTable(self::TABLE)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                foreach ([self::FK_INVOICE, self::FK_PAYMENT, self::FK_ALLOCATION, self::FK_USER] as $foreignKey) {
                    if ($this->hasNamedForeignKey(self::TABLE, $foreignKey)) {
                        $table->dropForeign($foreignKey);
                    }
                }
            });

            Schema::dropIfExists(self::TABLE);
        }

        if (Schema::hasColumn('customer_payments', 'source')) {
            Schema::table('customer_payments', function (Blueprint $table): void {
                if ($this->hasNamedIndex('customer_payments', self::CP_SOURCE_IDX)) {
                    $table->dropIndex(self::CP_SOURCE_IDX);
                }

                $table->dropColumn('source');
            });
        }
    }

    private function ensureCustomerPaymentSourceColumn(): void
    {
        if (! Schema::hasColumn('customer_payments', 'source')) {
            Schema::table('customer_payments', function (Blueprint $table): void {
                $table->string('source', 40)->default('finance_receipt')->after('idempotency_key');
            });
        }

        $this->ensureNamedIndex('customer_payments', self::CP_SOURCE_IDX, ['source']);
    }

    private function createReconciliationTable(): void
    {
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

            $table->foreign('statutory_invoice_id', self::FK_INVOICE)
                ->references('id')
                ->on('statutory_invoices')
                ->cascadeOnDelete();
            $table->foreign('customer_payment_id', self::FK_PAYMENT)
                ->references('id')
                ->on('customer_payments')
                ->nullOnDelete();
            $table->foreign('payment_allocation_id', self::FK_ALLOCATION)
                ->references('id')
                ->on('payment_allocations')
                ->nullOnDelete();
            $table->foreign('recorded_by', self::FK_USER)
                ->references('id')
                ->on('users')
                ->nullOnDelete();

            $table->unique('statutory_invoice_id', self::UQ_INVOICE);
            $table->unique('idempotency_key', self::UQ_IDEMPOTENCY);
            $table->index('completed_at', self::IDX_COMPLETED);
        });
    }

    private function recoverPartialReconciliationTable(): void
    {
        $rowCount = DB::table(self::TABLE)->count();
        if ($rowCount > 0) {
            throw new \RuntimeException(
                self::TABLE.' contains existing rows; manual intervention required before migration recovery.',
            );
        }

        foreach (self::EXPECTED_COLUMNS as $column) {
            if (! Schema::hasColumn(self::TABLE, $column)) {
                throw new \RuntimeException(
                    'Partial '.self::TABLE.' table is missing expected column '.$column.'.',
                );
            }
        }

        $this->ensureForeignKey(self::TABLE, 'statutory_invoice_id', 'statutory_invoices', self::FK_INVOICE, 'cascade');
        $this->ensureForeignKey(self::TABLE, 'customer_payment_id', 'customer_payments', self::FK_PAYMENT, 'set null');
        $this->ensureForeignKey(self::TABLE, 'payment_allocation_id', 'payment_allocations', self::FK_ALLOCATION, 'set null');
        $this->ensureForeignKey(self::TABLE, 'recorded_by', 'users', self::FK_USER, 'set null');

        $this->ensureUniqueIndex(self::TABLE, 'statutory_invoice_id', self::UQ_INVOICE);
        $this->ensureUniqueIndex(self::TABLE, 'idempotency_key', self::UQ_IDEMPOTENCY);
        $this->ensureNamedIndex(self::TABLE, self::IDX_COMPLETED, ['completed_at']);
    }

    private function ensureForeignKey(
        string $table,
        string $column,
        string $foreignTable,
        string $constraintName,
        string $onDelete,
    ): void {
        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['columns'] ?? []) === [$column]) {
                return;
            }
        }

        Schema::table($table, function (Blueprint $tableBlueprint) use ($column, $foreignTable, $constraintName, $onDelete): void {
            $foreign = $tableBlueprint->foreign($column, $constraintName)
                ->references('id')
                ->on($foreignTable);

            if ($onDelete === 'cascade') {
                $foreign->cascadeOnDelete();
            } elseif ($onDelete === 'set null') {
                $foreign->nullOnDelete();
            }
        });
    }

    private function ensureUniqueIndex(string $table, string $column, string $indexName): void
    {
        if ($this->hasNamedIndex($table, $indexName) || $this->hasUniqueOnColumn($table, $column)) {
            return;
        }

        Schema::table($table, function (Blueprint $tableBlueprint) use ($column, $indexName): void {
            $tableBlueprint->unique($column, $indexName);
        });
    }

    /**
     * @param  list<string>  $columns
     */
    private function ensureNamedIndex(string $table, string $indexName, array $columns): void
    {
        if ($this->hasNamedIndex($table, $indexName) || $this->hasIndexOnColumns($table, $columns)) {
            return;
        }

        Schema::table($table, function (Blueprint $tableBlueprint) use ($indexName, $columns): void {
            $tableBlueprint->index($columns, $indexName);
        });
    }

    private function hasNamedForeignKey(string $table, string $constraintName): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getForeignKeys($table) as $foreignKey) {
            if (($foreignKey['name'] ?? null) === $constraintName) {
                return true;
            }
        }

        return false;
    }

    private function hasNamedIndex(string $table, string $indexName): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (($index['name'] ?? null) === $indexName) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $columns
     */
    private function hasIndexOnColumns(string $table, array $columns): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (($index['columns'] ?? []) === $columns) {
                return true;
            }
        }

        return false;
    }

    private function hasUniqueOnColumn(string $table, string $column): bool
    {
        if (! Schema::hasTable($table)) {
            return false;
        }

        foreach (Schema::getIndexes($table) as $index) {
            if (($index['unique'] ?? false) === true && ($index['columns'] ?? []) === [$column]) {
                return true;
            }
        }

        return false;
    }
};
