<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('finance_legacy_cash_user_maps', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_source', 64);
            $table->string('legacy_admin_id', 64);
            $table->string('legacy_admin_name')->nullable();
            $table->foreignId('desk_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('mapping_status', 32);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['legacy_source', 'legacy_admin_id'], 'legacy_cash_admin_map_unique');
        });

        Schema::create('finance_legacy_cash_entries', function (Blueprint $table) {
            $table->id();
            $table->string('legacy_source', 64);
            $table->string('legacy_database', 64);
            $table->string('legacy_table', 64);
            $table->unsignedBigInteger('legacy_transaction_id');
            $table->string('idempotency_key')->unique();
            $table->timestamp('original_created_at');
            $table->timestamp('original_updated_at')->nullable();
            $table->string('original_amount_raw', 64);
            $table->decimal('amount', 14, 2);
            $table->string('entry_type', 16);
            $table->string('amount_type')->nullable();
            $table->text('description')->nullable();
            $table->string('legacy_created_by', 64);
            $table->string('legacy_admin_name')->nullable();
            $table->foreignId('desk_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('import_status', 32);
            $table->string('review_status', 32);
            $table->string('review_reason')->nullable();
            $table->timestamp('imported_at');
            $table->timestamps();

            $table->unique(
                ['legacy_database', 'legacy_table', 'legacy_transaction_id'],
                'legacy_cash_source_txn_unique',
            );
            $table->index('entry_type');
            $table->index('original_created_at');
            $table->index('legacy_created_by');
            $table->index('desk_user_id');
            $table->index('review_status');
            $table->index('amount_type');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('finance_legacy_cash_entries');
        Schema::dropIfExists('finance_legacy_cash_user_maps');
    }
};
