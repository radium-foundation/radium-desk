<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_wallets', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('status', 32)->default('active');
            $table->timestamps();

            $table->index('status');
        });

        Schema::create('central_wallet_account_links', function (Blueprint $table): void {
            $table->id();
            $table->uuid('central_wallet_id');
            $table->string('site_code', 64);
            $table->string('local_user_id', 64);
            $table->string('status', 32);
            $table->string('verification_method', 64)->nullable();
            $table->timestamp('linked_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->string('created_by', 128);
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->foreign('central_wallet_id', 'cw_account_links_wallet_fk')
                ->references('id')
                ->on('central_wallets')
                ->cascadeOnDelete();

            $table->index(['site_code', 'local_user_id'], 'cw_account_links_site_user_idx');
            $table->index(['central_wallet_id', 'site_code'], 'cw_account_links_wallet_site_idx');
            $table->index('status', 'cw_account_links_status_idx');
        });

        Schema::create('central_wallet_ledger_entries', function (Blueprint $table): void {
            $table->id();
            $table->uuid('central_wallet_id');
            $table->string('entry_type', 32);
            $table->decimal('amount', 12, 2);
            $table->char('currency', 3)->default('INR');
            $table->string('status', 32)->default('posted');
            $table->string('source_system', 64);
            $table->string('source_reference', 191)->nullable();
            $table->uuid('correlation_id');
            $table->string('business_reference', 191)->nullable();
            $table->uuid('reservation_id')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamp('posted_at');
            $table->timestamps();

            $table->foreign('central_wallet_id', 'cw_ledger_entries_wallet_fk')
                ->references('id')
                ->on('central_wallets')
                ->cascadeOnDelete();

            $table->index(['central_wallet_id', 'status'], 'cw_ledger_wallet_status_idx');
            $table->index('correlation_id', 'cw_ledger_correlation_idx');
            $table->index(['source_system', 'source_reference'], 'cw_ledger_source_idx');
        });

        Schema::create('central_wallet_idempotency_records', function (Blueprint $table): void {
            $table->id();
            $table->string('caller_id', 64);
            $table->string('idempotency_key', 128);
            $table->string('request_hash', 64);
            $table->unsignedSmallInteger('response_status');
            $table->json('response_body')->nullable();
            $table->string('response_body_hash', 64)->nullable();
            $table->string('resource_type', 64)->nullable();
            $table->string('resource_id', 64)->nullable();
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique(['caller_id', 'idempotency_key'], 'cw_idempotency_caller_key_uq');
            $table->index('expires_at', 'cw_idempotency_expires_idx');
        });

        Schema::create('central_wallet_audit_events', function (Blueprint $table): void {
            $table->id();
            $table->string('event_type', 64);
            $table->uuid('central_wallet_id')->nullable();
            $table->string('actor_type', 32);
            $table->string('actor_id', 128)->nullable();
            $table->uuid('correlation_id');
            $table->json('payload')->nullable();
            $table->timestamp('occurred_at');

            $table->index('event_type', 'cw_audit_event_type_idx');
            $table->index('correlation_id', 'cw_audit_correlation_idx');
            $table->index('central_wallet_id', 'cw_audit_wallet_idx');
        });

        Schema::create('central_wallet_reconciliation_runs', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('scope', 32);
            $table->string('status', 32);
            $table->timestamp('started_at')->nullable();
            $table->timestamp('completed_at')->nullable();
            $table->json('summary')->nullable();
            $table->timestamps();

            $table->index('status', 'cw_recon_runs_status_idx');
        });

        Schema::create('central_wallet_reconciliation_items', function (Blueprint $table): void {
            $table->id();
            $table->uuid('run_id');
            $table->string('item_type', 64);
            $table->string('severity', 8);
            $table->json('details')->nullable();
            $table->timestamps();

            $table->foreign('run_id', 'cw_recon_items_run_fk')
                ->references('id')
                ->on('central_wallet_reconciliation_runs')
                ->cascadeOnDelete();

            $table->index('severity', 'cw_recon_items_severity_idx');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('central_wallet_reconciliation_items');
        Schema::dropIfExists('central_wallet_reconciliation_runs');
        Schema::dropIfExists('central_wallet_audit_events');
        Schema::dropIfExists('central_wallet_idempotency_records');
        Schema::dropIfExists('central_wallet_ledger_entries');
        Schema::dropIfExists('central_wallet_account_links');
        Schema::dropIfExists('central_wallets');
    }
};
