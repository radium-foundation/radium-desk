<?php

namespace App\Support\Finance;

use App\Reports\CaMonthly\CaMonthlyReportInvoiceExportRow;
use App\Reports\CaMonthly\CaMonthlyReportWorkbookMeta;

final class CaMonthlyReportXlsxWriter
{
    /**
     * @param  list<string>  $headers
     * @param  iterable<int, CaMonthlyReportInvoiceExportRow>  $invoiceRows
     */
    public function write(string $path, array $headers, iterable $invoiceRows, ?CaMonthlyReportWorkbookMeta $meta = null): void
    {
        $writer = new CaMonthlyReportXlsxStreamWriter;
        $writer->open($path, $headers, $meta);

        try {
            foreach ($invoiceRows as $invoiceRow) {
                $writer->appendInvoiceGroup($invoiceRow);
            }
            $writer->close();
        } catch (\Throwable $exception) {
            $writer->abort();
            throw $exception;
        }
    }
}
