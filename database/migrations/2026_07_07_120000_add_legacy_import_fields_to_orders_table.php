<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('gst_number', 50)->nullable()->after('customer_phone');
            $table->string('invoice_number', 100)->nullable()->after('gst_number');
            $table->string('purchase_year', 10)->nullable()->after('invoice_number');
            $table->json('service_history')->nullable()->after('purchase_year');
            $table->string('amc_status', 100)->nullable()->after('service_history');
            $table->string('amc_year', 10)->nullable()->after('amc_status');
            $table->json('amc_details')->nullable()->after('amc_year');
            $table->string('legacy_order_status', 100)->nullable()->after('amc_details');
            $table->string('legacy_source', 50)->nullable()->after('legacy_order_status');
            $table->timestamp('legacy_imported_at')->nullable()->after('legacy_source');
            $table->foreignId('legacy_imported_by_user_id')->nullable()->after('legacy_imported_at')->constrained('users')->nullOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropConstrainedForeignId('legacy_imported_by_user_id');
            $table->dropColumn([
                'gst_number',
                'invoice_number',
                'purchase_year',
                'service_history',
                'amc_status',
                'amc_year',
                'amc_details',
                'legacy_order_status',
                'legacy_source',
                'legacy_imported_at',
            ]);
        });
    }
};
