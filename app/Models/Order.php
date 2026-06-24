<?php

namespace App\Models;

use App\Enums\OrderCompletionStatus;
use App\Enums\OrderStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Order extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_id',
        'customer_id',
        'serial_number',
        'product_name',
        'device_model',
        'transaction_id',
        'completed_at',
        'transaction_assigned_by',
        'customer_name',
        'customer_email',
        'customer_phone',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'completed_at' => 'datetime',
        ];
    }

    public function completionStatus(): OrderCompletionStatus
    {
        return filled($this->transaction_id)
            ? OrderCompletionStatus::Completed
            : OrderCompletionStatus::PendingAdmin;
    }

    public function isTransactionLocked(): bool
    {
        return filled($this->transaction_id);
    }

    public function completionTooltipHtml(Carbon $loggedAt): string
    {
        if ($this->completionStatus() === OrderCompletionStatus::PendingAdmin) {
            $lines = [
                'Waiting for Transaction ID',
                '',
                'Created:',
                $loggedAt->format('d M Y, h:i A'),
                '',
                'Pending for:',
                self::formatDurationBetween($loggedAt) ?? '—',
            ];
        } else {
            $lines = [
                'Transaction ID: '.($this->transaction_id ?: '—'),
                '',
                'Completed:',
                $this->completed_at?->format('d M Y, h:i A') ?? '—',
                '',
                'Total turnaround:',
                self::formatDurationBetween($loggedAt, $this->completed_at) ?? '—',
            ];
        }

        return collect($lines)
            ->map(fn (string $line): string => e($line))
            ->implode('<br>');
    }

    public static function formatDurationBetween(?Carbon $from, ?Carbon $to = null): ?string
    {
        if ($from === null) {
            return null;
        }

        $to ??= now();

        if ($to->lessThan($from)) {
            return null;
        }

        $diff = $from->diff($to);
        $parts = [];

        if ($diff->d > 0) {
            $parts[] = $diff->d.' '.Str::plural('day', $diff->d);

            if ($diff->h > 0) {
                $parts[] = $diff->h.' '.Str::plural('hour', $diff->h);
            }
        } elseif ($diff->h > 0) {
            $parts[] = $diff->h.' '.Str::plural('hour', $diff->h);

            if ($diff->i > 0) {
                $parts[] = $diff->i.' '.Str::plural('minute', $diff->i);
            }
        } elseif ($diff->i > 0) {
            $parts[] = $diff->i.' '.Str::plural('minute', $diff->i);
        } else {
            $parts[] = 'less than a minute';
        }

        return implode(' ', $parts);
    }

    public function transactionAssignTooltipHtml(): string
    {
        $lines = [
            'Completed',
            $this->completed_at?->format('d M Y, h:i A') ?? '—',
            'Assigned by '.($this->transactionAssigner?->firstName() ?? '—'),
        ];

        return collect($lines)
            ->map(fn (string $line): string => e($line))
            ->implode('<br>');
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function transactionAssigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'transaction_assigned_by');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function remarks(): MorphMany
    {
        return $this->morphMany(Remark::class, 'remarkable');
    }
}
