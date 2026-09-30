<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_customers', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->uuid('central_wallet_id')->unique('central_customers_wallet_uq');
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->foreign('central_wallet_id', 'central_customers_wallet_fk')
                ->references('id')
                ->on('central_wallets')
                ->cascadeOnDelete();

            $table->index('status', 'central_customers_status_idx');
        });

        Schema::create('central_customer_identity_credentials', function (Blueprint $table): void {
            $table->id();
            $table->uuid('desk_customer_id');
            $table->string('credential_type', 32);
            $table->string('provider', 64);
            $table->char('subject_hash', 64);
            $table->timestamp('verified_at');
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('desk_customer_id', 'central_customer_credentials_customer_fk')
                ->references('id')
                ->on('central_customers')
                ->cascadeOnDelete();

            $table->unique(
                ['credential_type', 'provider', 'subject_hash'],
                'central_customer_credentials_subject_uq',
            );
            $table->index('desk_customer_id', 'central_customer_credentials_customer_idx');
        });

        Schema::table('central_wallet_account_links', function (Blueprint $table): void {
            $table->uuid('desk_customer_id')->nullable()->after('central_wallet_id');

            $table->foreign('desk_customer_id', 'cw_account_links_customer_fk')
                ->references('id')
                ->on('central_customers')
                ->nullOnDelete();

            $table->index('desk_customer_id', 'cw_account_links_customer_idx');
        });
    }

    public function down(): void
    {
        Schema::table('central_wallet_account_links', function (Blueprint $table): void {
            $table->dropForeign('cw_account_links_customer_fk');
            $table->dropIndex('cw_account_links_customer_idx');
            $table->dropColumn('desk_customer_id');
        });

        Schema::dropIfExists('central_customer_identity_credentials');
        Schema::dropIfExists('central_customers');
    }
};
