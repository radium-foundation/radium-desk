<?php

namespace Tests\Unit\Reports\CaMonthly;

use App\Enums\ServiceOrderPaymentStatus;
use App\Enums\ServiceOrderStatus;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Reports\CaMonthly\CaMonthlyReportOrderContextResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class CaMonthlyReportOrderContextResolverTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_numeric_service_source_id_resolves_order_date(): void
    {
        $service = $this->createServiceOrder('SVC-100', '2026-09-22 12:36:03');

        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-22 13:00:00',
            'source_type' => StatutoryInvoiceSourceType::ServiceOrder,
            'source_id' => (string) $service->id,
            'source_order_id' => 'SVC-100',
        ]);

        $context = app(CaMonthlyReportOrderContextResolver::class)
            ->resolveForInvoices([$invoice])[$invoice->id];

        $this->assertSame('2026-09-22', $context->orderDate);
        $this->assertSame('SVC-100', $context->orderId);
    }

    public function test_svc_prefixed_source_id_resolves_via_order_number(): void
    {
        $this->createServiceOrder('SVC-674', '2026-09-22 12:36:03');

        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-22 13:00:00',
            'source_type' => StatutoryInvoiceSourceType::ServiceOrder,
            'source_id' => 'SVC-674',
            'source_order_id' => 'SVC-674',
            'buyer_name' => 'Sundrop Brands Limited',
        ]);

        $context = app(CaMonthlyReportOrderContextResolver::class)
            ->resolveForInvoices([$invoice])[$invoice->id];

        $this->assertSame('2026-09-22', $context->orderDate);
        $this->assertSame('SVC-674', $context->orderId);
    }

    private function createServiceOrder(string $orderNumber, string $createdAt): ServiceOrder
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Service Buyer',
            'phone' => '9000000002',
        ]);
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI',
            'name' => 'Delhi',
            'is_active' => true,
        ]);

        $service = ServiceOrder::query()->create([
            'order_number' => $orderNumber,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'buyer_name' => $customer->name,
            'buyer_phone' => $customer->phone,
            'status' => ServiceOrderStatus::Open,
            'payment_status' => ServiceOrderPaymentStatus::Unpaid,
            'subtotal' => 100,
            'tax_total' => 18,
            'discount' => 0,
            'total' => 118,
            'created_by' => User::factory()->create()->id,
        ]);
        $service->forceFill([
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ])->save();

        return $service->fresh();
    }
}
