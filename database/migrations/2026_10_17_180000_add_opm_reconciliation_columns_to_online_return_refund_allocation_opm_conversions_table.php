<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_return_refund_allocation_opm_conversions', function (Blueprint $table): void {
            $table->string('site_reservation_idempotency_key', 128)->nullable()->after('site_reservation_status');
            $table->string('site_reservation_state', 32)->default('not_sent')->after('site_reservation_idempotency_key');
            $table->timestamp('site_reservation_attempted_at')->nullable()->after('site_reservation_state');
            $table->timestamp('last_reconciliation_at')->nullable()->after('wallet_finalization_last_error');
            $table->foreignId('last_reconciliation_by_id')->nullable()->after('last_reconciliation_at')->constrained('users')->nullOnDelete();
            $table->string('last_reconciliation_action', 64)->nullable()->after('last_reconciliation_by_id');
            $table->string('last_reconciliation_outcome', 64)->nullable()->after('last_reconciliation_action');
            $table->text('last_reconciliation_detail')->nullable()->after('last_reconciliation_outcome');
        });
    }

    public function down(): void
    {
        Schema::table('online_return_refund_allocation_opm_conversions', function (Blueprint $table): void {
            $table->dropConstrainedForeignId('last_reconciliation_by_id');
            $table->dropColumn([
                'site_reservation_idempotency_key',
                'site_reservation_state',
                'site_reservation_attempted_at',
                'last_reconciliation_at',
                'last_reconciliation_action',
                'last_reconciliation_outcome',
                'last_reconciliation_detail',
            ]);
        });
    }
};
