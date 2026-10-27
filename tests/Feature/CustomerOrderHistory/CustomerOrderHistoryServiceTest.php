<?php

namespace Tests\Feature\CustomerOrderHistory;

use App\Models\Order;
use App\Models\User;
use App\Services\CustomerOrderHistory\CustomerOrderHistoryService;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerOrderHistoryServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_returns_orders_for_same_phone_newest_first(): void
    {
        $user = User::factory()->create();

        $older = Order::query()->create([
            'order_id' => 'RD3463001',
            'customer_phone' => '9876543210',
            'serial_number' => '1111111',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $older->forceFill(['created_at' => now()->subDays(10)])->save();

        $newer = Order::query()->create([
            'order_id' => 'RB1045',
            'customer_phone' => '9876543210',
            'serial_number' => '2222222',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);
        $newer->forceFill(['created_at' => now()->subDay()])->save();

        $service = app(CustomerOrderHistoryService::class);
        $result = $service->historyForContext(phone: '9876543210');

        $this->assertTrue($result['resolved']);
        $this->assertCount(2, $result['orders']);
        $this->assertSame('RB1045', $result['orders'][0]['order_id']);
        $this->assertSame('RD3463001', $result['orders'][1]['order_id']);
        $this->assertSame($newer->id, $result['orders'][0]['id']);
        $this->assertSame($older->id, $result['orders'][1]['id']);
    }

    public function test_cross_spoke_match_via_shared_cashfree_customer_id(): void
    {
        $user = User::factory()->create();
        $customerId = (string) Str::uuid();

        Order::query()->create([
            'order_id' => 'RD3463012',
            'customer_id' => $customerId,
            'customer_phone' => '9000000001',
            'serial_number' => '3333333',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        Order::query()->create([
            'order_id' => 'RB1046',
            'customer_id' => $customerId,
            'customer_phone' => '9000000099',
            'serial_number' => null,
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $service = app(CustomerOrderHistoryService::class);
        $result = $service->historyForContext(businessOrderId: 'RB1046');

        $this->assertTrue($result['resolved']);
        $this->assertCount(2, $result['orders']);
        $orderIds = array_column($result['orders'], 'order_id');
        $this->assertContains('RD3463012', $orderIds);
        $this->assertContains('RB1046', $orderIds);
    }

    public function test_shared_email_links_orders_with_different_phones(): void
    {
        $user = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD9001',
            'customer_email' => 'repeat@example.com',
            'customer_phone' => '9111111111',
            'serial_number' => '4444444',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        Order::query()->create([
            'order_id' => 'RB9002',
            'customer_email' => 'repeat@example.com',
            'customer_phone' => '9222222222',
            'serial_number' => '5555555',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $service = app(CustomerOrderHistoryService::class);
        $result = $service->historyForContext(email: 'repeat@example.com');

        $this->assertTrue($result['resolved']);
        $this->assertCount(2, $result['orders']);
    }

    public function test_ambiguous_when_multiple_customer_ids_match(): void
    {
        $user = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD8001',
            'customer_id' => (string) Str::uuid(),
            'customer_email' => 'shared@example.com',
            'customer_phone' => '9333333333',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        Order::query()->create([
            'order_id' => 'RD8002',
            'customer_id' => (string) Str::uuid(),
            'customer_email' => 'shared@example.com',
            'customer_phone' => '9333333333',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $service = app(CustomerOrderHistoryService::class);
        $result = $service->historyForContext(email: 'shared@example.com');

        $this->assertFalse($result['resolved']);
        $this->assertTrue($result['ambiguous']);
        $this->assertSame([], $result['orders']);
    }

    public function test_unrelated_customers_do_not_see_each_others_orders(): void
    {
        $user = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD7001',
            'customer_phone' => '9444444444',
            'serial_number' => '6666666',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        Order::query()->create([
            'order_id' => 'RD7002',
            'customer_phone' => '9555555555',
            'serial_number' => '7777777',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $service = app(CustomerOrderHistoryService::class);
        $result = $service->historyForContext(phone: '9444444444');

        $this->assertTrue($result['resolved']);
        $this->assertCount(1, $result['orders']);
        $this->assertSame('RD7001', $result['orders'][0]['order_id']);
    }

    public function test_flags_missing_serial_and_duplicate_conflict(): void
    {
        $user = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD3463012',
            'customer_phone' => '9666666666',
            'serial_number' => '8888888',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incoming = Order::query()->create([
            'order_id' => 'RB1046',
            'customer_phone' => '9666666666',
            'serial_number' => null,
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        app(RadiumBoxOrderEnrichmentSyncStore::class)->mergeMetadata($incoming->id, [
            'serial_assignment_conflict' => [
                'serial' => '8888888',
                'owner_order_id' => 'RD3463012',
            ],
        ]);

        $service = app(CustomerOrderHistoryService::class);
        $result = $service->historyForContext(businessOrderId: 'RB1046', highlightOrderDbId: $incoming->id);

        $rbRow = collect($result['orders'])->firstWhere('order_id', 'RB1046');
        $this->assertNotNull($rbRow);
        $this->assertTrue($rbRow['serial_missing']);
        $this->assertTrue(
            collect($rbRow['warnings'])->contains(fn (array $warning): bool => $warning['code'] === 'serial_missing'),
        );
        $this->assertTrue(
            collect($rbRow['warnings'])->contains(fn (array $warning): bool => $warning['code'] === 'enrichment_serial_skipped'),
        );
    }

    public function test_expired_validity_from_enrichment_metadata(): void
    {
        $user = User::factory()->create();

        $order = Order::query()->create([
            'order_id' => 'RD5001',
            'customer_phone' => '9777777777',
            'serial_number' => '9999999',
            'amc_status' => 'Expired',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $service = app(CustomerOrderHistoryService::class);
        $result = $service->historyForContext(phone: '9777777777');

        $row = collect($result['orders'])->firstWhere('order_id', 'RD5001');
        $this->assertSame('expired', $row['validity_status']);
        $this->assertSame('Expired (enrichment)', $row['validity_label']);
    }

    public function test_customer_order_history_api_requires_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->getJson(route('customer-order-history.show', ['phone' => '9888888888']))
            ->assertForbidden();
    }

    public function test_customer_order_history_api_returns_payload_for_authorized_user(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD6001',
            'customer_phone' => '9888888888',
            'serial_number' => '1010101',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->getJson(route('customer-order-history.show', ['phone' => '9888888888']))
            ->assertOk()
            ->assertJsonPath('order_history.resolved', true)
            ->assertJsonPath('order_history.orders.0.order_id', 'RD6001');
    }
}
