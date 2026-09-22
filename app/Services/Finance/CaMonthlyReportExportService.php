<?php

namespace App\Services\Finance;

use App\Enums\CaMonthlyReportEmailDeliveryMode;
use App\Enums\CaMonthlyReportEmailStatus;
use App\Enums\CaMonthlyReportExportFormat;
use App\Enums\CaMonthlyReportExportStatus;
use App\Jobs\GenerateCaMonthlyReportExportJob;
use App\Jobs\SendCaMonthlyReportExportEmailJob;
use App\Models\CaMonthlyReportExport;
use App\Models\User;
use App\Support\Finance\ReportPeriod;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

final class CaMonthlyReportExportService
{
    public function __construct(
        private readonly CaMonthlyReportExportStorage $storage,
        private readonly CaMonthlyReportExportGenerator $generator,
        private readonly CaMonthlyReportExportThreshold $threshold,
    ) {}

    public function requestFromHttp(Request $request, User $user, CaMonthlyReportExportFormat $format, ?string $emailRecipient = null): CaMonthlyReportExport
    {
        $period = ReportPeriod::fromRequest($request);
        $dateFrom = $period->from ?? now()->toDateString();
        $dateTo = $period->to ?? now()->toDateString();
        $idempotencyKey = $this->idempotencyKey($user, $dateFrom, $dateTo, $format);

        $existing = $this->findReusableExport($user, $idempotencyKey);
        if ($existing !== null) {
            if ($emailRecipient !== null && $this->shouldQueueEmail($existing, $emailRecipient)) {
                $this->queueEmail($existing, $emailRecipient);
            }

            return $existing;
        }

        return DB::transaction(function () use ($user, $dateFrom, $dateTo, $format, $idempotencyKey, $request, $emailRecipient): CaMonthlyReportExport {
            $export = CaMonthlyReportExport::query()->create([
                'user_id' => $user->id,
                'status' => CaMonthlyReportExportStatus::Queued,
                'format' => $format,
                'date_from' => $dateFrom,
                'date_to' => $dateTo,
                'storage_disk' => (string) config('ca_monthly_report.storage_disk', 'local'),
                'idempotency_key' => $idempotencyKey,
                'email_recipient' => $emailRecipient,
                'email_status' => $emailRecipient !== null ? CaMonthlyReportEmailStatus::Queued : null,
                'email_queued_at' => $emailRecipient !== null ? now() : null,
                'expires_at' => $this->defaultExpiry(),
            ]);

            if ($this->threshold->requiresAsync($request)) {
                GenerateCaMonthlyReportExportJob::dispatch($export->id);

                return $export;
            }

            $this->generateNow($export, $request);

            if ($emailRecipient !== null) {
                SendCaMonthlyReportExportEmailJob::dispatch($export->id);
            }

            return $export->fresh() ?? $export;
        });
    }

    public function generateNow(CaMonthlyReportExport $export, Request $request): void
    {
        if ($export->status === CaMonthlyReportExportStatus::Ready) {
            return;
        }

        $export->forceFill([
            'status' => CaMonthlyReportExportStatus::Processing,
            'processing_started_at' => now(),
            'failure_message' => null,
        ])->save();

        $this->storage->deleteArtifact($export);

        $storagePath = $this->storage->allocatePath($export);
        $absolutePath = $this->storage->disk()->path($storagePath);
        $this->storage->disk()->makeDirectory(dirname($storagePath));

        try {
            $result = $this->generator->generateToPath($request, $export->format, $absolutePath);

            $export->forceFill([
                'status' => CaMonthlyReportExportStatus::Ready,
                'storage_path' => $storagePath,
                'row_count' => $result['row_count'],
                'file_size_bytes' => $result['file_size_bytes'],
                'completed_at' => now(),
                'expires_at' => $this->defaultExpiry(),
                'failure_message' => null,
            ])->save();
        } catch (\Throwable $exception) {
            $this->storage->deleteArtifact($export->forceFill(['storage_path' => $storagePath]));

            $export->forceFill([
                'status' => CaMonthlyReportExportStatus::Failed,
                'storage_path' => null,
                'failure_message' => Str::limit($exception->getMessage(), 1000),
                'completed_at' => now(),
            ])->save();

            throw $exception;
        }
    }

    public function queueEmail(CaMonthlyReportExport $export, string $recipient): void
    {
        if ($export->email_status === CaMonthlyReportEmailStatus::Queued) {
            return;
        }

        $export->forceFill([
            'email_recipient' => $recipient,
            'email_status' => CaMonthlyReportEmailStatus::Queued,
            'email_failure_message' => null,
            'email_queued_at' => now(),
        ])->save();

        SendCaMonthlyReportExportEmailJob::dispatch($export->id);
    }

    public function authorizeDownload(CaMonthlyReportExport $export, User $user): void
    {
        abort_unless($export->isOwnedBy($user), 403);
        abort_if($export->isExpired(), 410, 'This export has expired.');
        abort_unless($export->isDownloadable(), 404, 'Export is not ready for download.');
        abort_unless($this->storage->exists($export), 404, 'Export file is unavailable.');
    }

    public function markDownloaded(CaMonthlyReportExport $export): void
    {
        $export->forceFill(['downloaded_at' => now()])->save();
    }

    public function resolveDeliveryMode(CaMonthlyReportExport $export): CaMonthlyReportEmailDeliveryMode
    {
        $limit = (int) config('ca_monthly_report.max_email_attachment_bytes', 8 * 1024 * 1024);
        $size = (int) ($export->file_size_bytes ?? 0);

        return $size > $limit
            ? CaMonthlyReportEmailDeliveryMode::DownloadLink
            : CaMonthlyReportEmailDeliveryMode::Attachment;
    }

    /**
     * @return Collection<int, CaMonthlyReportExport>
     */
    public function recentExportsForUser(User $user, int $limit = 10)
    {
        return CaMonthlyReportExport::query()
            ->where('user_id', $user->id)
            ->orderByDesc('id')
            ->limit($limit)
            ->get();
    }

    private function findReusableExport(User $user, string $idempotencyKey): ?CaMonthlyReportExport
    {
        $windowMinutes = max(1, (int) config('ca_monthly_report.idempotency_window_minutes', 10));

        return CaMonthlyReportExport::query()
            ->where('user_id', $user->id)
            ->where('idempotency_key', $idempotencyKey)
            ->where('created_at', '>=', now()->subMinutes($windowMinutes))
            ->whereIn('status', [
                CaMonthlyReportExportStatus::Queued,
                CaMonthlyReportExportStatus::Processing,
                CaMonthlyReportExportStatus::Ready,
            ])
            ->orderByDesc('id')
            ->first();
    }

    private function shouldQueueEmail(CaMonthlyReportExport $export, string $recipient): bool
    {
        if ($export->email_status === CaMonthlyReportEmailStatus::Sent
            && $export->email_recipient === $recipient) {
            return false;
        }

        return $export->email_status !== CaMonthlyReportEmailStatus::Queued;
    }

    private function idempotencyKey(User $user, string $from, string $to, CaMonthlyReportExportFormat $format): string
    {
        return hash('sha256', implode('|', [
            (string) $user->id,
            $from,
            $to,
            $format->value,
        ]));
    }

    private function defaultExpiry(): Carbon
    {
        return now()->addHours(max(1, (int) config('ca_monthly_report.retention_hours', 72)));
    }
}
