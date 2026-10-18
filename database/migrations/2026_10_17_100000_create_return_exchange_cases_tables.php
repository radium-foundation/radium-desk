<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('return_exchange_cases', function (Blueprint $table): void {
            $table->id();
            $table->string('case_no', 32)->unique();
            $table->string('source_kind', 40);
            $table->string('status', 40)->default('submitted');
            $table->foreignId('branch_id')->nullable()->constrained('inventory_branches')->nullOnDelete();
            $table->foreignId('inventory_sale_id')->nullable()->constrained('inventory_sales')->nullOnDelete();
            $table->foreignId('order_id')->nullable()->constrained('orders')->nullOnDelete();
            $table->foreignId('hardware_fulfilment_id')->nullable()->constrained('hardware_fulfilments')->nullOnDelete();
            $table->string('idempotency_key', 128)->nullable()->unique();
            $table->text('reason');
            $table->text('requester_remarks')->nullable();
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->timestamps();

            $table->index(['source_kind', 'status']);
            $table->index(['branch_id', 'status']);
            $table->index('inventory_sale_id');
            $table->index('order_id');
            $table->index('hardware_fulfilment_id');
        });

        Schema::create('return_exchange_case_lines', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('return_exchange_case_id')->constrained('return_exchange_cases')->cascadeOnDelete();
            $table->unsignedSmallInteger('line_no');
            $table->string('description');
            $table->foreignId('inventory_product_id')->nullable()->constrained('inventory_products')->nullOnDelete();
            $table->foreignId('inventory_sale_line_id')->nullable()->constrained('inventory_sale_lines')->nullOnDelete();
            $table->unsignedInteger('qty')->default(1);
            $table->string('serial_number', 128)->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['return_exchange_case_id', 'line_no']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('return_exchange_case_lines');
        Schema::dropIfExists('return_exchange_cases');
    }
};
