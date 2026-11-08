<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('pos_customer_requested_serial_exchanges', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('inventory_sale_id');
            $table->unsignedBigInteger('inventory_branch_id');
            $table->unsignedBigInteger('original_inventory_serial_id');
            $table->unsignedBigInteger('replacement_inventory_serial_id');
            $table->unsignedBigInteger('inventory_sale_serial_id')->nullable();
            $table->string('idempotency_key', 128)->unique();
            $table->string('exchange_reason', 2000);
            $table->string('status', 32);
            $table->text('physical_receipt_attestation_reason')->nullable();
            $table->unsignedBigInteger('physical_receipt_attested_by')->nullable();
            $table->timestamp('physical_receipt_attested_at')->nullable();
            $table->string('statutory_treatment_decision', 128)->nullable();
            $table->text('statutory_evidence_reference')->nullable();
            $table->text('statutory_notes')->nullable();
            $table->unsignedBigInteger('statutory_approved_by')->nullable();
            $table->timestamp('statutory_approved_at')->nullable();
            $table->unsignedBigInteger('performed_by')->nullable();
            $table->timestamp('executed_at')->nullable();
            $table->decimal('sale_total_snapshot', 12, 2);
            $table->unsignedInteger('sale_serial_count_snapshot');
            $table->timestamps();

            $table->unique(
                ['inventory_sale_id', 'original_inventory_serial_id', 'replacement_inventory_serial_id'],
                'pos_cse_sale_orig_repl_unique',
            );

            $table->foreign('inventory_sale_id', 'pos_cse_sale_fk')
                ->references('id')->on('inventory_sales');
            $table->foreign('inventory_branch_id', 'pos_cse_branch_fk')
                ->references('id')->on('inventory_branches');
            $table->foreign('original_inventory_serial_id', 'pos_cse_orig_serial_fk')
                ->references('id')->on('inventory_serials');
            $table->foreign('replacement_inventory_serial_id', 'pos_cse_repl_serial_fk')
                ->references('id')->on('inventory_serials');
            $table->foreign('inventory_sale_serial_id', 'pos_cse_sale_serial_fk')
                ->references('id')->on('inventory_sale_serials');
            $table->foreign('physical_receipt_attested_by', 'pos_cse_phys_attest_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('statutory_approved_by', 'pos_cse_stat_appr_fk')
                ->references('id')->on('users')->nullOnDelete();
            $table->foreign('performed_by', 'pos_cse_performed_fk')
                ->references('id')->on('users')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customer_requested_serial_exchanges');
    }
};
