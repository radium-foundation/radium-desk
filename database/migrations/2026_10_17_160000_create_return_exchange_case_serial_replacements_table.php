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
            $table->foreignId('return_exchange_case_id')->constrained('return_exchange_cases')->cascadeOnDelete();
            $table->foreignId('return_exchange_case_receipt_id')->constrained('return_exchange_case_receipts')->cascadeOnDelete();
            $table->foreignId('returned_inventory_serial_id')->constrained('inventory_serials');
            $table->foreignId('replacement_inventory_serial_id')->constrained('inventory_serials');
            $table->foreignId('inventory_sale_id')->nullable()->constrained('inventory_sales')->nullOnDelete();
            $table->foreignId('hardware_fulfilment_serial_id')->nullable()->constrained('hardware_fulfilment_serials')->nullOnDelete();
            $table->string('verified_unit_value', 16);
            $table->string('idempotency_key')->nullable();
            $table->foreignId('performed_by')->constrained('users');
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
