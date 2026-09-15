<?php

namespace Tests\Feature\Dashboard;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareWorkspaceFilter;
use App\Enums\HardwareWorkspaceScope;
use App\Enums\StatutoryInvoiceChannel;
use App\Events\Dashboard\HardwareFulfilmentsUpdated;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\User;
use App\Services\DashboardBroadcastService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Tests\TestCase;

class HardwareFulfilmentDashboardRealtimeTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_successful_update_publishes_minimal_payload_on_dashboard_channel_after_commit(): void
    {
        Event::fake([HardwareFulfilmentsUpdated::class]);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $fulfilment = $this->fulfilment();

        app(DashboardBroadcastService::class)->hardwareFulfilmentUpdated($fulfilment, $admin);

        Event::assertDispatched(HardwareFulfilmentsUpdated::class, function (HardwareFulfilmentsUpdated $event) use ($admin, $fulfilment): bool {
            $payload = $event->broadcastWith();
            $channels = $event->broadcastOn();

            return $event->recipient->is($admin)
                && $event->broadcastAs() === 'HardwareFulfilmentsUpdated'
                && $channels[0]->name === 'private-dashboard.'.$admin->id
                && $payload['fulfilment_ids'] === [(int) $fulfilment->id]
                && ($payload['fulfilments'][0]['fulfilment_id'] ?? null) === (int) $fulfilment->id
                && ($payload['fulfilments'][0]['source_id'] ?? null) === 'RDE902910'
                && ($payload['fulfilments'][0]['queue'] ?? null) === 'hardware'
                && ! array_key_exists('html', $payload)
                && ! array_key_exists('customer', $payload)
                && ! array_key_exists('payment', $payload);
        });
    }

    public function test_live_hardware_endpoint_returns_row_scope_counts_and_filter_counts(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $fulfilment = $this->fulfilment();

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.live.hardware', [
                'ids' => [$fulfilment->id],
                'hw_scope' => HardwareWorkspaceScope::Active->value,
                'hw_filter' => HardwareWorkspaceFilter::Ready->value,
            ]))
            ->assertOk()
            ->assertJsonPath('rows.0.fulfilment_id', (int) $fulfilment->id)
            ->assertJsonPath('rows.0.source_id', 'RDE902910')
            ->assertJsonPath('rows.0.scope', HardwareWorkspaceScope::Active->value)
            ->assertJsonPath('rows.0.filter', HardwareWorkspaceFilter::Ready->value)
            ->assertJsonPath('rows.0.queue', HardwareWorkspaceFilter::Ready->value);

        $this->assertNotEmpty($response->json('rows.0.html'));
        $this->assertArrayHasKey(HardwareWorkspaceScope::Active->value, $response->json('scope_counts'));
        $this->assertArrayHasKey(HardwareWorkspaceFilter::Ready->value, $response->json('filter_counts'));
        $this->assertGreaterThanOrEqual(1, (int) $response->json('hardware_count'));
    }

    public function test_live_hardware_endpoint_accepts_legacy_hw_queue_as_filter_compatibility(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $fulfilment = $this->fulfilment();

        $this->actingAs($admin)
            ->getJson(route('dashboard.live.hardware', [
                'ids' => [$fulfilment->id],
                'hw_queue' => HardwareWorkspaceFilter::Ready->value,
            ]))
            ->assertOk()
            ->assertJsonPath('rows.0.fulfilment_id', (int) $fulfilment->id)
            ->assertJsonPath('rows.0.filter', HardwareWorkspaceFilter::Ready->value);
    }

    public function test_live_hardware_endpoint_removes_row_when_active_filter_does_not_match(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $fulfilment = $this->fulfilment();

        $this->actingAs($admin)
            ->getJson(route('dashboard.live.hardware', [
                'ids' => [$fulfilment->id],
                'hw_scope' => HardwareWorkspaceScope::Active->value,
                'hw_filter' => HardwareWorkspaceFilter::Pickup->value,
            ]))
            ->assertOk()
            ->assertJsonPath('rows', [])
            ->assertJsonPath('remove_fulfilment_ids.0', (int) $fulfilment->id);
    }

    public function test_live_hardware_endpoint_exposes_all_scope_and_filter_count_keys(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $this->fulfilment();

        $response = $this->actingAs($admin)
            ->getJson(route('dashboard.live.hardware', [
                'ids' => [999999],
                'hw_scope' => HardwareWorkspaceScope::Active->value,
                'hw_filter' => HardwareWorkspaceFilter::All->value,
            ]))
            ->assertOk();

        foreach (HardwareWorkspaceScope::cases() as $scope) {
            $this->assertArrayHasKey($scope->value, $response->json('scope_counts'));
        }

        foreach (HardwareWorkspaceFilter::cases() as $filter) {
            $this->assertArrayHasKey($filter->value, $response->json('filter_counts'));
        }
    }

    private function fulfilment(): HardwareFulfilment
    {
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-RDE902910',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE902910',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RDE902910',
            'payload_hash' => hash('sha256', 'RDE902910'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'ordered_at' => '2026-09-07 10:00:00',
        ]);

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RDE902910',
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::ReadyForFulfilment,
            'ingested_at' => now(),
        ]);

        return $fulfilment;
    }
}
