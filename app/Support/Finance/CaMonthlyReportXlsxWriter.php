<?php

namespace App\Support\Finance;

final class CaMonthlyReportXlsxWriter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, list<string>>  $dataRows
     */
    public function write(string $path, array $headers, iterable $dataRows): void
    {
        $writer = new CaMonthlyReportXlsxStreamWriter;
        $writer->open($path, $headers);

        try {
            foreach ($dataRows as $dataRow) {
                $writer->appendRow($dataRow);
            }
            $writer->close();
        } catch (\Throwable $exception) {
            $writer->abort();
            throw $exception;
        }
    }
}
