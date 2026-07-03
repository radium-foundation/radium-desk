<?php

namespace Tests\Feature\RadiumBox;

use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Models\Order;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class RadiumBoxOrderEnrichmentSyncStoreTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_new_orders_default_to_not_synced_status(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-SYNC-DEFAULT',
            'serial_number' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $store = app(RadiumBoxOrderEnrichmentSyncStore::class);

        $this->assertSame(RadiumBoxEnrichmentSyncStatus::NotSynced, $order->radiumbox_sync_status);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::NotSynced, $store->status($order->id));
    }

    public function test_sync_status_is_persisted_on_orders_table(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-SYNC-PERSIST',
            'serial_number' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $store = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $store->markPending($order->id);
        $store->recordProcessingAttempt($order->id);

        $order->refresh();

        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Pending, $order->radiumbox_sync_status);
        $this->assertSame(1, $order->radiumbox_sync_attempts);
        $this->assertNotNull($order->radiumbox_last_sync_at);

        $store->markFailed($order->id, 'Timeout');

        $order->refresh();

        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Failed, $order->radiumbox_sync_status);
        $this->assertSame('Timeout', $order->radiumbox_last_sync_error);

        $store->markSynced($order->id, ['warranty' => 'Active']);

        $order->refresh();

        $this->assertSame(RadiumBoxEnrichmentSyncStatus::Synced, $order->radiumbox_sync_status);
        $this->assertNull($order->radiumbox_last_sync_error);
        $this->assertSame('Active', $store->metadata($order->id)['warranty'] ?? null);
    }

    public function test_forget_resets_persisted_sync_fields_to_not_synced(): void
    {
        $agent = User::factory()->create();
        $order = Order::query()->create([
            'order_id' => 'RD-SYNC-FORGET',
            'serial_number' => null,
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $store = app(RadiumBoxOrderEnrichmentSyncStore::class);
        $store->markFailed($order->id, 'Failed once');
        $store->recordProcessingAttempt($order->id);

        $store->forget($order->id);

        $order->refresh();

        $this->assertSame(RadiumBoxEnrichmentSyncStatus::NotSynced, $order->radiumbox_sync_status);
        $this->assertNull($order->radiumbox_last_sync_at);
        $this->assertNull($order->radiumbox_last_sync_error);
        $this->assertSame(0, $order->radiumbox_sync_attempts);
        $this->assertSame(RadiumBoxEnrichmentSyncStatus::NotSynced, $store->status($order->id));
    }

    protected function tearDown(): void
    {
        Cache::flush();

        parent::tearDown();
    }
}
