<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\IncidentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\Incident;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\User;
use App\Services\DashboardPersonalizationService;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class HardwareOpenFulfilmentAssigneeFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $hardwareOperator;

    private User $assigneeA;

    private User $assigneeB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutVite();
        Http::fake();

        $product = InventoryProduct::query()->create([
            'sku' => 'RBMFS110L1',
            'name' => 'MFS110',
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 2549,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        ChannelSkuMap::query()->create([
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'model_id' => 946,
            'inventory_product_id' => $product->id,
            'catalog_sku' => 'RBMFS110L1',
            'channel_sku' => '946',
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->hardwareOperator = User::factory()->create(['is_active' => true, 'name' => 'Hardware Operator']);
        $this->hardwareOperator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->assigneeA = User::factory()->create(['is_active' => true, 'name' => 'Assignee Alpha']);
        $this->assigneeA->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->assigneeB = User::factory()->create(['is_active' => true, 'name' => 'Assignee Beta']);
        $this->assigneeB->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
    }

    public function test_admin_sees_assignee_filter_on_open_fulfilments_tab(): void
    {
        $this->seedOpenFulfilmentPair();

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'open']))
            ->assertOk()
            ->assertSee('aria-label="Open fulfilments assignee filters"', false)
            ->assertSee('All assignees', false)
            ->assertSee('Unassigned', false)
            ->assertSee('Assignee Alpha', false);
    }

    public function test_hardware_operator_does_not_see_assignee_filter(): void
    {
        $this->seedOpenFulfilmentPair();

        $this->actingAs($this->hardwareOperator)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'open']))
            ->assertOk()
            ->assertDontSee('aria-label="Open fulfilments assignee filters"', false);
    }

    public function test_assignee_filter_returns_only_matching_orders(): void
    {
        [$fulfilmentA] = $this->seedOpenFulfilmentPair();

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'open',
                'assignee_id' => $this->assigneeA->id,
            ]))
            ->assertOk()
            ->assertSee($fulfilmentA->source_id)
            ->assertDontSee('RDEOPEN-B');
    }

    public function test_unassigned_filter_returns_only_unassigned_orders(): void
    {
        $this->seedOpenFulfilmentPair();
        $unassigned = $this->fulfilment('RDEOPEN-U', HardwareFulfilmentState::InvoiceIssued);

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'open',
                'assignee_id' => 'unassigned',
            ]))
            ->assertOk()
            ->assertSee($unassigned->source_id)
            ->assertDontSee('RDEOPEN-A')
            ->assertDontSee('RDEOPEN-B');
    }

    public function test_invalid_assignee_id_is_rejected_for_non_admin(): void
    {
        $this->seedOpenFulfilmentPair();

        $this->actingAs($this->hardwareOperator)
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'open',
                'assignee_id' => (string) $this->assigneeA->id,
            ]))
            ->assertForbidden();
    }

    public function test_admin_without_hardware_dashboard_permission_gets_forbidden_on_assignee_param(): void
    {
        $restricted = User::factory()->create(['is_active' => true]);
        $restricted->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        Role::findByName(RolePermissionSeeder::ROLE_ADMIN, 'web')
            ->revokePermissionTo(DashboardPersonalizationService::PERMISSION_HARDWARE_VIEW);

        $this->seedOpenFulfilmentPair();

        $this->actingAs($restricted->fresh())
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'open',
                'assignee_id' => 'unassigned',
            ]))
            ->assertForbidden();
    }

    public function test_assignee_and_shipment_status_filters_combine(): void
    {
        $this->fulfilment('RDEOPEN-READY-A', HardwareFulfilmentState::InvoiceIssued, [
            'invoice' => true,
            'serial' => true,
            'assignee' => $this->assigneeA,
        ]);
        $this->fulfilment('RDEOPEN-AWB-A', HardwareFulfilmentState::AwbAssigned, [
            'invoice' => true,
            'serial' => true,
            'bound' => true,
            'awb' => 'AWB-A',
            'assignee' => $this->assigneeA,
        ]);

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'open',
                'assignee_id' => (string) $this->assigneeA->id,
                'shipment_status' => 'ready',
            ]))
            ->assertOk()
            ->assertSee('RDEOPEN-READY-A')
            ->assertDontSee('RDEOPEN-AWB-A');
    }

    /**
     * @return array{0: HardwareFulfilment, 1: HardwareFulfilment}
     */
    private function seedOpenFulfilmentPair(): array
    {
        $a = $this->fulfilment('RDEOPEN-A', HardwareFulfilmentState::InvoiceIssued, [
            'invoice' => true,
            'serial' => true,
            'assignee' => $this->assigneeA,
        ]);
        $b = $this->fulfilment('RDEOPEN-B', HardwareFulfilmentState::InvoiceIssued, [
            'invoice' => true,
            'serial' => true,
            'assignee' => $this->assigneeB,
        ]);

        return [$a, $b];
    }

    /**
     * @param  array<string, mixed>  $opts
     */
    private function fulfilment(string $sourceId, HardwareFulfilmentState $state, array $opts = []): HardwareFulfilment
    {
        $order = Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'MFS 110',
            'status' => 'active',
            'created_by' => $this->admin->id,
        ]);

        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$sourceId,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:'.$sourceId,
            'payload_hash' => hash('sha256', $sourceId),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer '.$sourceId,
            'received_at' => now(),
            'ordered_at' => now(),
            'support_order_id' => $order->id,
        ]);

        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'catalog_sku' => 'MFS110',
            'model_id' => 946,
            'shipping_line_kind' => HardwareFulfilmentEligibility::PHYSICAL_LINE_KIND,
            'requires_shipping' => true,
            'description' => 'MFS110',
            'qty' => 1,
            'unit_price' => 2549,
            'gst_percentage' => 18,
            'taxable_value' => 2160.17,
            'tax_total' => 388.83,
            'line_total' => 2549,
        ]);

        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => $state,
            'support_order_id' => $order->id,
            'ingested_at' => now(),
        ]);

        if (isset($opts['assignee']) && $opts['assignee'] instanceof User) {
            Incident::query()->create([
                'order_id' => $order->id,
                'reference_no' => 'INC-'.$sourceId,
                'category' => 'Hardware',
                'source' => 'call',
                'title' => 'Hardware '.$sourceId,
                'description' => 'Test',
                'status' => IncidentStatus::Open,
                'assigned_to_user_id' => $opts['assignee']->id,
                'created_by' => $this->admin->id,
            ]);
        }

        return $fulfilment;
    }
}
