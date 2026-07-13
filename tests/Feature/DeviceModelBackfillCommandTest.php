<?php

namespace Tests\Feature;

use App\Models\DeviceModel;
use App\Models\Order;
use App\Models\User;
use Database\Seeders\DeviceModelSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DeviceModelBackfillCommandTest extends TestCase
{
    use RefreshDatabase;

    private User $systemUser;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(DeviceModelSeeder::class);

        $this->systemUser = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'first_name' => 'Ira',
            'last_name' => 'Automation',
        ]);
        $this->systemUser->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);
    }

    public function test_command_is_registered(): void
    {
        $this->artisan('device-models:backfill --help')->assertSuccessful();
    }

    public function test_exact_match_assigns_device_model_id(): void
    {
        $deviceModel = DeviceModel::query()->where('name', 'MFS110')->firstOrFail();
        $order = $this->createLegacyOrder('RD-DM-EXACT', 'MFS110');

        $this->artisan('device-models:backfill', ['--force' => true])
            ->expectsConfirmation('You are about to assign device models to 1 order(s). Continue?', 'yes')
            ->assertSuccessful()
            ->expectsOutputToContain('Matched: 1')
            ->expectsOutputToContain('Assigned: 1');

        $order->refresh();

        $this->assertSame($deviceModel->id, $order->device_model_id);
        $this->assertSame('MFS110', $order->device_model);
        $this->assertSame($this->systemUser->id, $order->device_model_assigned_by_user_id);
        $this->assertNotNull($order->device_model_assigned_at);

        $this->assertDatabaseHas('audit_logs', [
            'event' => 'device-model.bulk-assigned',
            'auditable_type' => $order->getMorphClass(),
            'auditable_id' => $order->id,
            'user_id' => $this->systemUser->id,
        ]);
    }

    public function test_case_insensitive_match_assigns_device_model_id(): void
    {
        $deviceModel = DeviceModel::query()->where('name', 'MSO E3')->firstOrFail();
        $order = $this->createLegacyOrder('RD-DM-CASE', '  mso   e3  ');

        $this->artisan('device-models:backfill', ['--force' => true])
            ->expectsConfirmation('You are about to assign device models to 1 order(s). Continue?', 'yes')
            ->assertSuccessful()
            ->expectsOutputToContain('Assigned: 1');

        $order->refresh();

        $this->assertSame($deviceModel->id, $order->device_model_id);
        $this->assertSame('MSO E3', $order->device_model);
    }

    public function test_code_match_assigns_device_model_id(): void
    {
        $deviceModel = DeviceModel::query()->where('name', 'L1')->firstOrFail();
        $deviceModel->update(['code' => 'L1-CODE']);

        $order = $this->createLegacyOrder('RD-DM-CODE', 'l1-code');

        $this->artisan('device-models:backfill', ['--force' => true])
            ->expectsConfirmation('You are about to assign device models to 1 order(s). Continue?', 'yes')
            ->assertSuccessful()
            ->expectsOutputToContain('Assigned: 1');

        $this->assertSame($deviceModel->id, $order->fresh()->device_model_id);
    }

    public function test_already_assigned_orders_are_counted_and_unchanged(): void
    {
        $deviceModel = DeviceModel::query()->where('name', 'L0')->firstOrFail();
        $otherModel = DeviceModel::query()->where('name', 'L1')->firstOrFail();

        $order = Order::query()->create([
            'order_id' => 'RD-DM-ALREADY',
            'serial_number' => null,
            'product_name' => 'L0',
            'device_model' => 'L0',
            'device_model_id' => $deviceModel->id,
            'device_model_assigned_at' => now(),
            'device_model_assigned_by_user_id' => $this->systemUser->id,
            'status' => 'active',
            'created_by' => $this->systemUser->id,
        ]);

        $this->artisan('device-models:backfill', [
            '--force' => true,
            '--order' => 'RD-DM-ALREADY',
        ])
            ->expectsConfirmation('You are about to assign device models to 1 order(s). Continue?', 'yes')
            ->assertSuccessful()
            ->expectsOutputToContain('Already assigned: 1')
            ->expectsOutputToContain('Assigned: 0');

        $order->refresh();

        $this->assertSame($deviceModel->id, $order->device_model_id);
        $this->assertNotSame($otherModel->id, $order->device_model_id);
    }

    public function test_unknown_model_is_reported_as_unmatched(): void
    {
        $order = $this->createLegacyOrder('RD-DM-UNKNOWN', 'Totally Fake Model');

        $this->artisan('device-models:backfill', ['--force' => true])
            ->expectsConfirmation('You are about to assign device models to 1 order(s). Continue?', 'yes')
            ->assertSuccessful()
            ->expectsOutputToContain('Unmatched: 1')
            ->expectsOutputToContain('Totally Fake Model (1)');

        $this->assertNull($order->fresh()->device_model_id);
    }

    public function test_dry_run_is_default_and_does_not_write(): void
    {
        $order = $this->createLegacyOrder('RD-DM-DRY', 'MFS110');

        $this->artisan('device-models:backfill')
            ->assertSuccessful()
            ->expectsOutputToContain('Dry run — no changes will be written.')
            ->expectsOutputToContain('Matched: 1')
            ->expectsOutputToContain('Would assign: 1');

        $this->assertNull($order->fresh()->device_model_id);
    }

    public function test_explicit_dry_run_option_does_not_write(): void
    {
        $order = $this->createLegacyOrder('RD-DM-DRY-EXPLICIT', 'Morpho 1300');

        $this->artisan('device-models:backfill', ['--dry-run' => true])
            ->assertSuccessful()
            ->expectsOutputToContain('Would assign: 1');

        $this->assertNull($order->fresh()->device_model_id);
    }

    public function test_force_requires_confirmation_and_can_be_cancelled(): void
    {
        $order = $this->createLegacyOrder('RD-DM-CANCEL', 'MFS110');

        $this->artisan('device-models:backfill', ['--force' => true])
            ->expectsConfirmation('You are about to assign device models to 1 order(s). Continue?', 'no')
            ->assertSuccessful()
            ->expectsOutputToContain('Backfill cancelled.');

        $this->assertNull($order->fresh()->device_model_id);
    }

    public function test_limit_option_caps_processed_orders(): void
    {
        $this->createLegacyOrder('RD-DM-LIMIT-1', 'MFS110');
        $this->createLegacyOrder('RD-DM-LIMIT-2', 'MFS110');

        $this->artisan('device-models:backfill', [
            '--force' => true,
            '--limit' => 1,
        ])
            ->expectsConfirmation('You are about to assign device models to 1 order(s). Continue?', 'yes')
            ->assertSuccessful()
            ->expectsOutputToContain('Processed: 1')
            ->expectsOutputToContain('Assigned: 1');

        $this->assertSame(1, Order::query()->whereNotNull('device_model_id')->count());
        $this->assertSame(1, Order::query()->whereNull('device_model_id')->where('device_model', '!=', '')->count());
    }

    public function test_order_option_targets_a_single_order(): void
    {
        $this->createLegacyOrder('RD-DM-OTHER', 'MFS110');
        $target = $this->createLegacyOrder('RD-DM-TARGET', 'L1');

        $this->artisan('device-models:backfill', [
            '--force' => true,
            '--order' => 'RD-DM-TARGET',
        ])
            ->expectsConfirmation('You are about to assign device models to 1 order(s). Continue?', 'yes')
            ->assertSuccessful()
            ->expectsOutputToContain('Processed: 1')
            ->expectsOutputToContain('Assigned: 1');

        $this->assertNotNull($target->fresh()->device_model_id);
        $this->assertNull(Order::query()->where('order_id', 'RD-DM-OTHER')->value('device_model_id'));
    }

    public function test_force_and_dry_run_cannot_be_combined(): void
    {
        $this->artisan('device-models:backfill', [
            '--force' => true,
            '--dry-run' => true,
        ])
            ->assertFailed()
            ->expectsOutputToContain('Cannot combine --force and --dry-run.');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLegacyOrder(string $orderId, string $deviceModel, array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_id' => $orderId,
            'serial_number' => null,
            'product_name' => null,
            'device_model' => $deviceModel,
            'device_model_id' => null,
            'status' => 'active',
            'created_by' => $this->systemUser->id,
        ], $overrides));
    }
}
