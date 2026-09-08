<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\IncidentSource;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\InventoryBranch;
use App\Models\InventoryUserBranch;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class HardwareFulfilmentDashboardNavigationTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_hardware_row_with_fulfilment_shows_existing_show_link_for_authorized_user(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        [$incident, $fulfilment] = $this->hardwareCaseWithFulfilment('RDE902001');

        $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk()
            ->assertSee('data-incident-id="'.$incident->id.'"', false)
            ->assertSee('dashboard-case-row--clickable', false)
            ->assertSee('data-hardware-fulfilment-link', false)
            ->assertSee('Fulfilment / Shipment')
            ->assertSee(route('inventory.hardware-fulfilments.show', $fulfilment), false)
            ->assertDontSee('/inventory/shipments')
            ->assertDontSee('/fulfilment/shipments');
    }

    public function test_hardware_row_without_fulfilment_does_not_invent_a_link(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->hardwareCase('RIN902002');

        $html = $this->actingAs($admin)
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk()
            ->assertSee('data-incident-id="'.$incident->id.'"', false)
            ->assertDontSee('data-hardware-fulfilment-link', false)
            ->assertDontSee('Fulfilment / Shipment')
            ->getContent();

        $this->assertStringNotContainsString('/inventory/hardware-fulfilments/', $html);
        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_unauthorized_user_does_not_see_fulfilment_link(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        [$incident, $fulfilment] = $this->hardwareCaseWithFulfilment('RDE902003');
        $incident->forceFill(['assigned_to_user_id' => $agent->id])->save();

        $this->actingAs($agent)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('data-incident-id="'.$incident->id.'"', false)
            ->assertDontSee('data-hardware-fulfilment-link', false)
            ->assertDontSee(route('inventory.hardware-fulfilments.show', $fulfilment), false);
    }

    public function test_customer_360_related_menu_includes_fulfilment_link_when_resolvable(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        [$incident, $fulfilment] = $this->hardwareCaseWithFulfilment('RDE902004');

        $this->actingAs($admin)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('Open Order')
            ->assertSee('Fulfilment / Shipment')
            ->assertSee(route('inventory.hardware-fulfilments.show', $fulfilment), false)
            ->assertSee('open-hardware-fulfilment', false)
            ->assertSee('Hardware Fulfilment')
            ->assertSee('id="hardware-fulfilment"', false)
            ->assertSee('View fulfilment')
            ->assertSee('view-hardware-fulfilment', false)
            ->assertDontSee('Start Hardware Fulfilment');
    }

    public function test_customer_360_hardware_section_disables_start_without_fulfilment(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->hardwareCase('RDE902007');
        $incident->order?->forceFill([
            'cashfree_payment_id' => 'cf_RDE902007',
            'created_at' => '2026-09-07 10:00:00',
        ])->save();

        $this->actingAs($admin)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('Hardware Fulfilment')
            ->assertSee('id="hardware-fulfilment"', false)
            ->assertSee('Start Hardware Fulfilment')
            ->assertSee('Isolated ingest requires a verified Box handoff payload', false)
            ->assertDontSee('Fulfilment / Shipment');

        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_customer_360_rin_is_mapping_required_without_mutating_cta(): void
    {
        $admin = $this->userWithRole(RolePermissionSeeder::ROLE_ADMIN);
        $incident = $this->hardwareCase('RIN902008');

        $this->actingAs($admin)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('Hardware Fulfilment')
            ->assertSee('RIN')
            ->assertSee('Blocked — RIN mapping required')
            ->assertSee('Start Hardware Fulfilment')
            ->assertDontSee('Allocate Serial')
            ->assertDontSee('Issue Invoice')
            ->assertDontSee('Fulfilment / Shipment');

        $this->assertSame(0, HardwareFulfilment::query()->count());
    }

    public function test_customer_360_related_menu_omits_fulfilment_when_unauthorized(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        [$incident, $fulfilment] = $this->hardwareCaseWithFulfilment('RDE902005');

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('Open Order')
            ->assertDontSee('Fulfilment / Shipment')
            ->assertDontSee(route('inventory.hardware-fulfilments.show', $fulfilment), false);
    }

    public function test_branch_scope_hides_dashboard_link_and_still_denies_show(): void
    {
        $delhi = $this->branch('DELHI-RETAIL');
        $mumbai = $this->branch('MUMBAI');
        $operator = $this->userWithRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryUserBranch::query()->create([
            'user_id' => $operator->id,
            'branch_id' => $delhi->id,
        ]);

        [$incident, $fulfilment] = $this->hardwareCaseWithFulfilment('RDE902006', $mumbai->id);

        $this->actingAs($operator)
            ->get(route('dashboard', ['workspace' => 'hardware']))
            ->assertOk()
            ->assertSee('data-incident-id="'.$incident->id.'"', false)
            ->assertDontSee('data-hardware-fulfilment-link', false)
            ->assertDontSee(route('inventory.hardware-fulfilments.show', $fulfilment), false);

        $this->actingAs($operator)
            ->get(route('inventory.hardware-fulfilments.show', $fulfilment))
            ->assertForbidden();
    }

    /**
     * @return array{0: Incident, 1: HardwareFulfilment}
     */
    private function hardwareCaseWithFulfilment(string $orderId, ?int $branchId = null): array
    {
        $incident = $this->hardwareCase($orderId);
        $order = $incident->order;
        $this->assertNotNull($order);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$orderId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$orderId,
            'payload_hash' => hash('sha256', $orderId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $orderId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::Ingested,
            'support_order_id' => $order->id,
            'fulfilment_branch_id' => $branchId,
            'ingested_at' => now(),
        ]);

        return [$incident, $fulfilment];
    }

    private function hardwareCase(string $orderId): Incident
    {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => $orderId,
            'product_name' => 'MFS 110',
            'device_model' => 'MFS 110',
            'status' => 'active',
            'created_by' => $creator->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Hardware case '.$orderId,
            'description' => 'Hardware dashboard case.',
            'status' => 'open',
            'created_by' => $creator->id,
            'updated_by' => $creator->id,
        ]);
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole($role);

        return $user;
    }

    private function branch(string $code): InventoryBranch
    {
        return InventoryBranch::query()->firstOrCreate(
            ['code' => $code],
            ['name' => $code, 'is_active' => true],
        );
    }
}
