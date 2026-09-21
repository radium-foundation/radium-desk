<?php

namespace Tests\Feature\Finance;

use App\Enums\StatutoryInvoiceStatus;
use App\Models\InventoryBranch;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportDefinition;
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
            ->get(route('finance.reports.ca-monthly.index', [
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-21',
            ]))
            ->assertOk()
            ->assertSee('CA Monthly Report')
            ->assertSee('Delhi Retail')
            ->assertSee($invoice->invoice_number)
            ->assertSee('RD Service')
            ->assertSee('Preflight');
    }

    public function test_date_range_is_inclusive_on_start_and_end_boundaries(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-01 00:00:00']);
        $this->makeTaxInvoice(['issued_at' => '2026-09-21 23:59:59']);
        $this->makeTaxInvoice(['issued_at' => '2026-09-22 00:00:00']);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $request = Request::create('/', 'GET', [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-21',
        ]);

        $this->assertSame(2, count($readModel->exportRows($request)));
    }

    public function test_zero_result_range_returns_no_rows(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-08-31 23:59:59']);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $request = Request::create('/', 'GET', [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-21',
        ]);

        $this->assertSame([], $readModel->exportRows($request));
    }

    public function test_multi_line_invoice_produces_multiple_rows(): void
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
        $request = Request::create('/', 'GET', [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-21',
        ]);

        $this->assertCount(2, $readModel->exportRows($request));
    }

    public function test_xlsx_export_reproduces_exact_header_row_and_column_count(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $response = $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.export.xlsx', [
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-21',
            ]));

        $response->assertOk();

        $path = $response->baseResponse->getFile()->getPathname();

        $headers = $this->readXlsxRow($path, 3);
        $firstDataRow = $this->readXlsxRow($path, 4);

        $this->assertSame(CaMonthlyReportDefinition::HEADERS, $headers);
        $this->assertCount(26, $headers);
        $this->assertSame('RD Service', $firstDataRow[11]);
        $this->assertCount(26, app(CaMonthlyStatutoryLineReadModel::class)->exportRows(Request::create('/', 'GET', [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-21',
        ]))[0]);
    }

    public function test_preview_and_export_use_the_same_row_mapping(): void
    {
        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'buyer_name' => 'Preview Export Parity Buyer',
        ]);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $request = Request::create('/', 'GET', [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-21',
        ]);

        $item = $invoice->items->first();
        $this->assertNotNull($item);

        $this->assertSame(
            $readModel->previewRow($item),
            $readModel->exportRows($request)[0],
        );
    }

    public function test_unresolved_fields_are_left_blank(): void
    {
        $this->makeTaxInvoice(['issued_at' => '2026-09-10 10:00:00']);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $row = $readModel->exportRows(Request::create('/', 'GET', [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-21',
        ]))[0];

        $this->assertSame('', $row[3]); // Ordertype
        $this->assertSame('', $row[10]); // eWay Bill
        $this->assertSame('', $row[15]); // Shipping
        $this->assertSame('', $row[19]); // Short/Excess
        $this->assertSame('', $row[25]); // Amount
    }

    public function test_cancelled_invoices_are_included_with_status_label(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_at' => '2026-09-11 10:00:00',
            'cancel_reason' => 'Test cancellation',
        ]);

        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $preflight = $readModel->preflight(Request::create('/', 'GET', [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-21',
        ]));

        $row = $readModel->exportRows(Request::create('/', 'GET', [
            'date_from' => '2026-09-01',
            'date_to' => '2026-09-21',
        ]))[0];

        $this->assertSame(1, $preflight->cancelledInvoiceCount);
        $this->assertSame('Cancelled', $row[23]);
    }

    public function test_agent_cannot_access_ca_monthly_report(): void
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)
            ->get(route('finance.reports.ca-monthly.index', [
                'date_from' => '2026-09-01',
                'date_to' => '2026-09-21',
            ]))
            ->assertForbidden();
    }

    public function test_xlsx_writer_places_headers_on_row_three(): void
    {
        $path = storage_path('app/tmp/ca-monthly-writer-test.xlsx');
        app(CaMonthlyReportXlsxWriter::class)->write(
            $path,
            CaMonthlyReportDefinition::HEADERS,
            [['Only', 'Data', 'Row']],
        );

        $this->assertSame(CaMonthlyReportDefinition::HEADERS, $this->readXlsxRow($path, 3));
        $this->assertSame(['Only', 'Data', 'Row'], array_slice($this->readXlsxRow($path, 4), 0, 3));

        @unlink($path);
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
