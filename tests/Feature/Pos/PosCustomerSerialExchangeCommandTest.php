<?php

namespace Tests\Feature\Pos;

use App\Enums\PosCustomerRequestedSerialExchangeStatutoryTreatment;
use App\Models\PosCustomerRequestedSerialExchange;
use App\Models\User;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PosCustomerSerialExchangeCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_production_environment_fails_before_mutation(): void
    {
        $this->app['env'] = 'production';

        $exit = Artisan::call('pos:customer-serial-exchange', [
            '--dry-run' => true,
            '--sale-id' => 1,
            '--original' => 'SN1',
            '--replacement' => 'SN2',
            '--idempotency-key' => 'k1',
            '--reason' => 'reason',
            '--attestation' => 'attest',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('not available', Artisan::output());
        $this->assertSame(0, PosCustomerRequestedSerialExchange::query()->count());
    }

    public function test_dry_run_does_not_create_exchange_rows(): void
    {
        $exit = Artisan::call('pos:customer-serial-exchange', [
            '--dry-run' => true,
            '--sale-id' => 1,
            '--original' => 'SN1',
            '--replacement' => 'SN2',
            '--idempotency-key' => 'k1',
            '--reason' => 'reason',
            '--attestation' => 'attest',
        ]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, PosCustomerRequestedSerialExchange::query()->count());
    }

    public function test_inactive_actor_is_rejected(): void
    {
        $user = User::factory()->create(['is_active' => false, 'email' => 'inactive@example.com']);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $exit = Artisan::call('pos:customer-serial-exchange', [
            '--sale-id' => 1,
            '--original' => 'SN1',
            '--replacement' => 'SN2',
            '--idempotency-key' => 'k1',
            '--reason' => 'reason',
            '--attestation' => 'attest',
            '--actor' => 'inactive@example.com',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('inactive', strtolower(Artisan::output()));
    }

    public function test_actor_without_execute_permission_is_rejected(): void
    {
        $user = User::factory()->create(['is_active' => true, 'email' => 'viewer@example.com']);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $exit = Artisan::call('pos:customer-serial-exchange', [
            '--sale-id' => 1,
            '--original' => 'SN1',
            '--replacement' => 'SN2',
            '--idempotency-key' => 'k1',
            '--reason' => 'reason',
            '--attestation' => 'attest',
            '--actor' => 'viewer@example.com',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('lacks permission', Artisan::output());
    }

    public function test_statutory_action_requires_statutory_permission(): void
    {
        $executor = User::factory()->create(['is_active' => true, 'email' => 'exec@example.com']);
        $executor->givePermissionTo([
            RolePermissionSeeder::PERMISSION_POS_CUSTOMER_SERIAL_EXCHANGE_EXECUTE,
            RolePermissionSeeder::PERMISSION_POS_CUSTOMER_SERIAL_EXCHANGE_ATTEST,
        ]);

        $exit = Artisan::call('pos:customer-serial-exchange', [
            '--action' => 'record-statutory',
            '--sale-id' => 1,
            '--original' => 'SN1',
            '--replacement' => 'SN2',
            '--idempotency-key' => 'k1',
            '--reason' => 'reason',
            '--statutory-decision' => PosCustomerRequestedSerialExchangeStatutoryTreatment::SerialOnlyNoInvoiceChange->value,
            '--statutory-evidence' => 'memo',
            '--actor' => 'exec@example.com',
        ]);

        $this->assertSame(1, $exit);
        $this->assertStringContainsString('statutory', strtolower(Artisan::output()));
    }
}
