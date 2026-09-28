<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('central_wallet_ceremony_identities', function (Blueprint $table): void {
            $table->uuid('id')->primary();
            $table->string('site_code', 64);
            $table->char('phone_e164_hash', 64);
            $table->uuid('central_wallet_id')->nullable();
            $table->timestamp('first_verified_at');
            $table->timestamps();

            $table->foreign('central_wallet_id', 'cw_ceremony_identities_wallet_fk')
                ->references('id')
                ->on('central_wallets')
                ->nullOnDelete();

            $table->unique(['site_code', 'phone_e164_hash'], 'cw_ceremony_identities_site_phone_uq');
            $table->index('central_wallet_id', 'cw_ceremony_identities_wallet_idx');
        });

        Schema::create('central_wallet_ceremony_proof_consumptions', function (Blueprint $table): void {
            $table->id();
            $table->string('jti', 64);
            $table->string('site_code', 64);
            $table->string('local_user_id', 64);
            $table->uuid('ceremony_attempt_id');
            $table->timestamp('consumed_at');
            $table->timestamp('expires_at');
            $table->timestamps();

            $table->unique('jti', 'cw_ceremony_proof_jti_uq');
            $table->index('expires_at', 'cw_ceremony_proof_expires_idx');
        });

        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement(
                'CREATE UNIQUE INDEX cw_account_links_site_user_active_uq ON central_wallet_account_links (site_code, local_user_id) WHERE status = \'active\''
            );
            DB::statement(
                'CREATE UNIQUE INDEX cw_account_links_site_wallet_active_uq ON central_wallet_account_links (site_code, central_wallet_id) WHERE status = \'active\''
            );

            return;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement(
                'CREATE UNIQUE INDEX cw_account_links_site_user_active_uq ON central_wallet_account_links (site_code, local_user_id, (IF(status = \'active\', 1, NULL)))'
            );
            DB::statement(
                'CREATE UNIQUE INDEX cw_account_links_site_wallet_active_uq ON central_wallet_account_links (site_code, central_wallet_id, (IF(status = \'active\', 1, NULL)))'
            );
        }
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            DB::statement('DROP INDEX IF EXISTS cw_account_links_site_user_active_uq');
            DB::statement('DROP INDEX IF EXISTS cw_account_links_site_wallet_active_uq');
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            DB::statement('DROP INDEX cw_account_links_site_user_active_uq ON central_wallet_account_links');
            DB::statement('DROP INDEX cw_account_links_site_wallet_active_uq ON central_wallet_account_links');
        }

        Schema::dropIfExists('central_wallet_ceremony_proof_consumptions');
        Schema::dropIfExists('central_wallet_ceremony_identities');
    }
};
