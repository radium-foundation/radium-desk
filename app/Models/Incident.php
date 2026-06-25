<?php

namespace App\Models;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Services\SettingService;
use App\Support\AppDateFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Incident extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_id',
        'reference_no',
        'category',
        'source',
        'title',
        'description',
        'status',
        'high_priority',
        'assigned_to_user_id',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'source' => IncidentSource::class,
            'high_priority' => 'boolean',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function assignee(): BelongsTo
    {
        return $this->belongsTo(User::class, 'assigned_to_user_id');
    }

    public function updater(): BelongsTo
    {
        return $this->belongsTo(User::class, 'updated_by');
    }

    public function remarks(): MorphMany
    {
        return $this->morphMany(Remark::class, 'remarkable');
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function approvalNumbers(): BelongsToMany
    {
        return $this->belongsToMany(ApprovalNumber::class, 'approval_incident')
            ->withPivot(['linked_by', 'created_at']);
    }

    public function getDisplayReferenceAttribute(): string
    {
        if ($this->reference_no === null || $this->reference_no === '') {
            return '';
        }

        if (preg_match('/^SC-?(\d+)$/i', $this->reference_no, $matches) === 1) {
            return 'SC'.str_pad($matches[1], 5, '0', STR_PAD_LEFT);
        }

        return $this->reference_no;
    }

    public static function parseReferenceSequence(string $query): ?int
    {
        $query = trim($query);

        if ($query === '') {
            return null;
        }

        if (preg_match('/^SC[- ]?(\d+)$/i', $query, $matches) === 1) {
            return (int) $matches[1];
        }

        if (preg_match('/^\d+$/', $query) === 1) {
            return (int) $query;
        }

        return null;
    }

    public function scopeMatchingReference(Builder $query, string $term): void
    {
        $term = trim($term);

        if ($term === '') {
            $query->whereRaw('0 = 1');

            return;
        }

        $sequence = self::parseReferenceSequence($term);

        if ($sequence !== null) {
            $padded = str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);

            $query->where(function (Builder $builder) use ($term, $sequence, $padded) {
                $builder->where('reference_no', 'like', '%'.$term.'%')
                    ->orWhere('reference_no', 'SC-'.$padded)
                    ->orWhere('reference_no', 'SC'.$padded);

                if ($sequence !== (int) $padded) {
                    $builder->orWhere('reference_no', 'SC-'.$sequence)
                        ->orWhere('reference_no', 'SC'.$sequence);
                }
            });

            return;
        }

        $query->where('reference_no', 'like', '%'.$term.'%');
    }

    public function isActive(): bool
    {
        return in_array($this->status, [IncidentStatus::Open, IncidentStatus::InProgress], true);
    }

    public function issueSummary(): string
    {
        $title = trim((string) $this->title);

        if ($title !== '') {
            return Str::limit($title, 80);
        }

        return Str::limit(trim((string) $this->description), 80) ?: '—';
    }

    public function isPendingAdmin(): bool
    {
        return $this->order !== null && ! $this->order->isTransactionLocked();
    }

    public function slaStatus(?Carbon $now = null): ServiceCaseSlaStatus
    {
        if (! $this->isPendingAdmin() || $this->created_at === null) {
            return ServiceCaseSlaStatus::WithinSla;
        }

        $now ??= now();
        $hoursPending = (int) $this->created_at->diffInHours($now);
        $settings = app(SettingService::class);

        if ($this->high_priority) {
            return match (true) {
                $hoursPending >= $settings->getInt('sla.priority_overdue_hours', 8) => ServiceCaseSlaStatus::Overdue,
                $hoursPending >= $settings->getInt('sla.priority_warning_hours', 4) => ServiceCaseSlaStatus::Warning,
                default => ServiceCaseSlaStatus::WithinSla,
            };
        }

        return match (true) {
            $hoursPending >= $settings->getInt('sla.normal_overdue_hours', 48) => ServiceCaseSlaStatus::Overdue,
            $hoursPending >= $settings->getInt('sla.normal_warning_hours', 24) => ServiceCaseSlaStatus::Warning,
            default => ServiceCaseSlaStatus::WithinSla,
        };
    }

    public function slaSortRank(?Carbon $now = null): int
    {
        if (! $this->isPendingAdmin()) {
            return 6;
        }

        $status = $this->slaStatus($now);

        return match (true) {
            $this->high_priority && $status === ServiceCaseSlaStatus::Overdue => 1,
            $this->high_priority && $status === ServiceCaseSlaStatus::Warning => 2,
            ! $this->high_priority && $status === ServiceCaseSlaStatus::Overdue => 3,
            ! $this->high_priority && $status === ServiceCaseSlaStatus::Warning => 4,
            default => 5,
        };
    }

    public function slaTooltipHtml(?Carbon $now = null): string
    {
        $now ??= now();
        $status = $this->slaStatus($now);

        $lines = [
            'Created:',
            AppDateFormatter::datetime($this->created_at) ?? '—',
            '',
            'Pending:',
            Order::formatDurationBetween($this->created_at, $now) ?? '—',
            '',
            'SLA:',
            $status->label(),
        ];

        return collect($lines)
            ->map(fn (string $line): string => e($line))
            ->implode('<br>');
    }
}
