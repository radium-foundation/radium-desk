<?php

namespace Tests\Feature\Finance;

use App\Enums\CaMonthlyReportExportFormat;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Models\CaMonthlyReportExport;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Reports\CaMonthly\CaMonthlyReportInvoiceExportBuilder;
use App\Services\Finance\CaMonthlyReportExportGenerator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;
use ZipArchive;

class CaMonthlyReportSalesReportTest extends TestCase
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
        Storage::fake('local');
        config(['ca_monthly_report.sync_max_lines' => 500]);
    }

    public function test_definition_uses_sales_report_display_name(): void
    {
        $this->assertSame('Sales Report', CaMonthlyReportDefinition::DISPLAY_NAME);
        $this->assertSame('Sales Report', CaMonthlyReportDefinition::SHEET_NAME);
        $this->assertSame('CA Monthly Report', CaMonthlyReportDefinition::LEGACY_DISPLAY_NAME);
    }

    public function test_detail_headers_include_product_name_and_sku(): void
    {
        $this->assertSame(
            ['Product Name', 'Product Code / SKU', 'Quantity', 'Unit Price', 'Discount', 'HSN/SAC', 'GST Rate', 'Taxable Amount', 'Shipping', 'IGST', 'CGST', 'SGST', 'Line Total'],
            CaMonthlyReportDefinition::DETAIL_HEADERS,
        );
    }

    public function test_line_detail_includes_product_name_and_sku_from_statutory_items(): void
    {
        $invoice = $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $rows = app(CaMonthlyReportInvoiceExportBuilder::class)->buildForInvoices(collect([$invoice]));

        $this->assertCount(1, $rows[0]->detailRows);
        $this->assertSame('RD Service', $rows[0]->detailRows[0][0]);
        $this->assertSame('RD-SVC', $rows[0]->detailRows[0][1]);
    }

    public function test_null_sku_renders_as_empty_string_in_line_detail(): void
    {
        $invoice = $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);
        $invoice->items()->update(['sku' => null]);

        $rows = app(CaMonthlyReportInvoiceExportBuilder::class)->buildForInvoices(collect([$invoice->fresh('items')]));

        $this->assertSame('', $rows[0]->detailRows[0][1]);
    }

    public function test_multi_product_invoice_produces_line_detail_per_product_without_duplicating_parent_totals(): void
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
        $exportRows = $readModel->exportRows($this->request());
        $groups = $readModel->paginateInvoiceGroups($this->request(), 50)->items();

        $this->assertCount(1, $exportRows);
        $this->assertSame('118.00', $exportRows[0][18]);
        $this->assertTrue($groups[0]->expandable);
        $this->assertCount(2, $groups[0]->children);
        $this->assertSame('RD Service', $groups[0]->children[0]->productName);
        $this->assertSame('RD-SVC', $groups[0]->children[0]->productCodeSku);
        $this->assertSame('Add-on Service', $groups[0]->children[1]->productName);
        $this->assertSame('ADDON', $groups[0]->children[1]->productCodeSku);
    }

    public function test_xlsx_child_row_includes_sku_in_expandable_detail_label(): void
    {
        $invoice = $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

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

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $response = $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.export.xlsx', self::RANGE));

        $response->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();
        $xml = $this->readWorksheetXml($path);

        $this->assertStringContainsString('RD Service [RD-SVC]', $xml);
        $this->assertStringContainsString('Add-on Service [ADDON]', $xml);
        $this->assertStringContainsString('Sales Report', $xml);
    }

    public function test_csv_export_preserves_invoice_grain_with_parent_headers_only(): void
    {
        $invoice = $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

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

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $response = $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.export.csv', self::RANGE));

        $response->assertOk();

        $export = CaMonthlyReportExport::query()->where('user_id', $user->id)->firstOrFail();
        $artifact = Storage::disk('local')->get((string) $export->storage_path);

        $this->assertStringContainsString('Invoice Total', $artifact);
        $this->assertStringContainsString('118.00', $artifact);
        $this->assertSame(1, substr_count($artifact, 'INV-EINV-1'));
        $this->assertStringNotContainsString('Add-on Service', $artifact);
    }

    public function test_credit_note_row_shows_credit_note_status_without_inventing_refund_amount(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'document_type' => StatutoryInvoiceDocumentType::CreditNote,
            'invoice_number' => 'CN-0001',
        ]);

        $row = app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->request())[0];

        $this->assertSame('Credit Note', $row[3]);
        $this->assertSame('CN-0001', $row[2]);
        $this->assertCount(27, $row);
    }

    public function test_sync_xlsx_generator_uses_sales_report_title(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $path = tempnam(sys_get_temp_dir(), 'sales-report-');
        app(CaMonthlyReportExportGenerator::class)->generateToPath(
            $this->request(),
            CaMonthlyReportExportFormat::Xlsx,
            $path,
        );

        $this->assertStringContainsString('Sales Report', $this->readWorksheetXml($path));
        @unlink($path);
    }

    private function request(): Request
    {
        return Request::create('/finance/reports/ca-monthly', 'GET', self::RANGE);
    }

    private function readWorksheetXml(string $path): string
    {
        $zip = new ZipArchive;
        $zip->open($path);
        $xml = $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        return $xml !== false ? $xml : '';
    }
}
