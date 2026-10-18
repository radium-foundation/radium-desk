<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_exchange_case_serial_replacements', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_exchange_case_id')->constrained('return_exchange_cases', indexName: 'rec_repl_case_fk')->cascadeOnDelete();
            $table->foreignId('return_exchange_case_receipt_id')->constrained('return_exchange_case_receipts', indexName: 'rec_repl_receipt_fk')->cascadeOnDelete();
            $table->foreignId('returned_inventory_serial_id')->constrained('inventory_serials', indexName: 'rec_repl_ret_serial_fk');
            $table->foreignId('replacement_inventory_serial_id')->constrained('inventory_serials', indexName: 'rec_repl_new_serial_fk');
            $table->foreignId('inventory_sale_id')->nullable()->constrained('inventory_sales', indexName: 'rec_repl_sale_fk')->nullOnDelete();
            $table->foreignId('hardware_fulfilment_serial_id')->nullable()->constrained('hardware_fulfilment_serials', indexName: 'rec_repl_hw_serial_fk')->nullOnDelete();
            $table->string('verified_unit_value', 16);
            $table->string('idempotency_key')->nullable();
            $table->foreignId('performed_by')->constrained('users', indexName: 'rec_repl_performed_by_fk');
            $table->timestamp('performed_at');
            $table->timestamps();

            $table->unique('return_exchange_case_receipt_id', 'return_case_serial_replacement_receipt_unique');
            $table->unique('replacement_inventory_serial_id', 'return_case_serial_replacement_serial_unique');
            $table->unique('idempotency_key', 'return_case_serial_replacement_idempotency_unique');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_exchange_case_serial_replacements');
    }
};
