<?php

namespace Tests\Feature\Finance;

use App\Reports\CaMonthly\CaMonthlyReportDefinition;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;
use Tests\Support\CaMonthlyReportExportBenchmarkRecorder;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

/**
 * Benchmark harness for CA export sizing. Run explicitly when tuning sync threshold.
 *
 * php vendor/bin/phpunit tests/Feature/Finance/CaMonthlyReportExportBenchmarkTest.php
 */
class CaMonthlyReportExportBenchmarkTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private const RANGE = [
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-21',
    ];

    /** @var list<int> */
    private const SIZES = [100, 250, 500, 1000, 2500, 5000];

    private int $seededInvoiceCount = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        File::ensureDirectoryExists(storage_path('app/tmp'));
        $this->seededInvoiceCount = 0;
    }

    public function test_records_export_generation_benchmarks(): void
    {
        $results = [];

        foreach (self::SIZES as $targetLines) {
            $this->seedInvoiceLines($targetLines);
            $request = Request::create('/', 'GET', self::RANGE);

            $stream = CaMonthlyReportExportBenchmarkRecorder::measureStreamExport($request);
            $this->assertSame($targetLines, $stream['row_count']);

            $xlsxPath = storage_path('app/tmp/ca-benchmark-'.$targetLines.'.xlsx');
            $xlsx = CaMonthlyReportExportBenchmarkRecorder::measureXlsxExport($request, $xlsxPath);
            $this->assertSame($targetLines, $xlsx['row_count']);
            $this->assertSame(CaMonthlyReportDefinition::HEADERS, $this->readXlsxHeaders($xlsxPath));

            @unlink($xlsxPath);

            $results[] = $stream;
            $results[] = $xlsx;

            fwrite(STDERR, sprintf(
                "CA benchmark: %d lines | stream %.2fms %.2fMB | xlsx %.2fms %.2fMB file=%dB\n",
                $targetLines,
                $stream['elapsed_ms'],
                $stream['peak_memory_mb'],
                $xlsx['elapsed_ms'],
                $xlsx['peak_memory_mb'],
                $xlsx['file_size_bytes'],
            ));
        }

        CaMonthlyReportExportBenchmarkRecorder::writeResults(
            $results,
            storage_path('app/tmp/ca-export-benchmark-results.json'),
        );

        $this->assertNotEmpty($results);
    }

    public function test_ten_times_stream_memory_records_actual_peak_values(): void
    {
        $this->seededInvoiceCount = 0;
        $this->seedInvoiceLines(40);
        $baseline = CaMonthlyReportExportBenchmarkRecorder::measureStreamExport($this->request());

        $this->seedInvoiceLines(400);
        $scaled = CaMonthlyReportExportBenchmarkRecorder::measureStreamExport($this->request());

        fwrite(STDERR, sprintf(
            "10x memory: baseline lines=%d peak=%.2fMB elapsed=%.2fms | scaled lines=%d peak=%.2fMB elapsed=%.2fms\n",
            $baseline['row_count'],
            $baseline['peak_memory_mb'],
            $baseline['elapsed_ms'],
            $scaled['row_count'],
            $scaled['peak_memory_mb'],
            $scaled['elapsed_ms'],
        ));

        $this->assertSame(40, $baseline['row_count']);
        $this->assertSame(400, $scaled['row_count']);
        $this->assertGreaterThan(0, $baseline['peak_memory_bytes']);
        $this->assertLessThan(
            $baseline['peak_memory_bytes'] * 4,
            $scaled['peak_memory_bytes'],
            'Peak memory grew faster than 4x while row volume grew 10x.',
        );
    }

    private function request(): Request
    {
        return Request::create('/', 'GET', self::RANGE);
    }

    private function seedInvoiceLines(int $targetCount): void
    {
        $toCreate = $targetCount - $this->seededInvoiceCount;
        for ($i = 0; $i < $toCreate; $i++) {
            $index = $this->seededInvoiceCount + $i;
            $day = str_pad((string) (($index % 20) + 1), 2, '0', STR_PAD_LEFT);
            $this->makeTaxInvoice(['issued_at' => "2026-09-{$day} 10:00:00"]);
        }

        $this->seededInvoiceCount = $targetCount;
    }

    /**
     * @return list<string>
     */
    private function readXlsxHeaders(string $path): array
    {
        $zip = new \ZipArchive;
        $zip->open($path);
        $xml = (string) $zip->getFromName('xl/worksheets/sheet1.xml');
        $zip->close();

        $sheet = simplexml_load_string($xml);
        $ns = $sheet->getNamespaces(true);
        $main = $ns[''] ?? 'http://schemas.openxmlformats.org/spreadsheetml/2006/main';
        $sheet->registerXPathNamespace('m', $main);
        $cells = $sheet->xpath('//m:sheetData/m:row[@r="3"]/m:c');

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

        ksort($values);

        return array_values($values);
    }
}
