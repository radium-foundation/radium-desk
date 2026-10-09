<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inter_branch_transactions', function (Blueprint $table) {
            $table->unsignedBigInteger('legacy_inventory_sale_id')->nullable()->after('inventory_reservation_id');
            $table->string('reconciliation_mode', 64)->nullable()->after('legacy_inventory_sale_id');
            $table->timestamp('reconciled_at')->nullable()->after('reconciliation_mode');
            $table->unsignedBigInteger('reconciled_by')->nullable()->after('reconciled_at');

            $table->foreign('legacy_inventory_sale_id', 'ibt_legacy_sale_fk')
                ->references('id')
                ->on('inventory_sales')
                ->nullOnDelete();
            $table->foreign('reconciled_by', 'ibt_reconciled_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->unique('legacy_inventory_sale_id', 'ibt_legacy_sale_uidx');
        });

        Schema::create('inter_branch_reconciliation_audits', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('inter_branch_transaction_id');
            $table->unsignedBigInteger('actor_user_id')->nullable();
            $table->unsignedBigInteger('inventory_sale_id');
            $table->unsignedBigInteger('statutory_invoice_id');
            $table->unsignedBigInteger('from_branch_id');
            $table->unsignedBigInteger('to_branch_id');
            $table->unsignedInteger('serial_count')->default(0);
            $table->string('reconciliation_mode', 64);
            $table->string('idempotency_key', 120);
            $table->text('reason')->nullable();
            $table->string('finance_treatment', 64);
            $table->unsignedBigInteger('finance_journal_id')->nullable();
            $table->json('before_state');
            $table->json('after_state');
            $table->timestamp('created_at')->useCurrent();

            $table->foreign('inter_branch_transaction_id', 'ibt_rec_audit_txn_fk')
                ->references('id')
                ->on('inter_branch_transactions')
                ->cascadeOnDelete();
            $table->foreign('actor_user_id', 'ibt_rec_audit_actor_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
            $table->foreign('inventory_sale_id', 'ibt_rec_audit_sale_fk')
                ->references('id')
                ->on('inventory_sales');
            $table->foreign('statutory_invoice_id', 'ibt_rec_audit_inv_fk')
                ->references('id')
                ->on('statutory_invoices');
            $table->foreign('from_branch_id', 'ibt_rec_audit_from_fk')
                ->references('id')
                ->on('inventory_branches');
            $table->foreign('to_branch_id', 'ibt_rec_audit_to_fk')
                ->references('id')
                ->on('inventory_branches');
            $table->foreign('finance_journal_id', 'ibt_rec_audit_fin_fk')
                ->references('id')
                ->on('finance_journals')
                ->nullOnDelete();
            $table->unique('idempotency_key', 'ibt_rec_audit_idem_uidx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('inter_branch_reconciliation_audits');

        Schema::table('inter_branch_transactions', function (Blueprint $table) {
            $table->dropForeign('ibt_legacy_sale_fk');
            $table->dropForeign('ibt_reconciled_by_fk');
            $table->dropUnique('ibt_legacy_sale_uidx');
            $table->dropColumn([
                'legacy_inventory_sale_id',
                'reconciliation_mode',
                'reconciled_at',
                'reconciled_by',
            ]);
        });
    }
};
