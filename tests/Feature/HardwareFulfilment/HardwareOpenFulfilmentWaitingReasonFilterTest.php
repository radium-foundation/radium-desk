<?php

namespace Tests\Feature\HardwareFulfilment;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\HardwareWorkspaceFilter;
use App\Enums\IncidentStatus;
use App\Enums\ShipmentStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\ChannelSkuMap;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\Incident;
use App\Models\InventoryProduct;
use App\Models\Order;
use App\Models\Shipment;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\DashboardPersonalizationService;
use App\Services\HardwareFulfilment\HardwareFulfilmentEligibility;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class HardwareOpenFulfilmentWaitingReasonFilterTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private User $hardwareOperator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
        $this->withoutVite();
        Http::fake();
        $this->seedCatalog();

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->hardwareOperator = User::factory()->create(['is_active' => true]);
        $this->hardwareOperator->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
    }

    public function test_admin_sees_waiting_reason_chips_on_open_fulfilments_tab(): void
    {
        $this->seedNeedsActionSet();

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'open']))
            ->assertOk()
            ->assertSee('aria-label="Open fulfilments waiting reason filters"', false)
            ->assertSee('All reasons', false)
            ->assertSee('Needs Action', false)
            ->assertSee('Product Mapping Required', false)
            ->assertSee('Awaiting Serial', false);
    }

    public function test_hardware_operator_does_not_see_waiting_reason_chips(): void
    {
        $this->seedNeedsActionSet();

        $this->actingAs($this->hardwareOperator)
            ->get(route('inventory.hardware-fulfilments.index', ['queue' => 'open']))
            ->assertOk()
            ->assertDontSee('aria-label="Open fulfilments waiting reason filters"', false);
    }

    public function test_waiting_reason_filter_returns_only_matching_orders(): void
    {
        $this->seedNeedsActionSet();

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'open',
                'waiting_reason' => HardwareWorkspaceFilter::AwaitingSerial->value,
            ]))
            ->assertOk()
            ->assertSee('RDE982003')
            ->assertDontSee('RDE982002')
            ->assertDontSee('RDE982004');
    }

    public function test_mapping_required_filter_on_open_queue(): void
    {
        $this->seedNeedsActionSet();

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'open',
                'waiting_reason' => HardwareWorkspaceFilter::MappingRequired->value,
            ]))
            ->assertOk()
            ->assertSee('RDE982002')
            ->assertDontSee('RDE982003');
    }

    public function test_invalid_waiting_reason_is_rejected_for_non_admin(): void
    {
        $this->seedNeedsActionSet();

        $this->actingAs($this->hardwareOperator)
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'open',
                'waiting_reason' => HardwareWorkspaceFilter::AwaitingSerial->value,
            ]))
            ->assertForbidden();
    }

    public function test_admin_without_hardware_dashboard_permission_gets_forbidden_on_waiting_reason_param(): void
    {
        $restricted = User::factory()->create(['is_active' => true]);
        $restricted->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        Role::findByName(RolePermissionSeeder::ROLE_ADMIN, 'web')
            ->revokePermissionTo(DashboardPersonalizationService::PERMISSION_HARDWARE_VIEW);

        $this->seedNeedsActionSet();

        $this->actingAs($restricted->fresh())
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'open',
                'waiting_reason' => HardwareWorkspaceFilter::NeedsAction->value,
            ]))
            ->assertForbidden();
    }

    public function test_waiting_reason_and_assignee_filters_combine(): void
    {
        $assignee = User::factory()->create(['is_active' => true, 'name' => 'Ops Assignee']);
        $assignee->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);

        $this->seedNeedsActionSet();
        $mapped = $this->fulfilment('RDE982099', HardwareFulfilmentState::ReadyForFulfilment);
        $this->createIncident($mapped, $assignee);

        $this->actingAs($this->admin)
            ->get(route('inventory.hardware-fulfilments.index', [
                'queue' => 'open',
                'waiting_reason' => HardwareWorkspaceFilter::AwaitingSerial->value,
                'assignee_id' => (string) $assignee->id,
            ]))
            ->assertOk()
            ->assertSee('RDE982099')
            ->assertDontSee('RDE982003');
    }

    private function seedNeedsActionSet(): void
    {
        $this->fulfilment('RDE982002', HardwareFulfilmentState::ReadyForFulfilment, mapped: false);
        $this->fulfilment('RDE982003', HardwareFulfilmentState::ReadyForFulfilment);
        $this->fulfilment('RDE982004', HardwareFulfilmentState::ShipmentCreated, serial: true, invoice: true, bound: true);
        $this->fulfilment(
            'RDE982005',
            HardwareFulfilmentState::AwbAssigned,
            serial: true,
            invoice: true,
            bound: true,
            awb: 'AWB982005',
            label: true,
            pickup: true,
            manifest: true,
        );
    }

    private function seedCatalog(): void
    {
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
    }

    private function fulfilment(
        string $sourceId,
        HardwareFulfilmentState $state,
        bool $serial = false,
        bool $invoice = false,
        bool $mapped = true,
        bool $bound = false,
        ?string $awb = null,
        bool $label = false,
        bool $pickup = false,
        bool $manifest = false,
    ): HardwareFulfilment {
        $creator = User::factory()->create(['is_active' => true]);
        $order = Order::query()->create([
            'order_id' => $sourceId,
            'product_name' => 'MFS 110',
            'status' => 'active',
            'customer_name' => 'Buyer '.$sourceId,
            'cashfree_payment_id' => 'cf_'.$sourceId,
            'created_by' => $creator->id,
        ]);

        $invoiceModel = null;
        if ($invoice) {
            $invoiceModel = StatutoryInvoice::query()->create([
                'invoice_number' => 'INV-'.$sourceId,
                'document_type' => 'tax_invoice',
                'status' => 'issued',
                'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
                'source_type' => 'commerce_order',
                'source_id' => $sourceId,
                'idempotency_key' => 'invoice:'.$sourceId,
                'invoice_value' => 2549,
                'issued_at' => now(),
            ]);
        }

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
            'statutory_invoice_id' => $invoiceModel?->id,
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'catalog_sku' => 'MFS110',
            'model_id' => $mapped ? 946 : 99999,
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
            'statutory_invoice_id' => $invoiceModel?->id,
            'ingested_at' => now(),
        ]);

        if ($serial) {
            HardwareFulfilmentSerial::query()->create([
                'hardware_fulfilment_id' => $fulfilment->id,
                'line_no' => 1,
                'position' => 1,
                'serial_number' => 'SN-'.$sourceId,
                'status' => HardwareFulfilmentSerialStatus::Allocated,
                'allocated_at' => now(),
            ]);
        }

        if ($bound || $awb !== null || $label || $pickup || $manifest) {
            $shipment = Shipment::query()->create([
                'shipment_no' => 'HW-'.$sourceId,
                'commerce_order_id' => $commerce->id,
                'hardware_fulfilment_id' => $fulfilment->id,
                'provider' => 'shiprocket',
                'status' => $awb !== null ? ShipmentStatus::AwbAssigned : ShipmentStatus::Created,
                'external_order_id' => $bound ? 'ext-'.$sourceId : null,
                'external_shipment_id' => $bound ? 'shp-'.$sourceId : null,
                'awb' => $awb,
                'label_url' => $label ? '/labels/'.$sourceId.'.pdf' : null,
                'pickup_requested_at' => $pickup ? now() : null,
                'manifest_id' => $manifest ? 'man-'.$sourceId : null,
                'idempotency_key' => 'ship:'.$sourceId,
                'correlation_id' => (string) Str::uuid(),
            ]);
            $fulfilment->forceFill([
                'shipment_id' => $shipment->id,
                'awb' => $awb,
            ])->save();
        }

        return $fulfilment->fresh() ?? $fulfilment;
    }

    private function createIncident(HardwareFulfilment $fulfilment, User $assignee): void
    {
        $order = Order::query()->findOrFail($fulfilment->support_order_id);
        Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'INC-'.$fulfilment->source_id,
            'category' => 'Hardware',
            'source' => 'call',
            'title' => 'Hardware '.$fulfilment->source_id,
            'description' => 'Test',
            'status' => IncidentStatus::Open,
            'assigned_to_user_id' => $assignee->id,
            'created_by' => $this->admin->id,
        ]);
    }
}
