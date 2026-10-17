<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_exchange_case_receipts', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_exchange_case_id')->constrained('return_exchange_cases')->cascadeOnDelete();
            $table->foreignId('return_exchange_case_line_id')->constrained('return_exchange_case_lines')->cascadeOnDelete();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->foreignId('inventory_serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->unsignedSmallInteger('qty_received')->default(1);
            $table->string('serial_number_scanned', 128)->nullable();
            $table->timestamp('received_at');
            $table->foreignId('received_by')->constrained('users')->cascadeOnDelete();
            $table->boolean('product_match_passed')->nullable();
            $table->boolean('device_operates_ok')->nullable();
            $table->string('condition_code', 40)->nullable();
            $table->boolean('accessories_verified')->nullable();
            $table->boolean('repair_hold')->default(false);
            $table->timestamp('inspected_at')->nullable();
            $table->foreignId('inspected_by')->nullable()->constrained('users')->nullOnDelete();
            $table->string('serial_status_after_inspection', 40)->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();

            $table->unique(['return_exchange_case_id', 'return_exchange_case_line_id'], 'return_case_receipt_line_unique');
            $table->unique(['return_exchange_case_id', 'inventory_serial_id'], 'return_case_receipt_serial_unique');
            $table->index('inventory_serial_id');
        });

        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->foreignId('return_exchange_case_id')->nullable()->after('sale_id')->constrained('return_exchange_cases')->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('return_exchange_case_id');
        });

        Schema::dropIfExists('return_exchange_case_receipts');
    }
};
