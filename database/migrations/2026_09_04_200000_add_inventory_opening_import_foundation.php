<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('inventory_products', function (Blueprint $table) {
            $table->decimal('unit_cost', 12, 2)->nullable()->after('unit_price');
        });

        Schema::table('inventory_serials', function (Blueprint $table) {
            $table->string('condition', 24)->nullable()->after('status');
            $table->decimal('unit_cost', 12, 2)->nullable()->after('condition');
        });

        Schema::create('inventory_opening_import_batches', function (Blueprint $table) {
            $table->id();
            $table->string('source_checksum', 64);
            $table->string('source_filename');
            $table->string('stored_path')->nullable();
            $table->string('status', 24);
            $table->date('opening_date')->nullable();
            $table->unsignedInteger('sku_created_count')->default(0);
            $table->unsignedInteger('variant_created_count')->default(0);
            $table->unsignedInteger('rows_valid')->default(0);
            $table->unsignedInteger('rows_invalid')->default(0);
            $table->unsignedInteger('rows_applied')->default(0);
            $table->json('summary')->nullable();
            $table->foreignId('actor_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->unique('source_checksum');
            $table->index(['status', 'created_at']);
        });

        Schema::create('inventory_opening_import_rows', function (Blueprint $table) {
            $table->id();
            $table->foreignId('batch_id')->constrained('inventory_opening_import_batches')->cascadeOnDelete();
            $table->string('sheet', 40);
            $table->unsignedInteger('row_number');
            $table->string('fingerprint', 80);
            $table->string('applied_identity', 160)->nullable();
            $table->string('sku', 64)->nullable();
            $table->string('variant_sku', 64)->nullable();
            $table->string('branch_code', 32)->nullable();
            $table->string('serial_number', 128)->nullable();
            $table->unsignedInteger('qty')->nullable();
            $table->string('status', 24);
            $table->json('issues')->nullable();
            $table->foreignId('product_id')->nullable()->constrained('inventory_products')->nullOnDelete();
            $table->foreignId('serial_id')->nullable()->constrained('inventory_serials')->nullOnDelete();
            $table->foreignId('movement_id')->nullable()->constrained('inventory_movements')->nullOnDelete();
            $table->timestamps();

            $table->unique(['batch_id', 'row_number', 'sheet']);
            $table->unique(['batch_id', 'fingerprint']);
            $table->unique('applied_identity');
            $table->index(['batch_id', 'status']);
        });

        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->foreignId('opening_import_batch_id')
                ->nullable()
                ->after('adjustment_id')
                ->constrained('inventory_opening_import_batches')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('inventory_movements', function (Blueprint $table) {
            $table->dropConstrainedForeignId('opening_import_batch_id');
        });

        Schema::dropIfExists('inventory_opening_import_rows');
        Schema::dropIfExists('inventory_opening_import_batches');

        Schema::table('inventory_serials', function (Blueprint $table) {
            $table->dropColumn(['condition', 'unit_cost']);
        });

        Schema::table('inventory_products', function (Blueprint $table) {
            $table->dropColumn('unit_cost');
        });
    }
};
