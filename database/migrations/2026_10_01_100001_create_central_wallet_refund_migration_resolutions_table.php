<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_wallet_refund_migration_resolutions', function (Blueprint $table): void {
            $table->id();
            $table->unsignedBigInteger('refund_id');
            $table->string('resolution_type', 32);
            $table->string('source_application', 64)->nullable();
            $table->unsignedBigInteger('source_wallet_id')->nullable();
            $table->string('source_local_user_id', 64)->nullable();
            $table->uuid('desk_customer_id');
            $table->uuid('cwid');
            $table->string('owner_approval_ref', 191);
            $table->string('approved_by', 128)->nullable();
            $table->timestamp('approved_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->unique('refund_id', 'cw_refund_migration_resolutions_refund_uq');
            $table->index('resolution_type', 'cw_refund_migration_resolutions_type_idx');

            $table->foreign('desk_customer_id', 'cw_refund_migration_resolutions_customer_fk')
                ->references('id')
                ->on('central_customers')
                ->cascadeOnDelete();

            $table->foreign('cwid', 'cw_refund_migration_resolutions_wallet_fk')
                ->references('id')
                ->on('central_wallets')
                ->cascadeOnDelete();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_wallet_refund_migration_resolutions');
    }
};
