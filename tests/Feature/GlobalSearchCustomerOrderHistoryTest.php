<?php

namespace Tests\Feature;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class GlobalSearchCustomerOrderHistoryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    private function createServiceCase(User $user, Order $order): Incident
    {
        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Global search order history case',
            'description' => 'Global search order history case.',
            'status' => IncidentStatus::Open,
            'created_by' => $user->id,
            'assigned_to_user_id' => $user->id,
        ]);
    }

    public function test_search_by_rb_order_id_includes_all_verified_customer_orders(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $customerId = (string) Str::uuid();

        $prior = Order::query()->create([
            'order_id' => 'RD3463012',
            'customer_id' => $customerId,
            'customer_phone' => '9000000001',
            'serial_number' => '8888888',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
        $prior->forceFill(['created_at' => now()->subDays(5)])->save();

        $current = Order::query()->create([
            'order_id' => 'RB1046',
            'customer_id' => $customerId,
            'customer_phone' => '9000000099',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = $this->createServiceCase($agent, $current);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RB1046']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonPath('incident_ids.0', $incident->id)
            ->assertJsonPath('order_history.resolved', true)
            ->assertJsonCount(2, 'order_history.orders')
            ->assertJsonPath('order_history.orders.0.order_id', 'RB1046')
            ->assertJsonPath('order_history.orders.1.order_id', 'RD3463012')
            ->assertJsonPath('order_history.orders.0.is_highlighted', true);
    }

    public function test_search_by_phone_includes_multiple_orders_for_same_customer(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $phone = '9876543210';

        $older = Order::query()->create([
            'order_id' => 'RD3463001',
            'customer_phone' => $phone,
            'serial_number' => '1111111',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);
        $older->forceFill(['created_at' => now()->subDays(3)])->save();

        $newer = Order::query()->create([
            'order_id' => 'RB1045',
            'customer_phone' => $phone,
            'serial_number' => '2222222',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = $this->createServiceCase($agent, $newer);

        $response = $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => $phone]))
            ->assertOk()
            ->assertJsonPath('incident_ids.0', $incident->id)
            ->assertJsonPath('order_history.resolved', true);

        $orderIds = collect($response->json('order_history.orders'))->pluck('order_id')->all();
        $this->assertSame(['RB1045', 'RD3463001'], $orderIds);
    }

    public function test_order_history_omitted_without_orders_view_permission(): void
    {
        $user = User::factory()->create();
        $user->givePermissionTo('incidents.view');

        $order = Order::query()->create([
            'order_id' => 'RB2001',
            'customer_phone' => '9111111111',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->createServiceCase($user, $order);

        $this->actingAs($user)
            ->getJson(route('search.index', ['q' => 'RB2001']))
            ->assertOk()
            ->assertJsonPath('match_count', 1)
            ->assertJsonMissingPath('order_history');
    }

    public function test_unrelated_customer_orders_are_not_merged_into_history(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD9999001',
            'customer_phone' => '9000111100',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $target = Order::query()->create([
            'order_id' => 'RB3003',
            'customer_phone' => '9000222200',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->createServiceCase($agent, $target);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RB3003']))
            ->assertOk()
            ->assertJsonPath('order_history.resolved', true)
            ->assertJsonCount(1, 'order_history.orders')
            ->assertJsonPath('order_history.orders.0.order_id', 'RB3003');
    }

    public function test_expired_prior_rd_does_not_surface_reusable_completion_in_order_history(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $customerId = (string) Str::uuid();

        $expired = Order::query()->create([
            'order_id' => 'RD3463012',
            'customer_id' => $customerId,
            'customer_phone' => '9000000001',
            'serial_number' => '8888888',
            'device_model' => 'MFS 110',
            'amc_status' => 'Expired',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->mergeMetadata($expired->id, [
            'warranty_expiry' => now()->subYear()->toDateString(),
        ]);

        $current = Order::query()->create([
            'order_id' => 'RB1046',
            'customer_id' => $customerId,
            'customer_phone' => '9000000099',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $this->createServiceCase($agent, $current);

        $response = $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RB1046']))
            ->assertOk()
            ->assertJsonPath('order_history.resolved', true);

        $expiredRow = collect($response->json('order_history.orders'))
            ->firstWhere('order_id', 'RD3463012');

        $this->assertSame('expired', $expiredRow['validity_status'] ?? null);
        $this->assertNull($expiredRow['completion_details'] ?? null);
        $this->assertNull($response->json('order_history.valid_prior_rd_reference'));
    }

    public function test_service_case_actions_remain_for_customer_360(): void
    {
        $agent = User::factory()->create();
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RB1046',
            'customer_phone' => '9000000099',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $agent->id,
        ]);

        $incident = $this->createServiceCase($agent, $order);

        $this->actingAs($agent)
            ->getJson(route('search.index', ['q' => 'RB1046']))
            ->assertOk()
            ->assertJsonPath('results.0.type', 'service_case')
            ->assertJsonPath('results.0.actions.incident_id', $incident->id)
            ->assertJsonPath('results.0.actions.display_reference', $incident->display_reference);
    }
}
