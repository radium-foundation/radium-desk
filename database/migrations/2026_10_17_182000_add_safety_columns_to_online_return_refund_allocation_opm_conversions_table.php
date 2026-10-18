<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('online_return_refund_allocation_opm_conversions', function (Blueprint $table): void {
            $table->boolean('site_reservation_may_exist')->default(false)->after('site_reservation_status');
            $table->timestamp('site_reservation_released_at')->nullable()->after('site_reservation_may_exist');
            $table->string('site_reservation_release_outcome', 32)->nullable()->after('site_reservation_released_at');
            $table->text('site_reservation_release_error')->nullable()->after('site_reservation_release_outcome');
            $table->string('wallet_finalization_state', 32)->default('not_sent')->after('wallet_finalization_idempotency_key');
            $table->timestamp('wallet_finalization_attempted_at')->nullable()->after('wallet_finalization_state');
            $table->timestamp('wallet_finalization_confirmed_at')->nullable()->after('wallet_finalization_attempted_at');
            $table->boolean('wallet_finalization_idempotent_replay')->nullable()->after('wallet_finalization_confirmed_at');
            $table->text('wallet_finalization_last_error')->nullable()->after('wallet_finalization_idempotent_replay');
            $table->string('bound_order_id', 64)->nullable()->after('wallet_transaction_id');
            $table->decimal('bound_wallet_credit_amount', 12, 2)->nullable()->after('bound_order_id');
        });
    }

    public function down(): void
    {
        Schema::table('online_return_refund_allocation_opm_conversions', function (Blueprint $table): void {
            $table->dropColumn([
                'site_reservation_may_exist',
                'site_reservation_released_at',
                'site_reservation_release_outcome',
                'site_reservation_release_error',
                'wallet_finalization_state',
                'wallet_finalization_attempted_at',
                'wallet_finalization_confirmed_at',
                'wallet_finalization_idempotent_replay',
                'wallet_finalization_last_error',
                'bound_order_id',
                'bound_wallet_credit_amount',
            ]);
        });
    }
};
