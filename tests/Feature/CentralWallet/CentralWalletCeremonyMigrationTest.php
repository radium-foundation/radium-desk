<?php

namespace Tests\Feature\CentralWallet;

use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralWalletCeremonyMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_fresh_migration_creates_ceremony_tables_and_active_link_indexes(): void
    {
        $this->assertTrue(Schema::hasTable('central_wallet_ceremony_identities'));
        $this->assertTrue(Schema::hasTable('central_wallet_ceremony_proof_consumptions'));
        $this->assertTrue($this->indexExists('central_wallet_account_links', 'cw_account_links_site_user_active_uq'));
        $this->assertTrue($this->indexExists('central_wallet_account_links', 'cw_account_links_site_wallet_active_uq'));
    }

    public function test_migration_completes_from_partial_production_state(): void
    {
        Schema::dropIfExists('central_wallet_ceremony_proof_consumptions');
        Schema::dropIfExists('central_wallet_ceremony_identities');
        DB::table('migrations')->where('migration', '2026_09_28_150000_create_central_wallet_ceremony_tables')->delete();

        Schema::create('central_wallet_ceremony_identities', function ($table): void {
            $table->uuid('id')->primary();
            $table->string('site_code', 64);
            $table->string('local_user_id', 64);
            $table->uuid('central_wallet_id')->nullable();
            $table->char('verified_phone_e164_hash', 64);
            $table->timestamp('first_verified_at');
            $table->timestamps();
        });

        Schema::create('central_wallet_ceremony_proof_consumptions', function ($table): void {
            $table->id();
            $table->string('jti', 64);
            $table->string('site_code', 64);
            $table->string('local_user_id', 64);
            $table->uuid('ceremony_attempt_id');
            $table->timestamp('consumed_at');
            $table->timestamp('expires_at');
            $table->timestamps();
        });

        $this->artisan('migrate', [
            '--path' => 'database/migrations/2026_09_28_150000_create_central_wallet_ceremony_tables.php',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertTrue($this->indexExists('central_wallet_account_links', 'cw_account_links_site_user_active_uq'));
        $this->assertTrue($this->indexExists('central_wallet_account_links', 'cw_account_links_site_wallet_active_uq'));
    }

    public function test_only_one_active_link_per_site_and_local_user_is_enforced(): void
    {
        $walletA = (string) Str::uuid();
        $walletB = (string) Str::uuid();

        DB::table('central_wallets')->insert([
            ['id' => $walletA, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
            ['id' => $walletB, 'status' => 'active', 'created_at' => now(), 'updated_at' => now()],
        ]);

        DB::table('central_wallet_account_links')->insert([
            'central_wallet_id' => $walletA,
            'site_code' => 'radiumbox.com',
            'local_user_id' => '42',
            'status' => 'active',
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('central_wallet_account_links')->insert([
            'central_wallet_id' => $walletB,
            'site_code' => 'radiumbox.com',
            'local_user_id' => '42',
            'status' => 'active',
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_only_one_active_link_per_site_and_wallet_is_enforced(): void
    {
        $wallet = (string) Str::uuid();

        DB::table('central_wallets')->insert([
            'id' => $wallet,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('central_wallet_account_links')->insert([
            'central_wallet_id' => $wallet,
            'site_code' => 'radiumbox.com',
            'local_user_id' => '42',
            'status' => 'active',
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->expectException(QueryException::class);

        DB::table('central_wallet_account_links')->insert([
            'central_wallet_id' => $wallet,
            'site_code' => 'radiumbox.com',
            'local_user_id' => '43',
            'status' => 'active',
            'created_by' => 'test',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function test_multiple_inactive_links_are_allowed_for_same_site_user_and_wallet(): void
    {
        $wallet = (string) Str::uuid();

        DB::table('central_wallets')->insert([
            'id' => $wallet,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('central_wallet_account_links')->insert([
            [
                'central_wallet_id' => $wallet,
                'site_code' => 'radiumbox.com',
                'local_user_id' => '42',
                'status' => 'revoked',
                'created_by' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'central_wallet_id' => $wallet,
                'site_code' => 'radiumbox.com',
                'local_user_id' => '42',
                'status' => 'pending_verification',
                'created_by' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
            [
                'central_wallet_id' => $wallet,
                'site_code' => 'radiumbox.com',
                'local_user_id' => '43',
                'status' => 'revoked',
                'created_by' => 'test',
                'created_at' => now(),
                'updated_at' => now(),
            ],
        ]);

        $this->assertSame(3, DB::table('central_wallet_account_links')->count());
    }

    public function test_migration_down_removes_ceremony_tables_and_active_link_indexes(): void
    {
        $this->artisan('migrate:rollback', [
            '--path' => 'database/migrations/2026_09_28_150000_create_central_wallet_ceremony_tables.php',
            '--force' => true,
        ])->assertExitCode(0);

        $this->assertFalse(Schema::hasTable('central_wallet_ceremony_identities'));
        $this->assertFalse(Schema::hasTable('central_wallet_ceremony_proof_consumptions'));
        $this->assertFalse($this->indexExists('central_wallet_account_links', 'cw_account_links_site_user_active_uq'));
        $this->assertFalse($this->indexExists('central_wallet_account_links', 'cw_account_links_site_wallet_active_uq'));
    }

    private function indexExists(string $table, string $indexName): bool
    {
        $driver = Schema::getConnection()->getDriverName();

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
            $rows = DB::select("SHOW INDEX FROM `{$table}` WHERE Key_name = ?", [$indexName]);

            return $rows !== [];
        }

        return false;
    }
}
