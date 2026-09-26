<?php

namespace Tests\Feature\Finance;

use App\Enums\CaMonthlyReportExportFormat;
use App\Enums\InventorySaleStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InventoryBranch;
use App\Models\InventorySale;
use App\Models\Order;
use App\Models\StatutoryInvoiceItem;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Reports\CaMonthly\CaMonthlyReportPaymentChannelResolver;
use App\Services\Finance\CaMonthlyReportExportGenerator;
use App\Support\Finance\CaMonthlyReportXlsxPackageValidator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;
use ZipArchive;

class CaMonthlyReportXlsxCompatibilityTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private const RANGE = [
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-26',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_generated_xlsx_passes_excel_package_validation(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $path = $this->generateWorkbook();

        $errors = (new CaMonthlyReportXlsxPackageValidator)->validate($path);
        $this->assertSame([], $errors, implode(' ', $errors));

        @unlink($path);
    }

    public function test_workbook_includes_styles_relationships_dimension_and_valid_worksheet_xml(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $path = $this->generateWorkbook();
        $zip = new ZipArchive;
        $zip->open($path);

        $contentTypes = (string) $zip->getFromName('[Content_Types].xml');
        $workbookRels = (string) $zip->getFromName('xl/_rels/workbook.xml.rels');
        $workbook = (string) $zip->getFromName('xl/workbook.xml');
        $styles = (string) $zip->getFromName('xl/styles.xml');
        $sheet = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $this->assertStringContainsString('/xl/styles.xml', $contentTypes);
        $this->assertStringContainsString('relationships/styles', $workbookRels);
        $this->assertStringContainsString('<bookViews>', $workbook);
        $this->assertStringContainsString('<styleSheet', $styles);
        $this->assertMatchesRegularExpression('/<dimension ref="A1:V\d+"\/>/', $sheet);
        $this->assertStringNotContainsString('fitToHeight="0"', $sheet);
        $this->assertStringContainsString('<autoFilter ref="A3:V3"/>', $sheet);

        @unlink($path);
    }

    public function test_xlsx_preserves_twenty_two_column_header_integrity(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $path = $this->generateWorkbook();

        $this->assertSame(CaMonthlyReportDefinition::HEADERS, $this->readXlsxRow($path, 3));
        $this->assertCount(22, CaMonthlyReportDefinition::HEADERS);
        $this->assertNotContains('Payment Method', CaMonthlyReportDefinition::HEADERS);
        $this->assertNotContains('Payment Reference', CaMonthlyReportDefinition::HEADERS);

        @unlink($path);
    }

    public function test_xlsx_handles_special_characters_gstin_and_place_of_supply(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'buyer_name' => 'M/s. A&B <Test> "Quotes" & Ampersand',
            'buyer_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
            'billing_address_structured' => [
                'state' => 'Delhi',
                'city' => 'Delhi',
                'pincode' => '110001',
            ],
        ]);

        $path = $this->generateWorkbook();
        $row = $this->readXlsxRow($path, 4);

        $this->assertSame('07AAICP1128M1Z9', $row[7]);
        $this->assertSame('Delhi', $row[8]);
        $this->assertSame('Delhi', $row[9]);
        $this->assertStringContainsString('Ampersand', $row[6]);

        $errors = (new CaMonthlyReportXlsxPackageValidator)->validate($path);
        $this->assertSame([], $errors);

        @unlink($path);
    }

    public function test_cancelled_invoice_row_and_summary_exclusion_remain_correct_in_xlsx(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'invoice_number' => 'INV-ACTIVE-599',
            'taxable_value' => '507.64',
            'invoice_value' => '599.01',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-11 10:00:00',
            'invoice_number' => 'INV-CANCELLED-118',
            'status' => StatutoryInvoiceStatus::Cancelled,
            'taxable_value' => '100.00',
            'invoice_value' => '118.00',
        ]);

        $path = $this->generateWorkbook();
        $active = $this->readXlsxRow($path, 4);
        $cancelled = $this->readXlsxRow($path, 5);

        $this->assertSame('Issued', $active[3]);
        $this->assertSame('599.01', $active[18]);
        $this->assertSame('Cancelled', $cancelled[3]);
        $this->assertSame('118.00', $cancelled[18]);

        $sheet = $this->sheetXml($path);
        $this->assertStringContainsString('<v>507.64</v>', $sheet);
        $this->assertStringNotContainsString('<v>618.00</v>', $sheet);

        @unlink($path);
    }

    public function test_payment_channel_values_and_empty_channel_for_unclassified_evidence(): void
    {
        $cfOrder = Order::query()->create([
            'order_id' => 'RD-CF-599',
            'serial_number' => 'SN-CF-599',
            'product_name' => 'Service',
            'device_model' => 'Model',
            'customer_name' => 'Buyer',
            'payment_method' => 'UPI',
            'payment_amount' => 599.00,
            'cashfree_payment_id' => 'cf_pay_599',
            'status' => 'active',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'support_order_id' => $cfOrder->id,
            'invoice_number' => 'INV-CF-599',
            'payment_method' => 'cashfree',
            'taxable_value' => '507.64',
            'tax_total' => '91.37',
            'igst' => '91.37',
            'invoice_value' => '599.01',
        ]);

        $unclassifiedOrder = Order::query()->create([
            'order_id' => 'RD-UPI-ONLY',
            'serial_number' => 'SN-UPI',
            'product_name' => 'Service',
            'device_model' => 'Model',
            'customer_name' => 'Buyer',
            'payment_method' => 'UPI',
            'status' => 'active',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-11 10:00:00',
            'support_order_id' => $unclassifiedOrder->id,
            'invoice_number' => 'INV-UNCLASSIFIED',
            'payment_method' => 'UPI',
            'payment_reference' => 'MANUAL-UPI',
        ]);

        $path = $this->generateWorkbook();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CF, $this->readXlsxRow($path, 4)[21]);
        $this->assertSame('599.01', $this->readXlsxRow($path, 4)[18]);
        $this->assertSame('', $this->readXlsxRow($path, 5)[21]);

        @unlink($path);
    }

    public function test_pos_6757_style_cancelled_uat_row_remains_visible_without_refund_side_effects(): void
    {
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);

        $sale = InventorySale::query()->create([
            'sale_no' => 'POS-6757',
            'invoice_number' => 'INV-0767325',
            'branch_id' => $branch->id,
            'status' => InventorySaleStatus::Cancelled,
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 18,
            'total' => 118,
            'payment_method' => 'Cash',
            'payment_reference' => 'CASH-UAT',
            'finance_handoff_status' => 'posted',
            'completed_at' => '2026-09-24 10:00:00',
            'cancelled_at' => '2026-09-24 11:00:00',
        ]);

        $this->makeHardwareTaxInvoice([
            'issued_at' => '2026-09-24 10:00:00',
            'invoice_number' => 'INV-0767325',
            'branch_id' => $branch->id,
            'inventory_sale_id' => $sale->id,
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_at' => '2026-09-24 11:00:00',
            'taxable_value' => '100.00',
            'invoice_value' => '118.00',
            'payment_method' => 'Cash',
        ]);

        $path = $this->generateWorkbook();
        $row = $this->readXlsxRow($path, 4);

        $this->assertSame('Delhi', $row[0]);
        $this->assertSame('Cancelled', $row[3]);
        $this->assertSame('POS-6757', $row[4]);
        $this->assertSame('118.00', $row[18]);
        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CASH, $row[21]);

        @unlink($path);
    }

    public function test_cancelled_pos_sale_without_statutory_invoice_is_absent_from_register(): void
    {
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);

        InventorySale::query()->create([
            'sale_no' => 'POS-000001',
            'branch_id' => $branch->id,
            'status' => InventorySaleStatus::Cancelled,
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 18,
            'total' => 118,
            'payment_method' => 'Cash',
            'finance_handoff_status' => 'skipped',
            'cancelled_at' => '2026-09-10 11:00:00',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'invoice_number' => 'INV-ACTIVE-ONLY',
        ]);

        $path = $this->generateWorkbook();

        $this->assertSame('INV-ACTIVE-ONLY', $this->readXlsxRow($path, 4)[2]);
        $this->assertStringNotContainsString('POS-000001', $this->sheetXml($path));

        @unlink($path);
    }

    public function test_multi_line_invoice_child_rows_remain_grouped_in_valid_workbook(): void
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

        $path = $this->generateWorkbook();
        $sheet = $this->sheetXml($path);

        $this->assertStringContainsString('outlineLevel="1"', $sheet);
        $this->assertStringContainsString('hidden="1"', $sheet);
        $this->assertStringContainsString('  » AMC Support', $sheet);

        $errors = (new CaMonthlyReportXlsxPackageValidator)->validate($path);
        $this->assertSame([], $errors);

        @unlink($path);
    }

    private function generateWorkbook(): string
    {
        $path = storage_path('app/tmp/ca-monthly-xlsx-compat-'.uniqid('', true).'.xlsx');
        app(CaMonthlyReportExportGenerator::class)->generateToPath(
            $this->request(),
            CaMonthlyReportExportFormat::Xlsx,
            $path,
        );

        return $path;
    }

    private function request(): Request
    {
        return Request::create('/', 'GET', self::RANGE);
    }

    private function sheetXml(string $path): string
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        return $xml;
    }

    /**
     * @return list<string>
     */
    private function readXlsxRow(string $path, int $rowNumber): array
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $sheet = simplexml_load_string($xml);
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
            $values[$colIndex - 1] = $type === 'inlineStr' ? (string) $cell->is->t : (string) $cell->v;
        }

        if ($values === []) {
            return [];
        }

        $max = max(array_keys($values));
        $targetMax = max($max, count(CaMonthlyReportDefinition::HEADERS) - 1);
        $row = [];
        for ($i = 0; $i <= $targetMax; $i++) {
            $row[] = $values[$i] ?? '';
        }

        return $row;
    }
}
