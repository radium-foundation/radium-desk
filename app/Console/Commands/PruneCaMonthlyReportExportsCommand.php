<?php

namespace App\Console\Commands;

use App\Models\CaMonthlyReportExport;
use App\Services\Finance\CaMonthlyReportExportStorage;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneCaMonthlyReportExportsCommand extends Command
{
    protected $signature = 'ca-monthly-report:prune-exports {--execute : Delete expired export artifacts and rows}';

    protected $description = 'Prune expired CA Monthly Report export artifacts (dry-run by default)';

    public function handle(CaMonthlyReportExportStorage $storage): int
    {
        $execute = (bool) $this->option('execute');
        $expired = CaMonthlyReportExport::query()
            ->whereNotNull('expires_at')
            ->where('expires_at', '<', now())
            ->orderBy('id')
            ->get();

        $deletedFiles = 0;
        $deletedRows = 0;

        foreach ($expired as $export) {
            if ($export->storage_path !== null && $storage->exists($export)) {
                $deletedFiles++;
                if ($execute) {
                    $storage->deleteArtifact($export);
                }
            }

            $deletedRows++;
            if ($execute) {
                $export->delete();
            }
        }

        $this->info(sprintf(
            '%s %d expired export row(s); %d artifact file(s) %s.',
            $execute ? 'Deleted' : 'Would delete',
            $deletedRows,
            $deletedFiles,
            $execute ? 'removed' : 'candidate for removal',
        ));

        Log::info('ca_monthly_report.prune_exports.completed', [
            'execute' => $execute,
            'rows' => $deletedRows,
            'files' => $deletedFiles,
        ]);

        return self::SUCCESS;
    }
}
