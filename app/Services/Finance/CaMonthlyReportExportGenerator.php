<?php

namespace App\Services\Finance;

use App\Enums\CaMonthlyReportExportFormat;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Support\Finance\CaMonthlyReportCsvFileWriter;
use App\Support\Finance\CaMonthlyReportXlsxStreamWriter;
use Illuminate\Http\Request;

final class CaMonthlyReportExportGenerator
{
    public function __construct(
        private readonly CaMonthlyStatutoryLineReadModel $readModel,
    ) {}

    /**
     * @return array{row_count: int, file_size_bytes: int}
     */
    public function generateToPath(Request $request, CaMonthlyReportExportFormat $format, string $absolutePath): array
    {
        $headers = $this->readModel->headers();
        $rowCount = 0;

        if ($format === CaMonthlyReportExportFormat::Csv) {
            $writer = new CaMonthlyReportCsvFileWriter;
            $writer->open($absolutePath, $headers);
            try {
                $rowCount = $this->readModel->streamExportRows($request, function (array $row) use ($writer): void {
                    $writer->appendRow($row);
                });
                $writer->close();
            } catch (\Throwable $exception) {
                $writer->abort();
                throw $exception;
            }
        } else {
            $writer = new CaMonthlyReportXlsxStreamWriter;
            $writer->open($absolutePath, $headers);
            try {
                $rowCount = $this->readModel->streamExportRows($request, function (array $row) use ($writer): void {
                    $writer->appendRow($row);
                });
                $writer->close();
            } catch (\Throwable $exception) {
                $writer->abort();
                throw $exception;
            }
        }

        if (! is_file($absolutePath)) {
            throw new \RuntimeException('Export artifact was not created.');
        }

        return [
            'row_count' => $rowCount,
            'file_size_bytes' => (int) filesize($absolutePath),
        ];
    }
}
