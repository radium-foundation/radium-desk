<?php

namespace App\Jobs;

use App\Enums\CaMonthlyReportEmailDeliveryMode;
use App\Enums\CaMonthlyReportEmailStatus;
use App\Enums\CaMonthlyReportExportStatus;
use App\Infrastructure\Queue\QueueRouting;
use App\Mail\CaMonthlyReportExportMail;
use App\Models\CaMonthlyReportExport;
use App\Services\Finance\CaMonthlyReportExportService;
use App\Services\Notifications\NotificationMailSender;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;

class SendCaMonthlyReportExportEmailJob implements ShouldBeUnique, ShouldQueue
{
    use Queueable;

    public int $tries = 3;

    /** @var list<int> */
    public array $backoff = [60, 300];

    public int $uniqueFor = 3600;

    public function __construct(
        public readonly int $exportId,
    ) {
        $this->onQueue(QueueRouting::notifications());
    }

    public function uniqueId(): string
    {
        return 'ca-monthly-export-email:'.$this->exportId;
    }

    public function handle(
        CaMonthlyReportExportService $exportService,
        NotificationMailSender $mailSender,
    ): void {
        $export = CaMonthlyReportExport::query()->find($this->exportId);
        if ($export === null) {
            return;
        }

        if ($export->email_recipient === null) {
            return;
        }

        if ($export->status !== CaMonthlyReportExportStatus::Ready) {
            return;
        }

        if ($export->email_status === CaMonthlyReportEmailStatus::Sent) {
            return;
        }

        $deliveryMode = $exportService->resolveDeliveryMode($export);
        $downloadUrl = null;

        if ($deliveryMode === CaMonthlyReportEmailDeliveryMode::DownloadLink) {
            $downloadUrl = URL::temporarySignedRoute(
                'finance.reports.ca-monthly.exports.download.signed',
                $export->expires_at ?? now()->addHours(72),
                ['export' => $export->id],
            );
        }

        $export->forceFill([
            'email_delivery_mode' => $deliveryMode,
            'email_status' => CaMonthlyReportEmailStatus::Queued,
            'email_queued_at' => now(),
        ])->save();

        $result = $mailSender->send(
            $export->email_recipient,
            new CaMonthlyReportExportMail($export, $deliveryMode, $downloadUrl),
        );

        if ($result['success']) {
            $export->forceFill([
                'email_status' => CaMonthlyReportEmailStatus::Sent,
                'email_sent_at' => now(),
                'email_failure_message' => null,
            ])->save();

            return;
        }

        $export->forceFill([
            'email_status' => CaMonthlyReportEmailStatus::Failed,
            'email_failure_message' => $result['error'],
        ])->save();
    }

    public function failed(?\Throwable $exception): void
    {
        $export = CaMonthlyReportExport::query()->find($this->exportId);
        if ($export === null) {
            return;
        }

        $export->forceFill([
            'email_status' => CaMonthlyReportEmailStatus::Failed,
            'email_failure_message' => Str::limit($exception?->getMessage() ?? 'Email failed.', 1000),
        ])->save();

        Log::warning('ca_monthly_report.export_email_failed', [
            'export_id' => $this->exportId,
            'error' => $exception?->getMessage(),
        ]);
    }
}
