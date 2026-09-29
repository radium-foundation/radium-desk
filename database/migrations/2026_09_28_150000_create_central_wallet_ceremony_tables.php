<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('central_wallet_ceremony_identities')) {
            Schema::create('central_wallet_ceremony_identities', function (Blueprint $table): void {
                $table->uuid('id')->primary();
                $table->string('site_code', 64);
                $table->string('local_user_id', 64);
                $table->uuid('central_wallet_id')->nullable();
                $table->char('verified_phone_e164_hash', 64);
                $table->timestamp('first_verified_at');
                $table->timestamps();

                $table->foreign('central_wallet_id', 'cw_ceremony_identities_wallet_fk')
                    ->references('id')
                    ->on('central_wallets')
                    ->nullOnDelete();

                $table->unique(['site_code', 'local_user_id'], 'cw_ceremony_identities_site_user_uq');
                $table->index('central_wallet_id', 'cw_ceremony_identities_wallet_idx');
            });
        }

        if (! Schema::hasTable('central_wallet_ceremony_proof_consumptions')) {
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
        }

        $this->ensureActiveAccountLinkUniqueIndexes();
    }

    public function down(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            if ($this->indexExists('central_wallet_account_links', 'cw_account_links_site_user_active_uq')) {
                DB::statement('DROP INDEX cw_account_links_site_user_active_uq');
            }

            if ($this->indexExists('central_wallet_account_links', 'cw_account_links_site_wallet_active_uq')) {
                DB::statement('DROP INDEX cw_account_links_site_wallet_active_uq');
            }
        } elseif (in_array($driver, ['mysql', 'mariadb'], true)) {
            if ($this->indexExists('central_wallet_account_links', 'cw_account_links_site_user_active_uq')) {
                DB::statement('DROP INDEX cw_account_links_site_user_active_uq ON central_wallet_account_links');
            }

            if (Schema::hasColumn('central_wallet_account_links', 'active_site_user_uniq_key')) {
                Schema::table('central_wallet_account_links', function (Blueprint $table): void {
                    $table->dropColumn('active_site_user_uniq_key');
                });
            }

            if ($this->indexExists('central_wallet_account_links', 'cw_account_links_site_wallet_active_uq')) {
                DB::statement('DROP INDEX cw_account_links_site_wallet_active_uq ON central_wallet_account_links');
            }

            if (Schema::hasColumn('central_wallet_account_links', 'active_site_wallet_uniq_key')) {
                Schema::table('central_wallet_account_links', function (Blueprint $table): void {
                    $table->dropColumn('active_site_wallet_uniq_key');
                });
            }
        }

        Schema::dropIfExists('central_wallet_ceremony_proof_consumptions');
        Schema::dropIfExists('central_wallet_ceremony_identities');
    }

    private function ensureActiveAccountLinkUniqueIndexes(): void
    {
        $driver = Schema::getConnection()->getDriverName();

        if ($driver === 'sqlite') {
            if (! $this->indexExists('central_wallet_account_links', 'cw_account_links_site_user_active_uq')) {
                DB::statement(
                    'CREATE UNIQUE INDEX cw_account_links_site_user_active_uq ON central_wallet_account_links (site_code, local_user_id) WHERE status = \'active\''
                );
            }

            if (! $this->indexExists('central_wallet_account_links', 'cw_account_links_site_wallet_active_uq')) {
                DB::statement(
                    'CREATE UNIQUE INDEX cw_account_links_site_wallet_active_uq ON central_wallet_account_links (site_code, central_wallet_id) WHERE status = \'active\''
                );
            }

            return;
        }

        if (! in_array($driver, ['mysql', 'mariadb'], true)) {
            return;
        }

        if (! Schema::hasColumn('central_wallet_account_links', 'active_site_user_uniq_key')) {
            DB::statement(
                "ALTER TABLE central_wallet_account_links
                    ADD COLUMN active_site_user_uniq_key VARCHAR(200)
                    GENERATED ALWAYS AS (
                        CASE WHEN status = 'active' THEN CONCAT(site_code, CHAR(1), local_user_id) ELSE NULL END
                    ) STORED"
            );
        }

        if (! $this->indexExists('central_wallet_account_links', 'cw_account_links_site_user_active_uq')) {
            DB::statement(
                'CREATE UNIQUE INDEX cw_account_links_site_user_active_uq ON central_wallet_account_links (active_site_user_uniq_key)'
            );
        }

        if (! Schema::hasColumn('central_wallet_account_links', 'active_site_wallet_uniq_key')) {
            DB::statement(
                "ALTER TABLE central_wallet_account_links
                    ADD COLUMN active_site_wallet_uniq_key VARCHAR(200)
                    GENERATED ALWAYS AS (
                        CASE WHEN status = 'active' THEN CONCAT(site_code, CHAR(1), central_wallet_id) ELSE NULL END
                    ) STORED"
            );
        }

        if (! $this->indexExists('central_wallet_account_links', 'cw_account_links_site_wallet_active_uq')) {
            DB::statement(
                'CREATE UNIQUE INDEX cw_account_links_site_wallet_active_uq ON central_wallet_account_links (active_site_wallet_uniq_key)'
            );
        }
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $connection = Schema::getConnection();
        $driver = $connection->getDriverName();

        if ($driver === 'sqlite') {
            $rows = DB::select("PRAGMA index_list('{$table}')");

            foreach ($rows as $row) {
                if (($row->name ?? null) === $indexName) {
                    return true;
                }
            }

            return false;
        }

        if (in_array($driver, ['mysql', 'mariadb'], true)) {
            $prefixedTable = $connection->getTablePrefix().$table;
            $rows = DB::select("SHOW INDEX FROM `{$prefixedTable}` WHERE Key_name = ?", [$indexName]);

            return $rows !== [];
        }

        return false;
    }
};
