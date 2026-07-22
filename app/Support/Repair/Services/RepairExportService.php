<?php

namespace App\Support\Repair\Services;

use App\Support\Repair\Data\RepairBatchSummary;
use App\Support\Repair\Models\SystemRepairBatch;
use Illuminate\Support\Facades\Storage;

class RepairExportService
{
    /**
     * @return array{json: ?string, csv: ?string}
     */
    public function export(
        SystemRepairBatch $batch,
        RepairBatchSummary $summary,
        bool $writeJson,
        bool $writeCsv,
        ?string $exportPath = null,
    ): array {
        $basePath = trim($exportPath ?? (string) config('repair.export_path', 'repairs'), '/');
        $date = now()->toDateString();
        $directory = $basePath.'/'.$date;
        $stem = sprintf('%s_%s', $batch->repair_key, $batch->uuid);

        $paths = ['json' => null, 'csv' => null];

        if ($writeJson) {
            $relative = $directory.'/'.$stem.'.json';
            Storage::disk('local')->put(
                $relative,
                json_encode($summary->toArray(), JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) ?: '{}',
            );
            $paths['json'] = Storage::disk('local')->path($relative);
        }

        if ($writeCsv) {
            $relative = $directory.'/'.$stem.'.csv';
            $lines = ['batch_uuid,repair_key,subject_key,subject_type,subject_id,action,category,outcome,skip_reason,error_message'];

            foreach ($batch->items()->orderBy('id')->cursor() as $item) {
                $lines[] = implode(',', [
                    $this->csv($batch->uuid),
                    $this->csv($batch->repair_key),
                    $this->csv((string) $item->subject_key),
                    $this->csv($item->subject_type),
                    $this->csv((string) $item->subject_id),
                    $this->csv($item->action),
                    $this->csv((string) $item->category),
                    $this->csv($item->outcome->value),
                    $this->csv((string) $item->skip_reason),
                    $this->csv((string) $item->error_message),
                ]);
            }

            Storage::disk('local')->put($relative, implode("\n", $lines)."\n");
            $paths['csv'] = Storage::disk('local')->path($relative);
        }

        return $paths;
    }

    private function csv(string $value): string
    {
        $escaped = str_replace('"', '""', $value);

        return '"'.$escaped.'"';
    }
}
