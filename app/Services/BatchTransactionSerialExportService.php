<?php

namespace App\Services;

use App\Models\Incident;
use App\Support\Oem\RdWarrantyCheckSerialNumbersXlsxWriter;
use Illuminate\Support\Collection;

class BatchTransactionSerialExportService
{
    public function __construct(
        private readonly RdWarrantyCheckSerialNumbersXlsxWriter $writer,
    ) {}

    /**
     * @param  list<int>  $incidentIds
     * @return list<string>
     */
    public function serialNumbersForIncidents(array $incidentIds): array
    {
        return $this->resolveIncidents($incidentIds)
            ->map(fn (Incident $incident) => $incident->order?->serial_number)
            ->filter(fn ($serial) => filled($serial))
            ->map(fn ($serial) => trim((string) $serial))
            ->filter(fn (string $serial) => $serial !== '')
            ->values()
            ->all();
    }

    /**
     * @param  list<int>  $incidentIds
     */
    public function writeWorkbookForIncidents(array $incidentIds, string $outputPath): void
    {
        $serials = $this->serialNumbersForIncidents($incidentIds);

        $this->writer->writeToPath($outputPath, $serials);
    }

    public function downloadFilename(): string
    {
        return 'RDWarrantyCheck-SerialNumbers-'.now('Asia/Kolkata')->format('Ymd-Hi').'.xlsx';
    }

    /**
     * @param  list<int>  $incidentIds
     * @return Collection<int, Incident>
     */
    private function resolveIncidents(array $incidentIds): Collection
    {
        return Incident::query()
            ->with('order')
            ->whereIn('id', $incidentIds)
            ->get()
            ->sortBy(fn (Incident $incident): int => array_search($incident->id, $incidentIds, true) ?: PHP_INT_MAX)
            ->values();
    }
}
