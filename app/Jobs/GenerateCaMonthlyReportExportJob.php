<?php

namespace App\Jobs;

use App\Enums\CaMonthlyReportEmailStatus;
use App\Enums\CaMonthlyReportExportStatus;
use App\Infrastructure\Queue\QueueRouting;
use App\Models\CaMonthlyReportExport;
use App\Services\Finance\CaMonthlyReportExportService;
use App\Services\Finance\CaMonthlyReportExportStorage;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class GenerateCaMonthlyReportExportJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 2;

    public int $timeout = 900;

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $exportId,
    ) {
        $this->onQueue((string) config('ca_monthly_report.export_queue', QueueRouting::maintenance()));
    }

    public function uniqueId(): string
    {
        return 'ca-monthly-export:'.$this->exportId;
    }

    public function handle(CaMonthlyReportExportService $exportService): void
    {
        $export = CaMonthlyReportExport::query()->find($this->exportId);
        if ($export === null) {
            return;
        }

        if ($export->status === CaMonthlyReportExportStatus::Ready) {
            return;
        }

        $request = Request::create('/finance/reports/ca-monthly', 'GET', [
            'date_from' => $export->date_from->toDateString(),
            'date_to' => $export->date_to->toDateString(),
        ]);

        $exportService->generateNow($export, $request);

        if ($export->email_recipient !== null
            && $export->email_status === CaMonthlyReportEmailStatus::Queued) {
            SendCaMonthlyReportExportEmailJob::dispatch($export->id);
        }
    }

    public function failed(?\Throwable $exception): void
    {
        $export = CaMonthlyReportExport::query()->find($this->exportId);
        if ($export === null) {
            return;
        }

        if ($export->status !== CaMonthlyReportExportStatus::Failed) {
            app(CaMonthlyReportExportStorage::class)->deleteArtifact($export);

            $export->forceFill([
                'status' => CaMonthlyReportExportStatus::Failed,
                'storage_path' => null,
                'failure_message' => Str::limit($exception?->getMessage() ?? 'Export failed.', 1000),
                'completed_at' => now(),
            ])->save();
        }

        Log::warning('ca_monthly_report.export_generation_failed', [
            'export_id' => $this->exportId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
