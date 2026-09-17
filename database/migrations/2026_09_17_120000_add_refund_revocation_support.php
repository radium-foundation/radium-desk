<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('refund_requests', function (Blueprint $table) {
            $table->timestamp('revoked_at')->nullable()->after('closed_at');
            $table->unsignedBigInteger('revoked_by')->nullable()->after('revoked_at');
            $table->text('revoke_reason')->nullable()->after('revoked_by');
            $table->string('revoke_customer_outcome', 64)->nullable()->after('revoke_reason');
            $table->string('revoke_wallet_reversal_reference', 255)->nullable()->after('revoke_customer_outcome');
            $table->string('revoke_wallet_reversal_transaction_id', 64)->nullable()->after('revoke_wallet_reversal_reference');

            $table->foreign('revoked_by', 'refund_requests_revoked_by_fk')
                ->references('id')
                ->on('users')
                ->nullOnDelete();
        });

        Schema::create('refund_revocation_attempts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('refund_request_id')->constrained('refund_requests')->cascadeOnDelete();
            $table->string('idempotency_key', 128);
            $table->string('customer_outcome', 64);
            $table->text('revoke_reason');
            $table->string('status', 32);
            $table->string('wallet_reversal_reference', 255)->nullable();
            $table->string('wallet_reversal_transaction_id', 64)->nullable();
            $table->unsignedBigInteger('commercial_service_restoration_id')->nullable();
            $table->unsignedBigInteger('actor_user_id');
            $table->text('error_message')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('idempotency_key', 'rr_attempts_idem_unique');
            $table->index(['refund_request_id', 'status'], 'rr_attempts_refund_status_idx');
            $table->foreign('actor_user_id', 'rr_attempts_actor_fk')
                ->references('id')
                ->on('users')
                ->cascadeOnDelete();
            $table->foreign('commercial_service_restoration_id', 'rr_attempts_csr_fk')
                ->references('id')
                ->on('commercial_service_restorations')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('refund_revocation_attempts');

        Schema::table('refund_requests', function (Blueprint $table) {
            $table->dropConstrainedForeignId('revoked_by');
            $table->dropColumn([
                'revoked_at',
                'revoke_reason',
                'revoke_customer_outcome',
                'revoke_wallet_reversal_reference',
                'revoke_wallet_reversal_transaction_id',
            ]);
        });
    }
};
