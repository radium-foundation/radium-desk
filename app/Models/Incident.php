<?php

namespace App\Models;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Enums\ServiceCaseSlaStatus;
use App\Models\ServiceCaseCloseException;
use App\Services\SerialValidation\SerialPlaceholderService;
use App\Services\Operations\BusinessHoursSlaCalculator;
use App\Services\SettingService;
use App\Support\AppDateFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Incident extends Model
{
    use SoftDeletes;

    protected $fillable = [
        'order_id',
        'inquiry_origin_order_id',
        'reference_no',
        'category',
        'source',
        'title',
        'description',
        'status',
        'high_priority',
        'recovery_phone',
        'missed_call_attempt_count',
        'last_missed_at',
        'assigned_to_user_id',
        'automation_pending_until',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => IncidentStatus::class,
            'source' => IncidentSource::class,
            'high_priority' => 'boolean',
            'last_missed_at' => 'datetime',
            'automation_pending_until' => 'datetime',
        ];
    }

    public function isAutomationPending(): bool
    {
        return $this->assigned_to_user_id === null
            && $this->automation_pending_until !== null
            && $this->automation_pending_until->isFuture();
    }

    public function scopeAutomationGraceExpired(Builder $query): void
    {
        $query->whereNull('assigned_to_user_id')
            ->whereNotNull('automation_pending_until')
            ->where('automation_pending_until', '<=', now());
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function inquiryOriginOrder(): BelongsTo
    {
        return $this->belongsTo(Order::class, 'inquiry_origin_order_id');
    }

    public function isOnInquiryOrder(): bool
    {
        $this->loadMissing('order');

        return $this->order?->isInquiryOrder() ?? false;
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

    public function closeExceptions(): HasMany
    {
        return $this->hasMany(ServiceCaseCloseException::class);
    }

    public function waitingStates(): HasMany
    {
        return $this->hasMany(IncidentWaitingState::class);
    }

    public function activeWaitingState(): HasOne
    {
        return $this->hasOne(IncidentWaitingState::class)->active()->latest('started_at');
    }

    public function hasSlaPaused(): bool
    {
        $waitingState = $this->relationLoaded('activeWaitingState')
            ? $this->activeWaitingState
            : $this->activeWaitingState()->first();

        return $waitingState !== null && $waitingState->sla_paused;
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

    public function supportAppointments(): HasMany
    {
        return $this->hasMany(SupportAppointment::class);
    }

    public function bonvoiceCallLinks(): HasMany
    {
        return $this->hasMany(IncidentBonvoiceCallLink::class);
    }

    public function hasActiveSupportAppointment(): bool
    {
        if ($this->relationLoaded('supportAppointments')) {
            return $this->supportAppointments->contains(
                fn (SupportAppointment $appointment): bool => $appointment->isScheduled(),
            );
        }

        return $this->supportAppointments()->scheduled()->exists();
    }

    public function getDisplayReferenceAttribute(): string
    {
        if ($this->reference_no === null || $this->reference_no === '') {
            return '';
        }

        if (preg_match('/^SC-?(\d+)$/i', $this->reference_no, $matches) === 1) {
            return self::formatDisplayReference((int) $matches[1]);
        }

        return $this->reference_no;
    }

    public static function formatDisplayReference(int $sequence): string
    {
        return 'SC'.str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
    }

    /**
     * All stored reference forms that resolve to the same service case sequence.
     *
     * @return list<string>
     */
    public static function referenceMatchVariants(int $sequence): array
    {
        $padded = str_pad((string) $sequence, 5, '0', STR_PAD_LEFT);
        $unpadded = (string) $sequence;

        return [
            'SC'.$padded,
            'SC-'.$padded,
            'SC'.$unpadded,
            'SC-'.$unpadded,
            $padded,
            $unpadded,
        ];
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
            $variants = self::referenceMatchVariants($sequence);

            $query->where(function (Builder $builder) use ($term, $variants) {
                $builder->whereIn('reference_no', $variants)
                    ->orWhere('reference_no', 'like', '%'.$term.'%');
            });

            return;
        }

        $query->where('reference_no', 'like', '%'.$term.'%');
    }

    public function isActive(): bool
    {
        return in_array($this->status, IncidentStatus::operationallyActive(), true);
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
        if ($this->hasSlaPaused()) {
            return ServiceCaseSlaStatus::Paused;
        }

        if (! $this->isPendingAdmin() || $this->created_at === null) {
            return ServiceCaseSlaStatus::WithinSla;
        }

        $now ??= now();
        $slaCalculator = app(BusinessHoursSlaCalculator::class);
        $hoursPending = $slaCalculator->isEnabled()
            ? $slaCalculator->elapsedBusinessHours($this, $now)
            : (int) $this->created_at->diffInHours($now);
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

        if ($this->hasSlaPaused()) {
            return 7;
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

    /**
     * Business priority tier for dashboard sorting (lower = higher priority).
     *
     * 1: Real RD/RDE order with serial and scheduled appointment
     * 2: Real RD/RDE order with serial
     * 3: Real RD/RDE order with missing serial
     * 4: INQ enquiry with identifiable existing customer
     * 5: Unknown INQ enquiry
     */
    public function servicePriorityTier(): int
    {
        $order = $this->order;

        if ($order === null) {
            return 5;
        }

        if ($order->isInquiryOrder()) {
            return $this->isIdentifiableInquiryCustomer() ? 4 : 5;
        }

        if ($this->hasServiceSerial()) {
            return $this->hasActiveSupportAppointment() ? 1 : 2;
        }

        return 3;
    }

    /**
     * Combined SLA + business priority rank for dashboard and queue position.
     * SLA bands (1–7) always dominate; business tier breaks ties within a band.
     */
    public function dashboardSortRank(?Carbon $now = null): int
    {
        return ($this->slaSortRank($now) * 10) + $this->servicePriorityTier();
    }

    public function isIdentifiableInquiryCustomer(): bool
    {
        $order = $this->order;

        if ($order === null || ! $order->isInquiryOrder()) {
            return false;
        }

        if (filled($order->customer_id)) {
            return true;
        }

        $phone = trim((string) ($order->customer_phone ?? $this->recovery_phone ?? ''));

        if ($phone === '') {
            return false;
        }

        static $existingCustomerPhones = [];

        if (array_key_exists($phone, $existingCustomerPhones)) {
            return $existingCustomerPhones[$phone];
        }

        $existingCustomerPhones[$phone] = Order::query()
            ->where('customer_phone', $phone)
            ->where('order_id', 'not like', 'INQ-%')
            ->exists();

        return $existingCustomerPhones[$phone];
    }

    private function hasServiceSerial(): bool
    {
        $order = $this->order;

        if ($order === null) {
            return false;
        }

        $serial = trim((string) $order->serial_number);

        if ($serial === '') {
            return false;
        }

        return ! app(SerialPlaceholderService::class)->isPlaceholder($serial);
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
