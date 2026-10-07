<?php

namespace Tests\Unit\Reports\CaMonthly;

use App\Enums\InventorySaleStatus;
use App\Enums\ServiceOrderPaymentStatus;
use App\Enums\ServiceOrderStatus;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventorySale;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Reports\CaMonthly\CaMonthlyReportStateResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class CaMonthlyReportStateResolverTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private CaMonthlyReportStateResolver $resolver;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->resolver = app(CaMonthlyReportStateResolver::class);
    }

    public function test_invoice_billing_snapshot_state_wins(): void
    {
        $invoice = $this->makeTaxInvoice([
            'billing_address_structured' => ['state' => 'Karnataka', 'city' => 'Bengaluru', 'pincode' => '560001'],
            'place_of_supply_state' => 'Delhi',
        ]);

        $this->assertSame('Karnataka', $this->resolver->resolve($invoice));
    }

    public function test_commerce_billing_state_wins_over_place_of_supply(): void
    {
        $invoice = $this->makeTaxInvoice([
            'billing_address_structured' => null,
            'place_of_supply_state' => 'Karnataka',
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RD-STATE-PREC',
        ]);

        $commerce = new CommerceOrder([
            'billing_state' => 'Andhra Pradesh',
            'billing_address_structured' => null,
        ]);

        $this->assertSame('Andhra Pradesh', $this->resolver->resolve($invoice, $commerce));
    }

    public function test_place_of_supply_fallback_when_no_commerce_billing_state(): void
    {
        $invoice = $this->makeTaxInvoice([
            'billing_address_structured' => null,
            'place_of_supply_state' => 'Punjab',
        ]);

        $this->assertSame('Punjab', $this->resolver->resolve($invoice));
    }

    public function test_pos_sale_structured_state_after_place_of_supply_when_commerce_absent(): void
    {
        $branch = InventoryBranch::query()->create([
            'code' => 'MUM-RETAIL',
            'name' => 'Mumbai',
            'is_active' => true,
        ]);
        $sale = InventorySale::query()->create([
            'sale_no' => 'POS-STATE-1',
            'invoice_number' => 'INV-STATE-1',
            'branch_id' => $branch->id,
            'status' => InventorySaleStatus::Completed,
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 18,
            'total' => 118,
            'payment_method' => 'Cash',
            'payment_reference' => 'CASH-STATE-1',
            'finance_handoff_status' => 'posted',
            'billing_address_structured' => ['state' => 'Maharashtra', 'city' => 'Mumbai', 'pincode' => '400001'],
            'completed_at' => '2026-09-10 10:00:00',
        ]);

        $invoice = $this->makeTaxInvoice([
            'billing_address_structured' => null,
            'place_of_supply_state' => null,
            'source_type' => StatutoryInvoiceSourceType::InventorySale,
            'inventory_sale_id' => $sale->id,
        ]);
        $invoice->setRelation('inventorySale', $sale);

        $this->assertSame('Maharashtra', $this->resolver->resolve($invoice));
    }

    public function test_service_order_billing_state_fallback(): void
    {
        $service = $this->createServiceOrder('SVC-901', billingState: 'Tamil Nadu');

        $invoice = $this->makeTaxInvoice([
            'billing_address_structured' => null,
            'place_of_supply_state' => null,
            'source_type' => StatutoryInvoiceSourceType::ServiceOrder,
            'source_id' => 'SVC-901',
        ]);

        $this->assertSame('Tamil Nadu', $this->resolver->resolve($invoice, null, $service));
    }

    public function test_genuinely_unresolved_state_returns_null(): void
    {
        $invoice = $this->makeTaxInvoice([
            'billing_address_structured' => null,
            'place_of_supply_state' => null,
            'buyer_gstin' => '27AAICP1128M1Z9',
        ]);

        $this->assertNull($this->resolver->resolve($invoice));
    }

    private function createServiceOrder(string $orderNumber, ?string $billingState = null): ServiceOrder
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Service Buyer',
            'phone' => '9000000001',
        ]);
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI',
            'name' => 'Delhi',
            'is_active' => true,
        ]);

        return ServiceOrder::query()->create([
            'order_number' => $orderNumber,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'buyer_name' => $customer->name,
            'buyer_phone' => $customer->phone,
            'billing_state' => $billingState,
            'status' => ServiceOrderStatus::Open,
            'payment_status' => ServiceOrderPaymentStatus::Unpaid,
            'subtotal' => 100,
            'tax_total' => 18,
            'discount' => 0,
            'total' => 118,
            'created_by' => User::factory()->create()->id,
        ]);
    }
}
