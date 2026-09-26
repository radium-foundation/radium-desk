<?php

namespace Tests\Feature\Finance;

use App\Enums\InventorySaleStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Models\InventoryBranch;
use App\Models\InventorySale;
use App\Models\Order;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Reports\CaMonthly\CaMonthlyReportPaidAmountResolver;
use App\Reports\CaMonthly\CaMonthlyReportPaymentChannelResolver;
use App\Reports\CaMonthly\CaMonthlyReportPaymentEvidenceResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class CaMonthlyReportPartialPaidToleranceTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private const RANGE = [
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-21',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_export_contract_is_payment_channel_only(): void
    {
        $this->assertCount(22, CaMonthlyReportDefinition::HEADERS);
        $this->assertSame('Payment Channel', CaMonthlyReportDefinition::HEADERS[21]);
        $this->assertNotContains('Payment Method', CaMonthlyReportDefinition::HEADERS);
        $this->assertNotContains('Payment Reference', CaMonthlyReportDefinition::HEADERS);
    }

    public function test_internal_payment_evidence_resolvers_remain_available(): void
    {
        $supportOrder = Order::query()->create([
            'order_id' => 'RD-INTERNAL-EVIDENCE',
            'serial_number' => 'SN-INTERNAL',
            'product_name' => 'Service',
            'device_model' => 'Model',
            'customer_name' => 'Buyer',
            'payment_method' => 'UPI',
            'payment_amount' => 118.00,
            'cashfree_payment_id' => 'cf_internal_1',
            'status' => 'active',
        ]);

        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'support_order_id' => $supportOrder->id,
            'payment_method' => 'cashfree',
            'payment_reference' => 'RD-INTERNAL-EVIDENCE',
        ]);

        $evidenceResolver = app(CaMonthlyReportPaymentEvidenceResolver::class);
        $channelResolver = app(CaMonthlyReportPaymentChannelResolver::class);

        $this->assertSame('UPI', $evidenceResolver->resolvePaymentModeDisplay($invoice, null, null, null, 'UPI'));
        $this->assertSame(
            'RD-INTERNAL-EVIDENCE',
            $channelResolver->resolvePaymentReferenceDisplay($invoice, null, $supportOrder),
        );
    }

    public function test_inv_67642_style_one_paisa_difference_exports_cf_not_partial_paid(): void
    {
        $this->seedSupportOrderInvoice('RD-INV-67642', 599.00, 'INV-67642-STYLE', '599.01');

        $row = $this->firstRow();

        $this->assertSame('599.01', $row[18]);
        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CF, $row[21]);
        $this->assertCount(22, $row);
    }

    public function test_inv_67643_style_one_paisa_difference_exports_cf_not_partial_paid(): void
    {
        $this->seedSupportOrderInvoice('RD-INV-67643', 499.00, 'INV-67643-STYLE', '499.01');

        $row = $this->firstRow();

        $this->assertSame('499.01', $row[18]);
        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CF, $row[21]);
    }

    public function test_inv_67506_style_one_paisa_difference_exports_cf_not_partial_paid(): void
    {
        $this->seedSupportOrderInvoice('RD-INV-67506', 599.00, 'INV-67506-STYLE', '599.01');

        $row = $this->firstRow();

        $this->assertSame('599.01', $row[18]);
        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CF, $row[21]);
    }

    public function test_exactly_one_rupee_difference_is_not_partial_paid_and_retains_cash_channel(): void
    {
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);

        $sale = InventorySale::query()->create([
            'sale_no' => 'POS-TOL-1',
            'invoice_number' => 'INV-TOL-1',
            'branch_id' => $branch->id,
            'status' => InventorySaleStatus::Completed,
            'subtotal' => 847.46,
            'discount' => 0,
            'tax' => 152.54,
            'total' => 999.00,
            'payment_method' => 'Cash',
            'payment_reference' => 'CASH-TOL-1',
            'finance_handoff_status' => 'posted',
            'completed_at' => '2026-09-10 10:00:00',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'invoice_number' => 'INV-TOL-1',
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'inventory_sale_id' => $sale->id,
            'payment_method' => 'Cash',
            'invoice_value' => '1000.00',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CASH, $row[21]);
    }

    public function test_one_rupee_one_paisa_difference_exports_partial_paid(): void
    {
        $supportOrder = Order::query()->create([
            'order_id' => 'RD-PARTIAL-101',
            'serial_number' => 'SN-PARTIAL-101',
            'product_name' => 'Service',
            'device_model' => 'Model',
            'customer_name' => 'Buyer',
            'payment_method' => 'UPI',
            'payment_amount' => 998.99,
            'cashfree_payment_id' => 'cf_partial_101',
            'status' => 'active',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'support_order_id' => $supportOrder->id,
            'invoice_number' => 'INV-PARTIAL-101',
            'payment_method' => 'cashfree',
            'invoice_value' => '1000.00',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_PARTIAL_PAID, $row[21]);
    }

    public function test_zero_payment_exports_unpaid(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'invoice_number' => 'INV-UNPAID-1',
            'payment_method' => null,
            'payment_reference' => null,
            'invoice_value' => '1000.00',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_UNPAID, $row[21]);
    }

    public function test_tolerance_constant_matches_owner_rule(): void
    {
        $this->assertSame(1.00, CaMonthlyReportPaidAmountResolver::PARTIAL_PAID_TOLERANCE);
    }

    private function seedSupportOrderInvoice(string $orderId, float $paidAmount, string $invoiceNumber, string $invoiceValue): void
    {
        $supportOrder = Order::query()->create([
            'order_id' => $orderId,
            'serial_number' => 'SN-'.$orderId,
            'product_name' => 'Service',
            'device_model' => 'Model',
            'customer_name' => 'Buyer',
            'payment_method' => 'UPI',
            'payment_amount' => $paidAmount,
            'cashfree_payment_id' => 'cf_'.$orderId,
            'status' => 'active',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'support_order_id' => $supportOrder->id,
            'invoice_number' => $invoiceNumber,
            'payment_method' => 'cashfree',
            'payment_reference' => $orderId,
            'invoice_value' => $invoiceValue,
        ]);
    }

    /**
     * @return list<string>
     */
    private function firstRow(): array
    {
        return app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->request())[0];
    }

    private function request(): Request
    {
        return Request::create('/finance/reports/ca-monthly', 'GET', self::RANGE);
    }
}
