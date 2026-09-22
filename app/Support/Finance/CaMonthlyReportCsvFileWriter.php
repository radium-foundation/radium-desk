<?php

namespace App\Support\Finance;

final class CaMonthlyReportCsvFileWriter
{
    /** @var resource|null */
    private $handle = null;

    /**
     * @param  list<string>  $headers
     */
    public function open(string $path, array $headers): void
    {
        $handle = fopen($path, 'wb');
        if ($handle === false) {
            throw new \RuntimeException('Could not open CSV export file.');
        }

        $this->handle = $handle;
        fputcsv($handle, $headers);
    }

    /**
     * @param  list<string>  $row
     */
    public function appendRow(array $row): void
    {
        if (! is_resource($this->handle)) {
            throw new \RuntimeException('CSV file writer is not open.');
        }

        fputcsv($this->handle, $row);
    }

    public function close(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
            $this->handle = null;
        }
    }

    public function abort(): void
    {
        if (is_resource($this->handle)) {
            fclose($this->handle);
            $this->handle = null;
        }
    }
}
