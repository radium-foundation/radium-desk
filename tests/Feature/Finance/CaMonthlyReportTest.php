<?php

namespace Tests\Feature\Finance;

use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\CustomerPayment;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\PaymentAllocation;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use App\Enums\CaMonthlyReportExportFormat;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Reports\CaMonthly\CaMonthlyReportOrderType;
use App\Services\Finance\CaMonthlyReportExportGenerator;
use App\Support\Finance\CaMonthlyReportXlsxWriter;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;
use ZipArchive;

class CaMonthlyReportTest extends TestCase
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

    public function test_admin_can_preview_ca_monthly_report_for_requested_range(): void
    {
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);

        $invoice = $this->makeTaxInvoice([
            'branch_id' => $branch->id,
            'issued_at' => '2026-09-10 10:00:00',
            'billing_address_structured' => [
                'state' => 'Delhi',
                'city' => 'Delhi',
                'pincode' => '110001',
            ],
        ]);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.index', self::RANGE))
            ->assertOk()
            ->assertSee('CA Monthly Report')
            ->assertSee($invoice->invoice_number)
            ->assertSee('RD Service')
            ->assertSee('Preflight summary')
            ->assertSee('Invoice preview')
            ->assertDontSee('data-invoice-id=', false);
    }

    public function test_date_range_is_inclusive_on_start_and_end_boundaries(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-01 00:00:00']);
        $this->makeTaxInvoice(['issued_at' => '2026-09-21 23:59:59']);
        $this->makeTaxInvoice(['issued_at' => '2026-09-22 00:00:00']);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);

        $this->assertSame(2, count($readModel->exportRows($this->request())));
    }

    public function test_zero_result_range_returns_no_rows(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-08-31 23:59:59']);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);

        $this->assertSame([], $readModel->exportRows($this->request()));
    }

    public function test_multi_line_invoice_produces_multiple_export_rows_and_grouped_preview(): void
    {
        $invoice = $this->makeTaxInvoice(['issued_at' => '2026-09-15 12:00:00']);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 2,
            'sku' => 'ADDON',
            'description' => 'Add-on Service',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => '50.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '50.00',
            'tax_total' => '9.00',
            'cgst' => '4.50',
            'sgst' => '4.50',
            'igst' => '0.00',
            'line_total' => '59.00',
        ]);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);

        $this->assertCount(1, $readModel->exportRows($this->request()));

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.index', self::RANGE))
            ->assertOk()
            ->assertSee('data-invoice-id="'.$invoice->id.'"', false)
            ->assertSee('Add-on Service');
    }

    public function test_xlsx_export_reproduces_exact_header_row_and_column_count(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $response = $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.export.xlsx', self::RANGE));

        $response->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();

        $headers = $this->readXlsxRow($path, 3);
        $firstDataRow = $this->readXlsxRow($path, 4);

        $this->assertSame(CaMonthlyReportDefinition::HEADERS, $headers);
        $this->assertCount(21, $headers);
        $this->assertSame('998313', $firstDataRow[10]);
        $this->assertSame('118.00', $firstDataRow[17]);
        $this->assertCount(21, app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->request())[0]);
    }

    public function test_service_ordertype_is_resolved_from_sac_code(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportOrderType::SERVICE, $row[4]);
    }

    public function test_hardware_ordertype_is_resolved_from_pos_channel_and_hsn(): void
    {
        $this->makeHardwareTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportOrderType::HARDWARE, $row[4]);
    }

    public function test_bundled_ordertype_is_resolved_for_hardware_and_service_lines(): void
    {
        $invoice = $this->makeHardwareTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 2,
            'sku' => 'SVC-ADD',
            'description' => 'Installation Service',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => '50.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '50.00',
            'tax_total' => '9.00',
            'cgst' => '4.50',
            'sgst' => '4.50',
            'igst' => '0.00',
            'line_total' => '59.00',
        ]);

        $rows = app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->request());

        $this->assertSame(CaMonthlyReportOrderType::BUNDLED, $rows[0][4]);
    }

    public function test_multiple_service_lines_remain_service_ordertype(): void
    {
        $invoice = $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 2,
            'sku' => 'AMC-ADD',
            'description' => 'AMC Support',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => '50.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '50.00',
            'tax_total' => '9.00',
            'cgst' => '4.50',
            'sgst' => '4.50',
            'igst' => '0.00',
            'line_total' => '59.00',
        ]);

        $rows = app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->request());

        $this->assertCount(1, $rows);
        $this->assertSame(CaMonthlyReportOrderType::SERVICE, $rows[0][4]);
    }

    public function test_date_of_order_and_date_of_invoice_are_independently_populated(): void
    {
        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-15 10:00:00',
            'channel' => StatutoryInvoiceChannel::RdServiceNet,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'ORD-DATE-TEST',
        ]);

        CommerceOrder::query()->create([
            'order_no' => 'CO-ORD-DATE-TEST',
            'channel' => StatutoryInvoiceChannel::RdServiceNet,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'ORD-DATE-TEST',
            'source_order_id' => 'WEB-12345',
            'idempotency_key' => 'statutory:rd_service_net:commerce_order:ORD-DATE-TEST',
            'payload_hash' => hash('sha256', 'ORD-DATE-TEST'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'payment_method' => 'UPI',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'billing_state' => 'Delhi',
            'billing_address' => '1 Test Street',
            'shipping_address' => '1 Test Street',
            'branch_code' => 'DELHI',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'order_value' => 118.00,
            'ordered_at' => '2026-09-05 08:00:00',
            'received_at' => now(),
        ]);

        $row = $this->firstRow();

        $this->assertSame('2026-09-15', $row[1]);
        $this->assertSame('WEB-12345', $row[3]);
    }

    public function test_discount_reduces_taxable_amount_and_amount_column(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'taxable_value' => '90.00',
            'tax_total' => '16.20',
            'cgst' => '8.10',
            'sgst' => '8.10',
            'invoice_value' => '106.20',
        ], [
            'unit_price' => '100.00',
            'discount' => '10.00',
            'taxable_value' => '90.00',
            'tax_total' => '16.20',
            'cgst' => '8.10',
            'sgst' => '8.10',
            'line_total' => '106.20',
        ]);

        $row = $this->firstRow();
        $preflight = app(CaMonthlyStatutoryLineReadModel::class)->preflight($this->request());

        $this->assertSame('90.00', $row[11]);
        $this->assertSame('106.20', $row[17]);
        $this->assertSame(1, $preflight->discountLineCount);
    }

    public function test_amount_represents_taxable_amount_not_payment_amount(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'payment_method' => 'UPI',
            'invoice_value' => '118.00',
        ]);

        $row = $this->firstRow();

        $this->assertSame('100.00', $row[11]);
        $this->assertSame('118.00', $row[17]);
    }

    public function test_shipping_remains_blank_without_authoritative_source(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $row = $this->firstRow();

        $this->assertSame('', $row[12]);
    }

    public function test_invoice_level_shipping_amount_is_reported_on_first_line_only(): void
    {
        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'shipping_amount' => '25.00',
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 2,
            'sku' => 'ADDON',
            'description' => 'Add-on Service',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => '50.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '50.00',
            'tax_total' => '9.00',
            'cgst' => '4.50',
            'sgst' => '4.50',
            'igst' => '0.00',
            'line_total' => '59.00',
        ]);

        $rows = app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->request());

        $this->assertSame('25.00', $rows[0][12]);
    }

    public function test_gst_columns_and_total_amount_are_populated(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $row = $this->firstRow();
        $preflight = app(CaMonthlyStatutoryLineReadModel::class)->preflight($this->request());

        $this->assertSame('9.00', $row[14]);
        $this->assertSame('9.00', $row[15]);
        $this->assertSame('', $row[13]);
        $this->assertSame('118.00', $row[17]);
        $this->assertSame('100.00', $preflight->taxableAmountTotal);
        $this->assertSame('118.00', $preflight->totalAmountTotal);
    }

    public function test_short_excess_rounding_appears_on_last_line_only(): void
    {
        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'rounding' => '0.35',
            'invoice_value' => '118.35',
        ], [
            'line_total' => '118.35',
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 2,
            'sku' => 'LINE-2',
            'description' => 'Second Line',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => '50.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '50.00',
            'tax_total' => '9.00',
            'cgst' => '4.50',
            'sgst' => '4.50',
            'igst' => '0.00',
            'line_total' => '59.00',
        ]);

        $rows = app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->request());
        $preflight = app(CaMonthlyStatutoryLineReadModel::class)->preflight($this->request());

        $this->assertSame('0.35', $rows[0][16]);
        $this->assertSame('0.35', $preflight->shortExcessTotal);
    }

    public function test_acknowledgement_outputs_number_only(): void
    {
        $invoice = $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'irn' => 'irn-token-0000000000000000000000000000000001',
            'ack_no' => 'ACK123456789',
            'ack_date' => '2026-09-10 12:00:00',
            'status' => 'submitted',
        ]);

        $row = $this->firstRow();

        $this->assertSame('ACK123456789', $row[19]);
        $this->assertStringNotContainsString('2026', $row[19]);
    }

    public function test_eway_bill_remains_blank_without_authoritative_source(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $row = $this->firstRow();
        $preflight = app(CaMonthlyStatutoryLineReadModel::class)->preflight($this->request());

        $this->assertSame('', $row[9]);
        $this->assertGreaterThan(0, $preflight->unresolvedEwayBillLineCount);
    }

    public function test_cancelled_unpaid_invoice_is_included(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_at' => '2026-09-11 10:00:00',
            'cancel_reason' => 'Unexecuted cancellation',
            'invoice_value' => '0.00',
            'payment_method' => null,
            'payment_reference' => null,
        ]);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $preflight = $readModel->preflight($this->request());
        $row = $readModel->exportRows($this->request())[0];

        $this->assertCount(1, $readModel->exportRows($this->request()));
        $this->assertSame(1, $preflight->cancelledIncludedCount);
        $this->assertSame(0, $preflight->cancelledExcludedCount);
        $this->assertSame('0.00', $row[17]);
    }

    public function test_cancelled_commerce_invoice_with_payment_snapshot_is_included(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_at' => '2026-09-11 10:00:00',
            'cancel_reason' => 'Paid then cancelled',
            'payment_method' => 'UPI',
            'payment_reference' => 'CF-PAY-123',
            'invoice_value' => '118.00',
        ]);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $preflight = $readModel->preflight($this->request());
        $row = $readModel->exportRows($this->request())[0];

        $this->assertSame(1, $preflight->cancelledIncludedCount);
        $this->assertSame(1, $preflight->cancelledIncludedViaPaymentReferenceCount);
        $this->assertSame('UPI', $row[20]);
    }

    public function test_cancelled_pos_invoice_with_payment_snapshot_is_included(): void
    {
        $this->makeHardwareTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_at' => '2026-09-11 10:00:00',
            'cancel_reason' => 'POS paid then cancelled',
            'payment_method' => 'Cash',
            'payment_reference' => 'POS-REF-001',
            'invoice_value' => '118.00',
        ]);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $preflight = $readModel->preflight($this->request());

        $this->assertCount(1, $readModel->exportRows($this->request()));
        $this->assertSame(1, $preflight->cancelledIncludedViaPaymentReferenceCount);
    }

    public function test_cancelled_service_pos_invoice_with_payment_allocation_is_included(): void
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Service Buyer',
            'phone' => '9999901234',
        ]);

        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'channel' => StatutoryInvoiceChannel::DeskService,
            'source_type' => StatutoryInvoiceSourceType::ServiceOrder,
            'source_id' => 'SVC-1001',
            'payment_method' => null,
            'payment_reference' => null,
            'invoice_value' => '118.00',
        ]);

        $payment = CustomerPayment::query()->create([
            'payment_number' => 'CP-000001',
            'customer_id' => $customer->id,
            'amount' => '118.00',
            'method' => 'Bank Transfer',
            'payment_date' => '2026-09-10',
        ]);

        PaymentAllocation::query()->create([
            'customer_payment_id' => $payment->id,
            'statutory_invoice_id' => $invoice->id,
            'amount' => '118.00',
            'allocated_at' => '2026-09-10 10:00:00',
        ]);

        $invoice->update([
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_at' => '2026-09-11 10:00:00',
            'cancel_reason' => 'Paid via allocation then cancelled',
        ]);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $preflight = $readModel->preflight($this->request());
        $row = $readModel->exportRows($this->request())[0];

        $this->assertCount(1, $readModel->exportRows($this->request()));
        $this->assertSame(1, $preflight->cancelledIncludedCount);
        $this->assertSame(1, $preflight->cancelledIncludedViaPaymentAllocationCount);
        $this->assertSame('Bank Transfer', $row[20]);
    }

    public function test_cancelled_service_pos_invoice_without_allocation_is_still_included(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'channel' => StatutoryInvoiceChannel::DeskService,
            'source_type' => StatutoryInvoiceSourceType::ServiceOrder,
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_at' => '2026-09-11 10:00:00',
            'cancel_reason' => 'Unpaid service cancellation',
            'payment_method' => null,
            'payment_reference' => null,
            'invoice_value' => '0.00',
        ]);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $preflight = $readModel->preflight($this->request());

        $this->assertCount(1, $readModel->exportRows($this->request()));
        $this->assertSame(1, $preflight->cancelledIncludedCount);
        $this->assertSame(0, $preflight->cancelledExcludedCount);
    }

    public function test_cancelled_invoice_with_invoice_value_only_is_included_and_flagged_in_preflight(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_at' => '2026-09-11 10:00:00',
            'cancel_reason' => 'Cancelled without payment evidence',
            'payment_method' => null,
            'payment_reference' => null,
            'invoice_value' => '118.00',
        ]);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $preflight = $readModel->preflight($this->request());

        $this->assertCount(1, $readModel->exportRows($this->request()));
        $this->assertSame(1, $preflight->cancelledAmbiguousInvoiceValueOnlyCount);
        $this->assertSame(0, $preflight->cancelledExcludedCount);
        $this->assertTrue(
            collect($preflight->warnings())->contains(
                fn (string $warning): bool => str_contains($warning, 'no authoritative payment evidence')
            )
        );
    }

    public function test_credit_note_is_included_with_document_type_label(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'document_type' => StatutoryInvoiceDocumentType::CreditNote,
            'invoice_number' => 'CN-2026-0001',
        ]);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $preflight = $readModel->preflight($this->request());
        $row = $readModel->exportRows($this->request())[0];

        $this->assertSame(1, $preflight->creditNoteCount);
        $this->assertSame('CN-2026-0001', $row[2]);
    }

    public function test_commerce_physical_merchandise_line_kind_classifies_hardware(): void
    {
        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RB-HW-001',
        ], [
            'sku' => 'RBP228',
            'description' => 'Hardware Product',
            'hsn_sac' => '84716050',
        ]);

        $order = CommerceOrder::query()->create([
            'order_no' => 'CO-RB-HW-001',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RB-HW-001',
            'source_order_id' => 'RB-HW-001',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RB-HW-001',
            'payload_hash' => hash('sha256', 'RB-HW-001'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'billing_state' => 'Delhi',
            'billing_address' => '1 Test Street',
            'shipping_address' => '1 Test Street',
            'branch_code' => 'DELHI',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'order_value' => 118.00,
            'ordered_at' => '2026-09-05 08:00:00',
            'received_at' => now(),
        ]);

        CommerceOrderItem::query()->create([
            'commerce_order_id' => $order->id,
            'sku' => 'RBP228',
            'description' => 'Hardware Product',
            'hsn_sac' => '84716050',
            'qty' => 1,
            'unit_price' => '100.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '100.00',
            'tax_total' => '18.00',
            'line_total' => '118.00',
            'shipping_line_kind' => 'physical_merchandise',
            'requires_shipping' => true,
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportOrderType::HARDWARE, $row[4]);
    }

    public function test_non_reconciling_lines_are_reported_in_preflight(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'invoice_value' => '120.00',
        ]);

        $preflight = app(CaMonthlyStatutoryLineReadModel::class)->preflight($this->request());

        $this->assertSame(1, $preflight->nonReconcilingLineCount);
        $this->assertTrue(
            collect($preflight->warnings())->contains(
                fn (string $warning): bool => str_contains($warning, 'do not reconcile')
            )
        );
    }

    public function test_preflight_processes_large_ranges_in_invoice_chunks_without_loading_every_line_at_once(): void
    {
        config(['ca_monthly_report.invoice_chunk_size' => 5]);

        for ($i = 0; $i < 60; $i++) {
            $day = str_pad((string) (($i % 20) + 1), 2, '0', STR_PAD_LEFT);
            $this->makeTaxInvoice(['issued_at' => "2026-09-{$day} 10:00:00"]);
        }

        $preflight = app(CaMonthlyStatutoryLineReadModel::class)->preflight($this->request());

        $this->assertSame(60, $preflight->lineCount);
        $this->assertSame(60, $preflight->invoiceCount);
    }

    public function test_index_defaults_missing_dates_to_current_month_and_renders_successfully(): void
    {
        $this->makeTaxInvoice(['issued_at' => now()->toDateString().' 10:00:00']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $response = $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.index'));

        $response->assertOk();
        $response->assertSee('CA Monthly Report');
        $response->assertSee(now()->startOfMonth()->toDateString(), false);
        $response->assertSee(now()->toDateString(), false);
    }

    public function test_page_presents_reporting_period_and_date_basis_prominently(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.index', self::RANGE))
            ->assertOk()
            ->assertSee('1 Sep 2026 – 21 Sep 2026', false)
            ->assertSee('Date of Invoice', false)
            ->assertSee('statutory_invoices.issued_at', false)
            ->assertSee('Reporting period', false);
    }

    public function test_preflight_summary_and_full_metrics_sections_render(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.index', self::RANGE))
            ->assertOk()
            ->assertSee('Preflight summary', false)
            ->assertSee('Full preflight metrics', false)
            ->assertSee('Statutory invoices:', false)
            ->assertSee('Lines not reconciling:', false)
            ->assertSee('Informational', false);
    }

    public function test_export_report_controls_include_format_email_and_user_facing_labels(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.index', self::RANGE))
            ->assertOk()
            ->assertSee('Export report', false)
            ->assertSee('Export Report', false)
            ->assertSee('Excel (.xlsx)', false)
            ->assertSee('CSV (.csv)', false)
            ->assertSee('Email report (optional)', false)
            ->assertSee('Download now', false)
            ->assertDontSee('Queue Export / Email', false);
    }

    public function test_recent_exports_section_renders_for_user_exports(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $user = User::factory()->create(['is_active' => true, 'email' => 'finance-ui@example.com']);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        config(['ca_monthly_report.sync_max_lines' => 2]);
        $this->actingAs($user)
            ->post(route('finance.reports.ca-monthly.exports.store'), array_merge(self::RANGE, [
                'format' => 'csv',
            ]))
            ->assertRedirect();

        $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.index', self::RANGE))
            ->assertOk()
            ->assertSee('Recent exports', false)
            ->assertSee('2026-09-01 to 2026-09-21', false)
            ->assertSee('CSV', false);
    }

    public function test_invoice_preview_section_renders_with_table_headers(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.index', self::RANGE))
            ->assertOk()
            ->assertSee('Invoice preview', false)
            ->assertSee('Invoice No.', false)
            ->assertSee('Date of Invoice', false);
    }

    public function test_agent_cannot_access_ca_monthly_report(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.index', self::RANGE))
            ->assertForbidden();
    }

    public function test_xlsx_writer_places_headers_on_row_three(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $path = storage_path('app/tmp/ca-monthly-writer-test.xlsx');
        app(CaMonthlyReportExportGenerator::class)->generateToPath(
            $this->request(),
            \App\Enums\CaMonthlyReportExportFormat::Xlsx,
            $path,
        );

        $this->assertSame(CaMonthlyReportDefinition::HEADERS, $this->readXlsxRow($path, 3));
        $this->assertSame('118.00', $this->readXlsxRow($path, 4)[17]);

        @unlink($path);
    }

    private function request(): Request
    {
        return Request::create('/', 'GET', self::RANGE);
    }

    /**
     * @return list<string>
     */
    private function firstRow(): array
    {
        return app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->request())[0];
    }

    /**
     * @return list<string>
     */
    private function readXlsxRow(string $path, int $rowNumber): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertNotFalse($xml);

        $sheet = simplexml_load_string($xml);
        $this->assertNotFalse($sheet);

        $ns = $sheet->getNamespaces(true);
        $main = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet->registerXPathNamespace('m', $main);

        $cells = $sheet->xpath('//m:sheetData/m:row[@r="'.$rowNumber.'"]/m:c');
        $this->assertIsArray($cells);

        $values = [];
        foreach ($cells as $cell) {
            $attributes = $cell->attributes();
            $ref = (string) $attributes['r'];
            preg_match('/([A-Z]+)/', $ref, $matches);
            $col = $matches[1];
            $colIndex = 0;
            foreach (str_split($col) as $char) {
                $colIndex = $colIndex * 26 + (ord($char) - 64);
            }

            $type = (string) ($attributes['t'] ?? '');
            if ($type === 'inlineStr') {
                $text = (string) $cell->is->t;
            } else {
                $text = (string) $cell->v;
            }

            $values[$colIndex - 1] = $text;
        }

        if ($values === []) {
            return [];
        }

        $max = max(array_keys($values));
        $row = [];
        for ($i = 0; $i <= $max; $i++) {
            $row[] = $values[$i] ?? '';
        }

        return $row;
    }
}
