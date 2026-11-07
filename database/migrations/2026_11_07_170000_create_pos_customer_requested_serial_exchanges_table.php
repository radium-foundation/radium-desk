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
            $table->foreignId('inventory_sale_id')->constrained('inventory_sales');
            $table->foreignId('inventory_branch_id')->constrained('inventory_branches');
            $table->foreignId('original_inventory_serial_id')->constrained('inventory_serials');
            $table->foreignId('replacement_inventory_serial_id')->constrained('inventory_serials');
            $table->foreignId('inventory_sale_serial_id')->nullable()->constrained('inventory_sale_serials');
            $table->string('idempotency_key', 128)->unique();
            $table->string('exchange_reason', 2000);
            $table->string('status', 32);
            $table->text('physical_receipt_attestation_reason')->nullable();
            $table->foreignId('physical_receipt_attested_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('physical_receipt_attested_at')->nullable();
            $table->string('statutory_treatment_decision', 128)->nullable();
            $table->text('statutory_evidence_reference')->nullable();
            $table->text('statutory_notes')->nullable();
            $table->foreignId('statutory_approved_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('statutory_approved_at')->nullable();
            $table->foreignId('performed_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('executed_at')->nullable();
            $table->decimal('sale_total_snapshot', 12, 2);
            $table->unsignedInteger('sale_serial_count_snapshot');
            $table->timestamps();

            $table->unique(
                ['inventory_sale_id', 'original_inventory_serial_id', 'replacement_inventory_serial_id'],
                'pos_cust_serial_ex_sale_original_replacement_unique',
            );
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('pos_customer_requested_serial_exchanges');
    }
};
