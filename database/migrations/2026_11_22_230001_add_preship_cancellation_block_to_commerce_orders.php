<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table) {
            if (! Schema::hasColumn('commerce_orders', 'hardware_fulfilment_blocked_at')) {
                $table->timestamp('hardware_fulfilment_blocked_at')->nullable()->after('support_order_id');
            }
            if (! Schema::hasColumn('commerce_orders', 'pre_shipment_cancellation_request_id')) {
                $table->foreignId('pre_shipment_cancellation_request_id')
                    ->nullable()
                    ->after('hardware_fulfilment_blocked_at')
                    ->constrained('pre_shipment_cancellation_requests')
                    ->nullOnDelete();
            }
        });
    }

    public function down(): void
    {
        Schema::table('commerce_orders', function (Blueprint $table) {
            if (Schema::hasColumn('commerce_orders', 'pre_shipment_cancellation_request_id')) {
                $table->dropConstrainedForeignId('pre_shipment_cancellation_request_id');
            }
            if (Schema::hasColumn('commerce_orders', 'hardware_fulfilment_blocked_at')) {
                $table->dropColumn('hardware_fulfilment_blocked_at');
            }
        });
    }
};
