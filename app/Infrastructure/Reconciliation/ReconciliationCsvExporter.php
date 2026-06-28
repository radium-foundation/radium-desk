<?php

namespace App\Infrastructure\Reconciliation;

class ReconciliationCsvExporter
{
    /**
     * @param  list<ReconciliationOrderRow>  $rows
     */
    public function export(array $rows): string
    {
        $handle = fopen('php://temp', 'r+');

        if ($handle === false) {
            return '';
        }

        fputcsv($handle, ReconciliationOrderRow::csvHeaders());

        foreach ($rows as $row) {
            fputcsv($handle, $row->toCsvRow());
        }

        rewind($handle);
        $csv = stream_get_contents($handle);
        fclose($handle);

        return is_string($csv) ? $csv : '';
    }
}
