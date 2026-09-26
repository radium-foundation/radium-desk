<?php

namespace Tests\Feature\Finance;

use App\Enums\CommerceOrderStatus;
use App\Enums\InventorySaleStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\InventoryBranch;
use App\Models\InventorySale;
use App\Models\Order;
use App\Models\StatutoryInvoiceItem;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Reports\CaMonthly\CaMonthlyReportOrderType;
use App\Reports\CaMonthly\CaMonthlyReportPaymentChannelResolver;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class CaMonthlyReportCorrectionsTest extends TestCase
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

    public function test_delhi_branch_normalizes_from_inventory_branch_name(): void
    {
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'branch_id' => $branch->id,
        ]);

        $this->assertSame('Delhi', $this->firstRow()[0]);
    }

    public function test_radium_delhi_commerce_branch_code_normalizes_to_delhi(): void
    {
        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'branch_id' => null,
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RD-BRANCH-NORM',
            'source_order_id' => 'RD-BRANCH-NORM',
        ]);

        CommerceOrder::query()->create([
            'order_no' => 'CO-RD-BRANCH-NORM',
            'channel' => StatutoryInvoiceChannel::RdServiceIn,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RD-BRANCH-NORM',
            'source_order_id' => 'RD-BRANCH-NORM',
            'idempotency_key' => 'statutory:rdservice_in:commerce_order:RD-BRANCH-NORM',
            'payload_hash' => hash('sha256', 'RD-BRANCH-NORM'),
            'status' => CommerceOrderStatus::Invoiced,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'billing_state' => 'Delhi',
            'billing_address' => '1 Test Street',
            'shipping_address' => '1 Test Street',
            'branch_code' => 'radium_delhi',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'order_value' => 118.00,
            'ordered_at' => '2026-09-05 08:00:00',
            'received_at' => now(),
            'statutory_invoice_id' => $invoice->id,
        ]);

        $this->assertSame('Delhi', $this->firstRow()[0]);
    }

    public function test_blank_branch_remains_blank_when_no_authoritative_source_exists(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'branch_id' => null,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RB-BLANK-BRANCH',
        ]);

        $preflight = app(CaMonthlyStatutoryLineReadModel::class)->preflight($this->request());

        $this->assertSame('', $this->firstRow()[0]);
        $this->assertSame(1, $preflight->missingBranchInvoiceCount);
    }

    public function test_pos_hardware_order_type_exports_as_goods(): void
    {
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);

        $sale = InventorySale::query()->create([
            'sale_no' => 'POS-GOODS-1',
            'invoice_number' => 'INV-GOODS-1',
            'branch_id' => $branch->id,
            'status' => InventorySaleStatus::Completed,
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 18,
            'total' => 118,
            'payment_method' => 'Cash',
            'payment_reference' => 'CASH-GOODS-1',
            'finance_handoff_status' => 'posted',
            'completed_at' => '2026-09-10 10:00:00',
        ]);

        $this->makeHardwareTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'invoice_number' => 'INV-GOODS-1',
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'inventory_sale_id' => $sale->id,
            'payment_method' => 'Cash',
            'invoice_value' => '118.00',
        ]);

        $this->assertSame(CaMonthlyReportOrderType::HARDWARE, $this->firstRow()[5]);
        $this->assertSame('Goods', $this->firstRow()[5]);
    }

    public function test_service_order_type_exports_as_service(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $this->assertSame(CaMonthlyReportOrderType::SERVICE, $this->firstRow()[5]);
    }

    public function test_cancelled_invoice_exports_status_and_is_excluded_from_preflight_totals(): void
    {
        $issued = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'invoice_number' => 'INV-ACTIVE-1',
            'taxable_value' => '100.00',
            'invoice_value' => '118.00',
        ]);

        $cancelled = $this->makeTaxInvoice([
            'issued_at' => '2026-09-11 10:00:00',
            'invoice_number' => 'INV-CANCELLED-1',
            'status' => StatutoryInvoiceStatus::Cancelled,
            'taxable_value' => '200.00',
            'invoice_value' => '236.00',
        ]);

        $rows = app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->request());
        $preflight = app(CaMonthlyStatutoryLineReadModel::class)->preflight($this->request());

        $this->assertCount(2, $rows);
        $this->assertSame('Issued', $rows[0][3]);
        $this->assertSame('Cancelled', $rows[1][3]);
        $this->assertSame('100.00', $preflight->taxableAmountTotal);
        $this->assertSame('118.00', $preflight->totalAmountTotal);
        $this->assertSame(1, $preflight->cancelledIncludedCount);
        $this->assertSame(1, $preflight->cancelledExcludedFromTotalsCount);
    }

    public function test_cf_requires_genuine_cashfree_evidence(): void
    {
        $supportOrder = Order::query()->create([
            'order_id' => 'RD-CF-EVIDENCE',
            'serial_number' => 'SN-CF',
            'product_name' => 'Service',
            'device_model' => 'Model',
            'customer_name' => 'Buyer',
            'payment_method' => 'UPI',
            'cashfree_payment_id' => 'cf_pay_1',
            'status' => 'active',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'support_order_id' => $supportOrder->id,
            'payment_method' => 'cashfree',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CF, $row[21]);
        $this->assertSame('UPI', $row[22]);
    }

    public function test_upi_without_cashfree_evidence_leaves_payment_channel_unclassified(): void
    {
        $supportOrder = Order::query()->create([
            'order_id' => 'RD-UPI-ONLY',
            'serial_number' => 'SN-UPI',
            'product_name' => 'Service',
            'device_model' => 'Model',
            'customer_name' => 'Buyer',
            'payment_method' => 'UPI',
            'status' => 'active',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'support_order_id' => $supportOrder->id,
            'payment_method' => 'UPI',
            'payment_reference' => 'MANUAL-UPI',
        ]);

        $preflight = app(CaMonthlyStatutoryLineReadModel::class)->preflight($this->request());
        $row = $this->firstRow();

        $this->assertSame('', $row[21]);
        $this->assertSame('UPI', $row[22]);
        $this->assertSame(1, $preflight->unclassifiedPaymentChannelCount);
    }

    public function test_partial_paid_when_verified_payment_is_less_than_invoice_total(): void
    {
        $supportOrder = Order::query()->create([
            'order_id' => 'RD-PARTIAL-67506',
            'serial_number' => 'SN-PARTIAL',
            'product_name' => 'Service',
            'device_model' => 'Model',
            'customer_name' => 'Buyer',
            'payment_method' => 'UPI',
            'payment_amount' => 599.00,
            'cashfree_payment_id' => 'cf_partial_1',
            'status' => 'active',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'support_order_id' => $supportOrder->id,
            'invoice_number' => 'INV-67506-STYLE',
            'payment_method' => 'cashfree',
            'taxable_value' => '507.64',
            'tax_total' => '91.37',
            'igst' => '91.37',
            'invoice_value' => '599.01',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_PARTIAL_PAID, $row[21]);
        $this->assertSame('599.01', $row[18]);
    }

    public function test_inv_67506_style_amounts_preserve_exact_invoice_total(): void
    {
        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-08 22:24:00',
            'invoice_number' => 'INV-67506-STYLE',
            'taxable_value' => '507.64',
            'tax_total' => '91.37',
            'igst' => '91.37',
            'invoice_value' => '599.01',
        ], [
            'taxable_value' => '422.89',
            'tax_total' => '76.12',
            'igst' => '76.12',
            'line_total' => '499.01',
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 2,
            'description' => 'AMC : 1 Year Standard',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => '84.75',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '84.75',
            'tax_total' => '15.25',
            'igst' => '15.25',
            'line_total' => '100.00',
        ]);

        $row = $this->firstRow();

        $this->assertSame('507.64', $row[12]);
        $this->assertSame('91.37', $row[14]);
        $this->assertSame('599.01', $row[18]);
    }

    public function test_export_contract_includes_status_column(): void
    {
        $this->assertContains('Status', CaMonthlyReportDefinition::HEADERS);
        $this->assertCount(24, CaMonthlyReportDefinition::HEADERS);
    }

    public function test_workbook_meta_formats_reporting_period_as_dd_mmm_yyyy(): void
    {
        $meta = app(CaMonthlyStatutoryLineReadModel::class)->workbookMeta($this->request());

        $this->assertSame('01-Sep-2026', $meta->periodFrom);
        $this->assertSame('21-Sep-2026', $meta->periodTo);
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
