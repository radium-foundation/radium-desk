<?php

namespace Tests\Feature\Finance;

use App\Enums\CaMonthlyReportExportFormat;
use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilmentPaymentEvidence;
use App\Models\InventoryBranch;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceItem;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Reports\CaMonthly\CaMonthlyReportOrderType;
use App\Reports\CaMonthly\CaMonthlyReportPaymentChannelResolver;
use App\Reports\CaMonthly\CaMonthlyReportPaymentEvidenceResolver;
use App\Services\Finance\CaMonthlyReportExportGenerator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;
use ZipArchive;

class CaMonthlyReportInvoiceRegisterTest extends TestCase
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

    public function test_export_has_invoice_level_columns_without_status_or_document_type(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $headers = CaMonthlyReportDefinition::HEADERS;

        $this->assertCount(22, $headers);
        $this->assertContains('Status', $headers);
        $this->assertNotContains('Document Type', $headers);
        $this->assertContains('Invoice Total', $headers);
        $this->assertContains('Payment Channel', $headers);
        $this->assertNotContains('Payment Method', $headers);
        $this->assertNotContains('Payment Reference', $headers);
    }

    public function test_single_product_invoice_exports_one_parent_row(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $rows = app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->request());

        $this->assertCount(1, $rows);
        $this->assertCount(22, $rows[0]);
        $this->assertSame(CaMonthlyReportOrderType::SERVICE, $rows[0][5]);
    }

    public function test_multi_product_invoice_exports_one_parent_row_with_expandable_detail(): void
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

        $groups = $readModel->paginateInvoiceGroups($this->request(), 50)->items();
        $this->assertCount(1, $groups);
        $this->assertTrue($groups[0]->expandable);
        $this->assertCount(2, $groups[0]->children);
    }

    public function test_zero_value_included_support_line_is_excluded_from_detail(): void
    {
        $invoice = $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 2,
            'sku' => 'SUPPORT',
            'description' => 'RD Technical Support — included',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => '0.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '0.00',
            'tax_total' => '0.00',
            'cgst' => '0.00',
            'sgst' => '0.00',
            'igst' => '0.00',
            'line_total' => '0.00',
        ]);

        $groups = app(CaMonthlyStatutoryLineReadModel::class)
            ->paginateInvoiceGroups($this->request(), 50)
            ->items();

        $this->assertCount(1, $groups);
        $this->assertFalse($groups[0]->expandable);
        $this->assertCount(1, $groups[0]->children);
        $this->assertStringNotContainsString('RD Technical Support', implode(',', $groups[0]->parentRow));
    }

    public function test_inv_674_style_taxable_and_invoice_total_remain_distinct_on_parent_row(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'invoice_number' => 'INV-674',
            'taxable_value' => '669.47',
            'tax_total' => '120.51',
            'cgst' => '60.25',
            'sgst' => '60.26',
            'invoice_value' => '789.98',
        ], [
            'taxable_value' => '669.47',
            'tax_total' => '120.51',
            'cgst' => '60.25',
            'sgst' => '60.26',
            'line_total' => '789.98',
        ]);

        $row = $this->firstRow();

        $this->assertSame('669.47', $row[12]);
        $this->assertSame('789.98', $row[18]);
        $this->assertNotSame($row[12], $row[18]);
    }

    public function test_rb297_payment_mode_prefers_support_order_transaction_over_invoice_snapshot(): void
    {
        $supportOrder = Order::query()->create([
            'order_id' => 'RB297',
            'serial_number' => 'SN-RB297',
            'product_name' => 'Radium Device',
            'device_model' => 'Model X',
            'customer_name' => 'RB297 Buyer',
            'payment_method' => 'card',
            'status' => 'active',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RB297',
            'source_order_id' => 'RB297',
            'support_order_id' => $supportOrder->id,
            'payment_method' => 'UPI',
        ]);

        $invoice = StatutoryInvoice::query()->where('source_id', 'RB297')->firstOrFail();
        $evidenceResolver = app(CaMonthlyReportPaymentEvidenceResolver::class);
        $supportMethods = $evidenceResolver->supportOrderPaymentMethodsForInvoices(collect([$invoice]));

        $this->assertSame(
            'Card',
            $evidenceResolver->resolvePaymentModeDisplay($invoice, null, null, null, $supportMethods[$invoice->id] ?? null),
        );
        $this->assertCount(22, $this->firstRow());
    }

    public function test_branch_resolves_from_commerce_order_branch_code_when_invoice_branch_is_missing(): void
    {
        InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);

        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'branch_id' => null,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RB-BRANCH-1',
        ]);

        CommerceOrder::query()->create([
            'order_no' => 'CO-RB-BRANCH-1',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RB-BRANCH-1',
            'source_order_id' => 'RB-BRANCH-1',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RB-BRANCH-1',
            'payload_hash' => hash('sha256', 'RB-BRANCH-1'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'billing_state' => 'Delhi',
            'billing_address' => '1 Test Street',
            'shipping_address' => '1 Test Street',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'order_value' => 118.00,
            'ordered_at' => '2026-09-05 08:00:00',
            'received_at' => now(),
            'statutory_invoice_id' => $invoice->id,
        ]);

        $row = $this->firstRow();

        $this->assertSame('Delhi', $row[0]);
    }

    public function test_hardware_payment_evidence_overrides_cashfree_provider_snapshot(): void
    {
        $invoice = $this->makeHardwareTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'source_order_id' => 'RDE123456',
            'payment_method' => 'cashfree',
        ]);

        HardwareFulfilmentPaymentEvidence::query()->create([
            'source_id' => 'RDE123456',
            'cashfree_payment_id' => 'cf_pay_123',
            'payment_status' => 'SUCCESS',
            'verified' => true,
            'payment_method' => 'netbanking',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CF, $row[21]);
    }

    public function test_xlsx_workbook_has_grouped_child_rows_and_summary(): void
    {
        $invoice = $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 2,
            'sku' => 'ADDON',
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

        $path = storage_path('app/tmp/ca-monthly-register-test.xlsx');
        app(CaMonthlyReportExportGenerator::class)->generateToPath(
            $this->request(),
            CaMonthlyReportExportFormat::Xlsx,
            $path,
        );

        $xml = $this->sheetXml($path);
        $this->assertStringContainsString('outlineLevel="1"', $xml);
        $this->assertStringContainsString('hidden="1"', $xml);
        $this->assertStringContainsString('CA Monthly Report', $xml);
        $this->assertSame(CaMonthlyReportDefinition::HEADERS, $this->readXlsxRow($path, 3));
        $this->assertSame('118.00', $this->readXlsxRow($path, 4)[18]);

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

    private function sheetXml(string $path): string
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        return $xml !== false ? $xml : '';
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

        $sheet = simplexml_load_string((string) $xml);
        $ns = $sheet->getNamespaces(true);
        $main = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet->registerXPathNamespace('m', $main);

        $cells = $sheet->xpath('//m:sheetData/m:row[@r="'.$rowNumber.'"]/m:c');
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
            $text = $type === 'inlineStr' ? (string) $cell->is->t : (string) $cell->v;
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
