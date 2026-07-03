<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Jobs\RadiumBoxOrderEnrichmentJob;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\RadiumBox\RadiumBoxSyncRecoveryService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class RadiumBoxSyncRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'radiumbox.enabled' => true,
            'radiumbox.recovery.enabled' => true,
            'radiumbox.recovery.stale_pending_minutes' => 30,
            'radiumbox.recovery.schedule_limit' => 50,
            'radiumbox.recovery.max_recovery_attempts' => 10,
        ]);
    }

    public function test_scheduler_recovers_failed_orders_when_safe(): void
    {
        Queue::fake();

        $order = $this->createRecoverableOrder();
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);

        $syncStore->markFailed($order->id, 'Connection timed out');
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Failed,
            'radiumbox_last_sync_at' => now()->subHours(2),
            'radiumbox_sync_attempts' => 1,
        ]);

        $this->travel(2)->hours();

        Artisan::call('radiumbox:recover-sync');

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, fn (RadiumBoxOrderEnrichmentJob $job): bool => $job->orderId === $order->id);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Pending, $order->fresh()->radiumbox_sync_status);
    }

    public function test_scheduler_recovers_stale_pending_orders(): void
    {
        Queue::fake();

        $order = $this->createRecoverableOrder();
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);

        $syncStore->markPending($order->id);
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Pending,
            'radiumbox_last_sync_at' => now()->subMinutes(45),
            'radiumbox_sync_attempts' => 1,
        ]);

        Artisan::call('radiumbox:recover-sync');

        Queue::assertPushed(RadiumBoxOrderEnrichmentJob::class, fn (RadiumBoxOrderEnrichmentJob $job): bool => $job->orderId === $order->id);
    }

    public function test_scheduler_skips_orders_at_retry_limit(): void
    {
        Queue::fake();

        $order = $this->createRecoverableOrder();
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);

        $syncStore->markFailed($order->id, 'Persistent failure');
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Failed,
            'radiumbox_last_sync_at' => now()->subHours(2),
            'radiumbox_sync_attempts' => 10,
        ]);

        $this->travel(2)->hours();

        $result = app(RadiumBoxSyncRecoveryService::class)->recover();

        Queue::assertNothingPushed();
        $this->assertSame(1, $result->skipped);
        $this->assertSame(0, $result->recovered);
    }

    public function test_scheduler_skips_recent_pending_orders(): void
    {
        Queue::fake();

        $order = $this->createRecoverableOrder();
        $syncStore = app(RadiumBoxOrderEnrichmentSyncStore::class);

        $syncStore->markPending($order->id);
        $order->update([
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::Pending,
            'radiumbox_last_sync_at' => now()->subMinutes(5),
            'radiumbox_sync_attempts' => 1,
        ]);

        $result = app(RadiumBoxSyncRecoveryService::class)->recover();

        Queue::assertNothingPushed();
        $this->assertFalse(app(RadiumBoxSyncRecoveryService::class)->isStalePending($order->fresh()));
        $this->assertSame(1, $result->skipped);
    }

    public function test_recovery_command_respects_disabled_config(): void
    {
        config(['radiumbox.recovery.enabled' => false]);

        $this->artisan('radiumbox:recover-sync')
            ->expectsOutput('RadiumBox recovery is disabled.')
            ->assertSuccessful();
    }

    private function createRecoverableOrder(): Order
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        return Order::query()->create([
            'order_id' => 'RD-RECOVER-'.uniqid(),
            'cashfree_payment_id' => 'cf_pay_'.uniqid(),
            'serial_number' => null,
            'product_name' => null,
            'device_model' => null,
            'status' => 'active',
            'created_by' => $agent->id,
            'created_at' => now()->subHours(2),
        ]);
    }
}
