<?php

namespace Tests\Feature\Finance;

use App\Enums\CaMonthlyReportExportFormat;
use App\Enums\CommerceOrderStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Mail\CaMonthlyReportExportMail;
use App\Models\CaMonthlyReportExport;
use App\Models\CommerceOrder;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use App\Reports\CaMonthly\CaMonthlyReportInvoiceExportBuilder;
use App\Services\Finance\CaMonthlyReportExportGenerator;
use App\Services\Finance\CaMonthlyReportExportService;
use App\Support\Finance\CaMonthlyReportXlsxPackageValidator;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Mail;
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
        $this->assertSame('118.00', $exportRows[0][19]);
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
        $this->assertStringContainsString('RD Service; Add-on Service', $artifact);
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
        $this->assertCount(28, $row);
        $this->assertSame('RD Service', $row[27]);
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

        $sheet1 = $this->readWorksheetXml($path);
        $this->assertStringContainsString('Sales Report', $sheet1);
        $this->assertLessThan(strpos($sheet1, '<sheetViews>'), strpos($sheet1, '<dimension '));
        $this->assertLessThan(strpos($sheet1, '<pageSetUpPr'), strpos($sheet1, '<outlinePr'));
        $this->assertDoesNotMatchRegularExpression('/<pageSetup[^>]*fitToPage=/', $sheet1);

        $zip = new ZipArchive;
        $zip->open($path);
        $sheet2 = $zip->getFromName('xl/worksheets/sheet2.xml');
        $workbook = $zip->getFromName('xl/workbook.xml');
        $zip->close();
        $this->assertIsString($sheet2);
        $this->assertLessThan(strpos($sheet2, '<sheetViews>'), strpos($sheet2, '<dimension '));
        $this->assertStringContainsString('Refund &amp; CN Review', (string) $workbook);
        @unlink($path);
    }

    public function test_download_filename_uses_sales_report_prefix(): void
    {
        $export = new CaMonthlyReportExport([
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-07',
            'format' => CaMonthlyReportExportFormat::Xlsx,
        ]);

        $this->assertSame('sales-report-20260901-20260907.xlsx', $export->downloadFilename());
        $this->assertDoesNotMatchRegularExpression('/ca-monthly-report/', $export->downloadFilename());
    }

    public function test_download_filename_rejects_legacy_ca_monthly_report_prefix_for_every_format(): void
    {
        foreach (CaMonthlyReportExportFormat::cases() as $format) {
            $name = (new CaMonthlyReportExport([
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-07',
                'format' => $format,
            ]))->downloadFilename();

            $this->assertSame('sales-report-20260901-20260907.'.$format->extension(), $name);
            $this->assertDoesNotMatchRegularExpression('/ca-monthly-report/', $name);
        }
    }

    public function test_background_and_synchronous_email_exports_share_the_sales_report_workbook(): void
    {
        Mail::fake();
        $this->seedPeriodInvoiceWithBillingState();
        $this->makeTaxInvoice([
            'issued_at' => '2026-08-31 23:59:59',
            'invoice_number' => 'INV-OUTSIDE-RANGE',
            'buyer_gstin' => null,
        ]);

        $background = $this->requestEmailedXlsx('background-export@example.com', syncMaxLines: 0);
        $synchronous = $this->requestEmailedXlsx('sync-export@example.com', syncMaxLines: 500);

        $sent = Mail::sent(CaMonthlyReportExportMail::class);
        $this->assertCount(2, $sent);

        foreach ([$background, $synchronous] as $export) {
            $export->refresh();
            $this->assertSame('sales-report-20260901-20260907.xlsx', $export->downloadFilename());
            $this->assertDoesNotMatchRegularExpression('/ca-monthly-report/', $export->downloadFilename());
            $this->assertSame([], app(CaMonthlyReportXlsxPackageValidator::class)->validate(
                Storage::disk('local')->path((string) $export->storage_path),
            ));
        }

        foreach ($sent as $mail) {
            $attachments = $mail->attachments();
            $this->assertCount(1, $attachments);
            $this->assertSame('sales-report-20260901-20260907.xlsx', $attachments[0]->as);
            $this->assertDoesNotMatchRegularExpression('/ca-monthly-report/', (string) $attachments[0]->as);
            $this->assertStringContainsString('2026-09-01 to 2026-09-07', $mail->render());
        }

        $backgroundXml = $this->withoutGeneratedTimestamp($this->readWorksheetXml(
            Storage::disk('local')->path((string) $background->storage_path),
        ));
        $synchronousXml = $this->withoutGeneratedTimestamp($this->readWorksheetXml(
            Storage::disk('local')->path((string) $synchronous->storage_path),
        ));

        $this->assertSame($synchronousXml, $backgroundXml);
        $this->assertStringContainsString('Branch Name', $backgroundXml);
        $this->assertStringContainsString('Product Name', $backgroundXml);
        $this->assertStringContainsString('Payment Method', $backgroundXml);
        $this->assertStringContainsString('Andhra Pradesh', $backgroundXml);
        $this->assertStringContainsString('RD Service', $backgroundXml);
        $this->assertStringContainsString('Delhi', $backgroundXml);
        $this->assertStringNotContainsString('Customer Type', $backgroundXml);
        $this->assertStringNotContainsString('INV-OUTSIDE-RANGE', $backgroundXml);
        $this->assertSame(1, substr_count($backgroundXml, 'Andhra Pradesh'));

        $zip = new ZipArchive;
        $zip->open(Storage::disk('local')->path((string) $background->storage_path));
        $workbook = $zip->getFromName('xl/workbook.xml');
        $refundSheet = $zip->getFromName('xl/worksheets/sheet2.xml');
        $zip->close();
        $this->assertIsString($workbook);
        $this->assertStringContainsString('Sales Report', $workbook);
        $this->assertStringContainsString('Refund &amp; CN Review', $workbook);
        $this->assertIsString($refundSheet);
        $this->assertStringContainsString('Customer Type', $refundSheet);
    }

    public function test_state_uses_commerce_billing_state_when_invoice_snapshot_has_no_state(): void
    {
        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-07 10:00:00',
            'buyer_gstin' => null,
            'billing_address_structured' => null,
            'place_of_supply_state' => 'Karnataka',
            'channel' => StatutoryInvoiceChannel::RdServiceNet,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'STATE-BILLING',
        ]);

        CommerceOrder::query()->create([
            'order_no' => 'CO-STATE-BILLING',
            'channel' => StatutoryInvoiceChannel::RdServiceNet,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'STATE-BILLING',
            'source_order_id' => 'STATE-BILLING',
            'idempotency_key' => 'statutory:rd_service_net:commerce_order:STATE-BILLING',
            'payload_hash' => hash('sha256', 'STATE-BILLING'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'No Gstin Buyer',
            'billing_state' => 'Andhra Pradesh',
            'billing_address' => '1 Road',
            'shipping_address' => '1 Road',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Karnataka',
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
            'ordered_at' => '2026-09-07 09:00:00',
            'received_at' => now(),
        ]);

        $row = app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->request())[0];

        $this->assertSame('Andhra Pradesh', $row[9]);
        $this->assertNotSame('Karnataka', $row[9]);
        $this->assertSame('RD Service', $row[27]);
        $this->assertSame('Branch Name', CaMonthlyReportDefinition::HEADERS[0]);
        $this->assertNotSame('', $row[0]);
        $this->assertSame($invoice->invoice_number, $row[2]);
    }

    private function request(): Request
    {
        return Request::create('/finance/reports/ca-monthly', 'GET', self::RANGE);
    }

    private function requestEmailedXlsx(string $recipient, int $syncMaxLines): CaMonthlyReportExport
    {
        config(['ca_monthly_report.sync_max_lines' => $syncMaxLines]);

        $user = User::factory()->create([
            'is_active' => true,
            'email' => $recipient,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        return app(CaMonthlyReportExportService::class)->requestFromHttp(
            Request::create('/finance/reports/ca-monthly', 'GET', [
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-07',
            ]),
            $user,
            CaMonthlyReportExportFormat::Xlsx,
            $recipient,
        );
    }

    private function seedPeriodInvoiceWithBillingState(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-07 10:00:00',
            'invoice_number' => 'INV-EMAIL-PATH',
            'buyer_gstin' => null,
            'billing_address_structured' => null,
            'place_of_supply_state' => 'Karnataka',
            'channel' => StatutoryInvoiceChannel::RdServiceNet,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'EMAIL-PATH',
        ]);

        CommerceOrder::query()->create([
            'order_no' => 'CO-EMAIL-PATH',
            'channel' => StatutoryInvoiceChannel::RdServiceNet,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'EMAIL-PATH',
            'source_order_id' => 'EMAIL-PATH',
            'idempotency_key' => 'statutory:rd_service_net:commerce_order:EMAIL-PATH',
            'payload_hash' => hash('sha256', 'EMAIL-PATH'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Email Path Buyer',
            'billing_state' => 'Andhra Pradesh',
            'billing_address' => '1 Road',
            'shipping_address' => '1 Road',
            'branch_code' => 'DELHI-RETAIL',
            'place_of_supply_state' => 'Karnataka',
            'taxable_value' => 100,
            'tax_total' => 18,
            'order_value' => 118,
            'ordered_at' => '2026-09-07 09:00:00',
            'received_at' => now(),
        ]);
    }

    private function withoutGeneratedTimestamp(string $xml): string
    {
        return (string) preg_replace('/Generated[^<]*/', 'Generated', $xml);
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
