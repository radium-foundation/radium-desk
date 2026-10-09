<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('cashfree_historical_identity_repair_cohort_audits', function (Blueprint $table): void {
            $table->id();
            $table->string('run_id', 64);
            $table->string('normalized_email', 191)->nullable();
            $table->char('subject_hash', 64)->nullable();
            $table->json('order_ids');
            $table->json('previous_customer_ids');
            $table->uuid('target_desk_customer_id')->nullable();
            $table->uuid('central_wallet_id')->nullable();
            $table->string('action', 64);
            $table->string('status', 32);
            $table->string('refund_exposure', 64)->nullable();
            $table->text('error_reason')->nullable();
            $table->timestamp('planned_at')->nullable();
            $table->timestamp('applied_at')->nullable();
            $table->timestamps();

            $table->index('run_id', 'cw_cashfree_hist_repair_run_idx');
            $table->index(['run_id', 'subject_hash'], 'cw_cashfree_hist_repair_run_hash_idx');
            $table->index('status', 'cw_cashfree_hist_repair_status_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('cashfree_historical_identity_repair_cohort_audits');
    }
};
