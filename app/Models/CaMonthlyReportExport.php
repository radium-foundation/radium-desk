<?php

namespace App\Models;

use App\Enums\CaMonthlyReportEmailDeliveryMode;
use App\Enums\CaMonthlyReportEmailStatus;
use App\Enums\CaMonthlyReportExportFormat;
use App\Enums\CaMonthlyReportExportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Carbon;

class CaMonthlyReportExport extends Model
{
    protected $fillable = [
        'user_id',
        'status',
        'format',
        'date_from',
        'date_to',
        'row_count',
        'storage_disk',
        'storage_path',
        'file_size_bytes',
        'failure_message',
        'idempotency_key',
        'email_recipient',
        'email_status',
        'email_delivery_mode',
        'email_failure_message',
        'email_queued_at',
        'email_sent_at',
        'processing_started_at',
        'completed_at',
        'expires_at',
        'downloaded_at',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'status' => CaMonthlyReportExportStatus::class,
            'format' => CaMonthlyReportExportFormat::class,
            'email_status' => CaMonthlyReportEmailStatus::class,
            'email_delivery_mode' => CaMonthlyReportEmailDeliveryMode::class,
            'date_from' => 'date',
            'date_to' => 'date',
            'email_queued_at' => 'datetime',
            'email_sent_at' => 'datetime',
            'processing_started_at' => 'datetime',
            'completed_at' => 'datetime',
            'expires_at' => 'datetime',
            'downloaded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function isOwnedBy(User $user): bool
    {
        return (int) $this->user_id === (int) $user->id;
    }

    public function isExpired(): bool
    {
        return $this->expires_at instanceof Carbon
            && $this->expires_at->isPast();
    }

    public function isDownloadable(): bool
    {
        return $this->status === CaMonthlyReportExportStatus::Ready
            && $this->storage_path !== null
            && ! $this->isExpired();
    }

    public function dateRangeLabel(): string
    {
        return $this->date_from->toDateString().' to '.$this->date_to->toDateString();
    }

    public function downloadFilename(): string
    {
        return sprintf(
            'ca-monthly-report-%s-%s.%s',
            $this->date_from->format('Ymd'),
            $this->date_to->format('Ymd'),
            $this->format->extension(),
        );
    }
}
