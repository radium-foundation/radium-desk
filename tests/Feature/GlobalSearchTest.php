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
            'reference_no' => 'SC-00099',
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
        $response->assertSee(config('ui.service_case.plural'));
        $response->assertSee('Refunds');
        $response->assertSee('RD3421021');
        $response->assertSee('SC00099');
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

    public function test_search_finds_order_by_serial_number(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SER-001',
            'serial_number' => 'SN-SEARCH-001',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('search.index', ['q' => 'SN-SEARCH-001']))
            ->assertOk()
            ->assertSee('RD-SER-001')
            ->assertSee(route('orders.show', $order), false);
    }

    public function test_search_finds_order_by_transaction_id(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-TXN-SRCH',
            'serial_number' => 'SN-TXN-SRCH',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'transaction_id' => 'TXN-SEARCH-001',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('search.index', ['q' => 'TXN-SEARCH-001']))
            ->assertOk()
            ->assertSee('RD-TXN-SRCH')
            ->assertSee(route('orders.show', $order), false);
    }

    public function test_search_finds_service_case_through_related_order_serial(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SC-SRCH',
            'serial_number' => 'SN-SC-001',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-00055',
            'category' => 'Hardware',
            'source' => 'call',
            'title' => 'Battery issue',
            'description' => 'Customer reported battery drain.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('search.index', ['q' => 'SN-SC-001']))
            ->assertOk()
            ->assertSee(config('ui.service_case.plural'))
            ->assertSee('SC00055')
            ->assertSee(route('incidents.show', $incident), false);
    }

    public function test_search_finds_service_case_by_normalized_reference_formats(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-SC-FMT',
            'serial_number' => 'SN-SC-FMT',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-00099',
            'category' => 'Hardware',
            'source' => 'call',
            'title' => 'Screen issue',
            'description' => 'Customer reported a cracked screen.',
            'status' => 'open',
            'created_by' => $user->id,
        ]);

        foreach (['SC99', 'SC00099', 'SC-00099', '00099', '99'] as $query) {
            $this->actingAs($user)
                ->get(route('search.index', ['q' => $query]))
                ->assertOk()
                ->assertSee('SC00099')
                ->assertSee(route('incidents.show', $incident), false);
        }
    }

    public function test_search_finds_order_by_customer_name_email_and_mobile(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-CUST-SRCH',
            'serial_number' => 'SN-CUST-SRCH',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'customer_name' => 'Priya Sharma',
            'customer_email' => 'priya@example.com',
            'customer_phone' => '9988776655',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        foreach (['Priya Sharma', 'priya@example.com', '9988776655'] as $query) {
            $this->actingAs($user)
                ->get(route('search.index', ['q' => $query]))
                ->assertOk()
                ->assertSee('RD-CUST-SRCH')
                ->assertSee(route('orders.show', $order), false);
        }
    }

    public function test_search_finds_refund_through_related_order_transaction_id(): void
    {
        $user = User::factory()->create();
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = Order::query()->create([
            'order_id' => 'RD-REF-TXN',
            'serial_number' => 'SN-REF-TXN',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'transaction_id' => 'TXN-REF-001',
            'status' => 'active',
            'created_by' => $user->id,
        ]);

        $incident = Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'SC-00056',
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
            'reference_no' => 'REF-TXN-0001',
            'amount' => 500.00,
            'reason' => 'Customer cancellation',
            'status' => 'pending',
            'requested_by' => $user->id,
        ]);

        $this->actingAs($user)
            ->get(route('search.index', ['q' => 'TXN-REF-001']))
            ->assertOk()
            ->assertSee('Refunds')
            ->assertSee('REF-TXN-0001')
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
