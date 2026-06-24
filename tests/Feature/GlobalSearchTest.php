<?php

namespace Tests\Feature;

use App\Models\ApprovalNumber;
use App\Models\Incident;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\SearchService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class GlobalSearchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_guests_are_redirected_from_search(): void
    {
        $this->get(route('search.index'))
            ->assertRedirect(route('login'));
    }

    public function test_search_returns_grouped_results_with_detail_links(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD3421021',
            'serial_number' => 'SN-ABC-001',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'transaction_id' => 'TXN-10001',
            'customer_name' => 'Jane Doe',
            'customer_email' => 'jane@example.com',
            'customer_phone' => '9876543210',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-2026-000123',
            'category' => 'Hardware',
            'source' => 'call',
            'title' => 'Screen issue',
            'description' => 'Customer reported a cracked screen.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $approval = ApprovalNumber::query()->create([
            'approval_number' => 'AP-2026-000045',
            'description' => 'Batch approval',
            'created_by' => $user->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-2026-000011',
            'amount' => 1500.00,
            'reason' => 'Duplicate charge',
            'status' => 'pending',
            'refund_transaction_id' => 'RFTX-90001',
            'requested_by' => $user->id,
        ]);

        $response = $this->actingAs($user)->get(route('search.index', ['q' => 'RD3421021']));

        $response->assertOk();
        $response->assertSee('Orders');
        $response->assertSee('Incidents');
        $response->assertSee('Refunds');
        $response->assertSee('RD3421021');
        $response->assertSee('INC-2026-000123');
        $response->assertSee('REF-2026-000011');
        $response->assertSee(route('orders.show', $order), false);
        $response->assertSee(route('incidents.show', $incident), false);
        $response->assertSee(route('refunds.show', $refund), false);

        $this->actingAs($user)
            ->get(route('search.index', ['q' => 'AP-2026-000045']))
            ->assertOk()
            ->assertSee('Approvals')
            ->assertSee('AP-2026-000045')
            ->assertSee(route('approvals.show', $approval), false);
    }

    public function test_search_supports_customer_email_partial_match(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD9990001',
            'serial_number' => 'SN-EMAIL-001',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'customer_email' => 'support.customer@example.com',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('search.index', ['q' => 'support.customer']))
            ->assertOk()
            ->assertSee('RD9990001')
            ->assertSee(route('orders.show', $order), false);
    }

    public function test_search_finds_order_by_exact_customer_id(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CUST-001',
            'serial_number' => 'SN-CUST-001',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'customer_id' => 'TEST2345',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('search.index', ['q' => 'TEST2345']))
            ->assertOk()
            ->assertSee('RD-CUST-001')
            ->assertSee('TEST2345')
            ->assertSee(route('orders.show', $order), false);
    }

    public function test_search_finds_order_by_partial_customer_id(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CUST-002',
            'serial_number' => 'SN-CUST-002',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'customer_id' => 'TEST2345',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('search.index', ['q' => 'TEST23']))
            ->assertOk()
            ->assertSee('RD-CUST-002')
            ->assertSee('TEST2345')
            ->assertSee(route('orders.show', $order), false);
    }

    public function test_search_prioritizes_exact_customer_id_over_partial_match(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD-CUST-PARTIAL',
            'serial_number' => 'SN-CUST-PARTIAL',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'customer_id' => 'TEST234567',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        Order::query()->create([
            'order_id' => 'RD-CUST-EXACT',
            'serial_number' => 'SN-CUST-EXACT',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'customer_id' => 'TEST2345',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $results = app(SearchService::class)->search($user, 'TEST2345');

        $this->assertSame('RD-CUST-EXACT', $results['orders']->first()->order_id);
    }

    public function test_search_finds_incident_through_related_order_customer_id(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-INC-CUST',
            'serial_number' => 'SN-INC-CUST',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'customer_id' => 'TEST2345',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-CUST-0001',
            'category' => 'Hardware',
            'source' => 'call',
            'title' => 'Battery issue',
            'description' => 'Customer reported battery drain.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('search.index', ['q' => 'TEST2345']))
            ->assertOk()
            ->assertSee('Incidents')
            ->assertSee('INC-CUST-0001')
            ->assertSee('TEST2345')
            ->assertSee(route('incidents.show', $incident), false);
    }

    public function test_search_finds_refund_through_related_order_customer_id(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-REF-CUST',
            'serial_number' => 'SN-REF-CUST',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'customer_id' => 'TEST2345',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-REF-CUST-001',
            'category' => 'Billing',
            'source' => 'call',
            'title' => 'Refund request',
            'description' => 'Customer requested refund.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $refund = RefundRequest::query()->create([
            'order_id' => $order->id,
            'incident_id' => $incident->id,
            'reference_no' => 'REF-CUST-0001',
            'amount' => 500.00,
            'reason' => 'Customer cancellation',
            'status' => 'pending',
            'requested_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('search.index', ['q' => 'TEST2345']))
            ->assertOk()
            ->assertSee('Refunds')
            ->assertSee('REF-CUST-0001')
            ->assertSee('TEST2345')
            ->assertSee(route('refunds.show', $refund), false);
    }

    public function test_search_prioritizes_exact_order_matches(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        Order::query()->create([
            'order_id' => 'RD34210219',
            'serial_number' => 'SN-PARTIAL-002',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        Order::query()->create([
            'order_id' => 'RD3421021',
            'serial_number' => 'SN-EXACT-001',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $results = app(SearchService::class)->search($user, 'RD3421021');

        $this->assertSame('RD3421021', $results['orders']->first()->order_id);
    }

    public function test_search_respects_view_permissions(): void
    {
        $user = User::factory()->create();

        Order::query()->create([
            'order_id' => 'RD3421021',
            'serial_number' => 'SN-RESTRICTED',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
        ]);

        ApprovalNumber::query()->create([
            'approval_number' => 'AP-2026-000045',
            'created_by' => $user->id,
        ]);

        $results = app(SearchService::class)->search($user, 'RD3421021');

        $this->assertNull($results['orders']);
        $this->assertNull($results['incidents']);
        $this->assertNull($results['approvals']);
        $this->assertNull($results['refunds']);

        $this->actingAs($user)
            ->get(route('search.index', ['q' => 'RD3421021']))
            ->assertOk()
            ->assertSee('No results found');
    }

    public function test_approval_show_page_is_accessible_with_permission(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $approval = ApprovalNumber::query()->create([
            'approval_number' => 'AP-2026-000045',
            'description' => 'Batch approval',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('approvals.show', $approval))
            ->assertOk()
            ->assertSee('AP-2026-000045')
            ->assertSee('Batch approval');
    }
}
