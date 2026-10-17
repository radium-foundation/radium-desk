<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\HardwareFulfilmentState;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\ReturnExchangeCaseStatus;
use App\Enums\CommerceOrderStatus;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventorySale;
use App\Models\InventoryUserBranch;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\ReturnExchangeCase;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\ReturnExchange\ReturnExchangeCaseIntakeService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class ReturnExchangeCaseIntakeTest extends TestCase
{
    use RefreshDatabase;

    private User $agent;

    private User $branchAdmin;

    private InventoryBranch $branchA;

    private InventoryBranch $branchB;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $this->branchA = InventoryBranch::query()->create([
            'code' => 'BR-A',
            'name' => 'Branch A',
            'is_active' => true,
        ]);
        $this->branchB = InventoryBranch::query()->create([
            'code' => 'BR-B',
            'name' => 'Branch B',
            'is_active' => true,
        ]);

        $this->agent = User::factory()->create(['is_active' => true]);
        $this->agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->branchAdmin = User::factory()->create(['is_active' => true]);
        $this->branchAdmin->givePermissionTo([
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_VIEW,
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_CREATE,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->branchAdmin->id,
            'branch_id' => $this->branchA->id,
        ]);
    }

    public function test_pos_case_is_recorded_without_mutating_sale_stock_or_movements(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $stock = app(InventoryStockService::class);
        $sales = app(PosSaleService::class);
        $product = InventoryProductFactory::serialized($this);
        $stock->stockInSerialized($product, $this->branchA, ['RC-9001'], $actor);

        $sale = $sales->completeSale(
            branch: $this->branchA,
            customer: ['name' => 'Buyer', 'phone' => '9999900100'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['RC-9001'],
            ]],
            paymentMethod: 'Cash',
            actor: $actor,
        );

        $saleLineId = (int) $sale->lines()->value('id');
        $saleSnapshot = $sale->fresh()->toArray();
        $movementCount = InventoryMovement::query()->count();
        $serialStatus = $sale->serials()->firstOrFail()->serial->fresh()->status;

        $central = User::factory()->create(['is_active' => true]);
        $central->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $case = app(ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => ReturnExchangeCaseSourceKind::PosSale->value,
            'inventory_sale_id' => $sale->id,
            'reason' => 'Customer reported defect',
            'lines' => [[
                'description' => $product->name,
                'qty' => 1,
                'serial_number' => 'RC-9001',
                'inventory_sale_line_id' => $saleLineId,
                'inventory_product_id' => $product->id,
            ]],
        ]);

        $this->assertSame(ReturnExchangeCaseStatus::Submitted, $case->status);
        $this->assertSame($this->branchA->id, $case->branch_id);
        $this->assertSame($sale->id, $case->inventory_sale_id);
        $this->assertDatabaseHas('return_exchange_cases', ['id' => $case->id]);
        $this->assertSame($saleSnapshot, $sale->fresh()->toArray());
        $this->assertSame($movementCount, InventoryMovement::query()->count());
        $this->assertSame($serialStatus, $sale->serials()->firstOrFail()->serial->fresh()->status);
        $this->assertSame(InventorySerialStatus::Sold, $serialStatus);
        $this->assertSame(0, RefundRequest::query()->count());
        $this->assertSame(0, StatutoryInvoice::query()->where('inventory_sale_id', $sale->id)->count());
    }

    public function test_service_order_case_links_order_without_refund_mutation(): void
    {
        $order = $this->createOrder(['payment_amount' => 1500]);

        $case = app(ReturnExchangeCaseIntakeService::class)->create($this->agent, [
            'source_kind' => ReturnExchangeCaseSourceKind::ServiceOrder->value,
            'order_id' => $order->id,
            'reason' => 'Service cancellation request',
            'lines' => [[
                'description' => 'Annual support plan',
                'qty' => 1,
            ]],
        ]);

        $this->assertNull($case->branch_id);
        $this->assertSame($order->id, $case->order_id);
        $this->assertSame((float) $order->fresh()->payment_amount, 1500.0);
        $this->assertSame(0, RefundRequest::query()->count());
    }

    public function test_idempotency_key_returns_same_case(): void
    {
        $order = $this->createOrder();
        $service = app(ReturnExchangeCaseIntakeService::class);
        $payload = [
            'source_kind' => ReturnExchangeCaseSourceKind::EcommerceOrder->value,
            'order_id' => $order->id,
            'idempotency_key' => 'idem-rc-001',
            'reason' => 'Duplicate submit',
            'lines' => [['description' => 'Item', 'qty' => 1]],
        ];

        $first = $service->create($this->agent, $payload);
        $second = $service->create($this->agent, $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ReturnExchangeCase::query()->count());
    }

    public function test_idempotency_replay_denied_when_actor_cannot_view_existing_case(): void
    {
        $owner = User::factory()->create(['is_active' => true]);
        $owner->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $other = User::factory()->create(['is_active' => true]);
        $other->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $order = $this->createOrder();
        $service = app(ReturnExchangeCaseIntakeService::class);
        $payload = [
            'source_kind' => ReturnExchangeCaseSourceKind::ServiceOrder->value,
            'order_id' => $order->id,
            'idempotency_key' => 'idem-rc-cross-agent',
            'reason' => 'Owner case',
            'lines' => [['description' => 'Item', 'qty' => 1]],
        ];

        $service->create($owner, $payload);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        $service->create($other, $payload);
    }

    public function test_rejects_serial_not_on_pos_sale(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $stock = app(InventoryStockService::class);
        $sales = app(PosSaleService::class);
        $product = InventoryProductFactory::serialized($this);
        $stock->stockInSerialized($product, $this->branchA, ['RC-9002'], $actor);
        $sale = $sales->completeSale(
            branch: $this->branchA,
            customer: ['name' => 'Buyer', 'phone' => '9999900101'],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['RC-9002']]],
            paymentMethod: 'Cash',
            actor: $actor,
        );

        $central = User::factory()->create(['is_active' => true]);
        $central->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->expectException(ValidationException::class);
        app(ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => ReturnExchangeCaseSourceKind::PosSale->value,
            'inventory_sale_id' => $sale->id,
            'reason' => 'Wrong serial',
            'lines' => [[
                'description' => 'Device',
                'serial_number' => 'WRONG-SERIAL',
            ]],
        ]);
    }

    public function test_branch_assigned_user_cannot_create_for_other_branch_pos_sale(): void
    {
        $centralActor = User::factory()->create(['is_active' => true]);
        $centralActor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $stock = app(InventoryStockService::class);
        $sales = app(PosSaleService::class);
        $product = InventoryProductFactory::serialized($this);
        $stock->stockInSerialized($product, $this->branchB, ['RC-9003'], $centralActor);
        $sale = $sales->completeSale(
            branch: $this->branchB,
            customer: ['name' => 'Buyer', 'phone' => '9999900102'],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['RC-9003']]],
            paymentMethod: 'Cash',
            actor: $centralActor,
        );

        $branchOnly = User::factory()->create(['is_active' => true]);
        $branchOnly->givePermissionTo([
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_VIEW,
            RolePermissionSeeder::PERMISSION_RETURNS_CASES_CREATE,
            RolePermissionSeeder::PERMISSION_INVENTORY_STOCK_IN,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $branchOnly->id,
            'branch_id' => $this->branchA->id,
        ]);

        $this->expectException(\Symfony\Component\HttpKernel\Exception\HttpException::class);
        app(ReturnExchangeCaseIntakeService::class)->create($branchOnly, [
            'source_kind' => ReturnExchangeCaseSourceKind::PosSale->value,
            'inventory_sale_id' => $sale->id,
            'reason' => 'Cross branch',
            'lines' => [['description' => 'Device', 'qty' => 1]],
        ]);
    }

    public function test_show_denies_cross_branch_view(): void
    {
        $actor = User::factory()->create(['is_active' => true]);
        $actor->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        $stock = app(InventoryStockService::class);
        $sales = app(PosSaleService::class);
        $product = InventoryProductFactory::serialized($this);
        $stock->stockInSerialized($product, $this->branchB, ['RC-9004'], $actor);
        $sale = $sales->completeSale(
            branch: $this->branchB,
            customer: ['name' => 'Buyer', 'phone' => '9999900103'],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['RC-9004']]],
            paymentMethod: 'Cash',
            actor: $actor,
        );

        $central = User::factory()->create(['is_active' => true]);
        $central->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $case = app(ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => ReturnExchangeCaseSourceKind::PosSale->value,
            'inventory_sale_id' => $sale->id,
            'reason' => 'Branch B case',
            'lines' => [['description' => 'Device', 'qty' => 1]],
        ]);

        $policy = app(\App\Policies\ReturnExchangeCasePolicy::class);
        $this->assertFalse($policy->view($this->branchAdmin, $case));
    }

    public function test_employee_without_permission_cannot_create(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_EMPLOYEE);

        $this->actingAs($user)
            ->get(route('return-cases.create'))
            ->assertForbidden();
    }

    public function test_ecommerce_fulfilment_case_resolves_order_and_branch(): void
    {
        $order = $this->createOrder();
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-RC-1',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => 'RC-SRC-1',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RC-SRC-1',
            'payload_hash' => hash('sha256', 'RC-SRC-1'),
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
            'source_id' => 'RC-SRC-1',
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::Ingested,
            'support_order_id' => $order->id,
            'fulfilment_branch_id' => $this->branchA->id,
            'ingested_at' => now(),
        ]);

        $central = User::factory()->create(['is_active' => true]);
        $central->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $case = app(ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => ReturnExchangeCaseSourceKind::EcommerceFulfilment->value,
            'hardware_fulfilment_id' => $fulfilment->id,
            'reason' => 'Return shipment',
            'lines' => [['description' => 'Hardware SKU', 'qty' => 1]],
        ]);

        $this->assertSame($fulfilment->id, $case->hardware_fulfilment_id);
        $this->assertSame($order->id, $case->order_id);
        $this->assertSame($this->branchA->id, $case->branch_id);
    }

    public function test_http_store_redirects_to_show(): void
    {
        $order = $this->createOrder();

        $response = $this->actingAs($this->agent)->post(route('return-cases.store'), [
            'source_kind' => ReturnExchangeCaseSourceKind::ServiceOrder->value,
            'order_id' => $order->id,
            'reason' => 'HTTP intake',
            'lines' => [['description' => 'Plan', 'qty' => 1]],
        ]);

        $case = ReturnExchangeCase::query()->firstOrFail();
        $response->assertRedirect(route('return-cases.show', $case));
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createOrder(array $overrides = []): Order
    {
        return Order::query()->create(array_merge([
            'order_id' => 'RB'.random_int(10000, 99999),
            'customer_name' => 'Test Customer',
            'customer_phone' => '9999900999',
            'payment_amount' => 0,
            'status' => 'active',
        ], $overrides));
    }
}

/**
 * @internal
 */
final class InventoryProductFactory
{
    /**
     * @return \App\Models\InventoryProduct
     */
    public static function serialized(TestCase $test): \App\Models\InventoryProduct
    {
        return \App\Models\InventoryProduct::query()->create([
            'sku' => 'RC-'.strtoupper(bin2hex(random_bytes(3))),
            'name' => 'Return case device',
            'gst_percentage' => 18,
            'unit_price' => 10000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }
}
