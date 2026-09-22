<?php

namespace Tests\Support;

use App\Enums\CaMonthlyReportExportFormat;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Services\Finance\CaMonthlyReportExportGenerator;
use Illuminate\Http\Request;

final class CaMonthlyReportExportBenchmarkRecorder
{
    /**
     * @return array{
     *     lines: int,
     *     format: string,
     *     elapsed_ms: float,
     *     peak_memory_bytes: int,
     *     peak_memory_mb: float,
     *     file_size_bytes: int,
     *     row_count: int
     * }
     */
    public static function measureXlsxExport(Request $request, string $outputPath): array
    {
        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $lineCount = $readModel->countExportLines($request);

        $start = microtime(true);
        $peak = memory_get_usage(true);

        $result = app(CaMonthlyReportExportGenerator::class)->generateToPath(
            $request,
            CaMonthlyReportExportFormat::Xlsx,
            $outputPath,
        );

        $peak = max($peak, memory_get_peak_usage(true));
        $elapsedMs = (microtime(true) - $start) * 1000;

        return [
            'lines' => $lineCount,
            'format' => 'xlsx',
            'elapsed_ms' => round($elapsedMs, 2),
            'peak_memory_bytes' => $peak,
            'peak_memory_mb' => round($peak / 1024 / 1024, 2),
            'file_size_bytes' => (int) filesize($outputPath),
            'row_count' => $result['row_count'],
        ];
    }

    /**
     * @return array{
     *     lines: int,
     *     format: string,
     *     elapsed_ms: float,
     *     peak_memory_bytes: int,
     *     peak_memory_mb: float,
     *     row_count: int
     * }
     */
    public static function measureStreamExport(Request $request): array
    {
        $readModel = app(CaMonthlyStatutoryLineReadModel::class);
        $lineCount = $readModel->countExportLines($request);

        $start = microtime(true);
        $peak = memory_get_usage(true);
        $rows = 0;

        $readModel->streamExportRows($request, function () use (&$peak, &$rows): void {
            $rows++;
            $peak = max($peak, memory_get_usage(true));
        });

        $peak = max($peak, memory_get_peak_usage(true));

        return [
            'lines' => $lineCount,
            'format' => 'stream',
            'elapsed_ms' => round((microtime(true) - $start) * 1000, 2),
            'peak_memory_bytes' => $peak,
            'peak_memory_mb' => round($peak / 1024 / 1024, 2),
            'row_count' => $rows,
        ];
    }

    /**
     * @param  list<array<string, int|float|string>>  $results
     */
    public static function writeResults(array $results, string $path): void
    {
        file_put_contents($path, json_encode($results, JSON_PRETTY_PRINT));
    }
}
