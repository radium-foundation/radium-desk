<?php

namespace App\Models;

use App\Enums\IncidentStatus;
use App\Enums\OrderCompletionStatus;
use App\Enums\OrderStatus;
use App\Enums\RadiumBoxEnrichmentSyncStatus;
use App\Support\AppDateFormatter;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Carbon;
use Illuminate\Support\Str;

class Order extends Model
{
    use SoftDeletes;

    protected $attributes = [
        'radiumbox_sync_status' => 'NOT_SYNCED',
        'radiumbox_sync_attempts' => 0,
    ];

    protected $fillable = [
        'order_id',
        'customer_id',
        'serial_number',
        'serial_entered_at',
        'serial_entered_by_user_id',
        'missing_serial_automation_status',
        'missing_serial_first_requested_at',
        'missing_serial_last_contacted_at',
        'missing_serial_escalated_at',
        'radiumbox_sync_status',
        'radiumbox_last_sync_at',
        'radiumbox_last_sync_error',
        'radiumbox_sync_attempts',
        'product_name',
        'device_model',
        'device_model_id',
        'device_model_assigned_at',
        'device_model_assigned_by_user_id',
        'transaction_id',
        'cashfree_payment_id',
        'payment_amount',
        'payment_method',
        'payment_date',
        'bank_reference',
        'gateway_order_id',
        'gateway_payment_id',
        'completed_at',
        'transaction_assigned_by',
        'customer_name',
        'customer_email',
        'customer_phone',
        'gst_number',
        'invoice_number',
        'purchase_year',
        'service_history',
        'amc_status',
        'amc_year',
        'amc_details',
        'legacy_order_status',
        'legacy_source',
        'legacy_imported_at',
        'legacy_imported_by_user_id',
        'status',
        'created_by',
        'updated_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => OrderStatus::class,
            'completed_at' => 'datetime',
            'serial_entered_at' => 'datetime',
            'missing_serial_first_requested_at' => 'datetime',
            'missing_serial_last_contacted_at' => 'datetime',
            'missing_serial_escalated_at' => 'datetime',
            'radiumbox_sync_status' => RadiumBoxEnrichmentSyncStatus::class,
            'radiumbox_last_sync_at' => 'datetime',
            'radiumbox_sync_attempts' => 'integer',
            'device_model_assigned_at' => 'datetime',
            'payment_date' => 'datetime',
            'payment_amount' => 'decimal:2',
            'service_history' => 'array',
            'amc_details' => 'array',
            'legacy_imported_at' => 'datetime',
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

    public static function isInquiryOrderId(?string $orderId): bool
    {
        if ($orderId === null) {
            return false;
        }

        return str_starts_with(strtoupper(trim($orderId)), 'INQ-');
    }

    public static function isHardwareOrderId(?string $orderId): bool
    {
        if ($orderId === null || trim($orderId) === '') {
            return false;
        }

        $prefix = strtoupper((string) config('operations.hardware_order_prefix', 'RDE'));

        return str_starts_with(strtoupper(trim($orderId)), $prefix);
    }

    public static function isProductOrderId(?string $orderId): bool
    {
        return self::isHardwareOrderId($orderId);
    }

    public function isProductOrder(): bool
    {
        return self::isProductOrderId($this->order_id);
    }

    public static function inquiryOrderIdFromReference(string $reference): string
    {
        $normalized = strtoupper(trim($reference));

        return 'INQ-'.$normalized;
    }

    public function isCashfreeVerified(): bool
    {
        return filled($this->cashfree_payment_id);
    }

    public function isSerialLocked(): bool
    {
        return filled($this->serial_number);
    }

    public function isMissingSerialNumber(): bool
    {
        return ! filled($this->serial_number);
    }

    public function isMissingDeviceModel(): bool
    {
        if ($this->hasDeviceModelAssigned()) {
            return false;
        }

        return ! filled($this->device_model);
    }

    public function isMissingDeviceEnrichment(): bool
    {
        return $this->isMissingSerialNumber() || $this->isMissingDeviceModel();
    }

    public function scopeCashfreeVerified(Builder $query): void
    {
        $query->whereNotNull('cashfree_payment_id')
            ->where('cashfree_payment_id', '!=', '');
    }

    public function scopeMissingDeviceEnrichment(Builder $query): void
    {
        $query->where(function (Builder $builder): void {
            $builder->where(function (Builder $serialQuery): void {
                $serialQuery->whereNull('serial_number')
                    ->orWhere('serial_number', '');
            })->orWhere(function (Builder $deviceModelQuery): void {
                $deviceModelQuery
                    ->where(function (Builder $textQuery): void {
                        $textQuery->whereNull('device_model')
                            ->orWhere('device_model', '');
                    })
                    ->whereNull('device_model_id');
            });
        });
    }

    /**
     * Orders with no usable serial number (null, empty, or whitespace-only).
     */
    public function scopeWhereSerialMissing(Builder $query): void
    {
        $query->where(function (Builder $builder): void {
            $builder->whereNull('serial_number')
                ->orWhere('serial_number', '')
                ->orWhereRaw("TRIM(serial_number) = ''");
        });
    }

    public function hasDeviceModelAssigned(): bool
    {
        return $this->device_model_id !== null;
    }

    public function displayDeviceModelName(): ?string
    {
        if ($this->device_model_id !== null && self::supportsDeviceModelMaster()) {
            if ($this->relationLoaded('deviceModel') && $this->deviceModel !== null) {
                return $this->deviceModel->name;
            }

            return $this->deviceModel?->name;
        }

        return filled($this->product_name)
            ? $this->product_name
            : (filled($this->device_model) ? $this->device_model : null);
    }

    public static function supportsDeviceModelMaster(): bool
    {
        static $supported = null;

        $supported ??= Schema::hasTable('device_models')
            && Schema::hasColumn('orders', 'device_model_id');

        return $supported;
    }

    public static function supportsRadiumBoxSyncTracking(): bool
    {
        static $supported = null;

        $supported ??= Schema::hasColumn('orders', 'radiumbox_sync_status');

        return $supported;
    }

    public static function supportsMissingSerialAutomationTracking(): bool
    {
        static $supported = null;

        $supported ??= Schema::hasColumn('orders', 'missing_serial_automation_status');

        return $supported;
    }

    public function completionTooltipHtml(Carbon $loggedAt): string
    {
        if ($this->completionStatus() === OrderCompletionStatus::PendingAdmin) {
            $lines = [
                'Waiting for Service Reference',
                '',
                'Created:',
                AppDateFormatter::datetime($loggedAt) ?? '—',
                '',
                'Pending for:',
                self::formatDurationBetween($loggedAt) ?? '—',
            ];
        } else {
            $lines = [
                'Service Reference: '.($this->transaction_id ?: '—'),
                '',
                'Completed:',
                AppDateFormatter::datetime($this->completed_at) ?? '—',
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

    public static function formatCompactDurationBetween(?Carbon $from, ?Carbon $to = null): ?string
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
            $parts[] = $diff->d.'d';

            if ($diff->h > 0) {
                $parts[] = $diff->h.'h';
            }
        } elseif ($diff->h > 0) {
            $parts[] = $diff->h.'h';

            if ($diff->i > 0) {
                $parts[] = $diff->i.'m';
            }
        } elseif ($diff->i > 0) {
            $parts[] = $diff->i.'m';
        } else {
            $parts[] = '<1m';
        }

        return implode(' ', $parts);
    }

    public function transactionAssignTooltipHtml(): string
    {
        $lines = [
            'Completed',
            AppDateFormatter::datetime($this->completed_at) ?? '—',
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

    public function serialEnterer(): BelongsTo
    {
        return $this->belongsTo(User::class, 'serial_entered_by_user_id');
    }

    public function deviceModel(): BelongsTo
    {
        return $this->belongsTo(DeviceModel::class);
    }

    public function deviceModelAssigner(): BelongsTo
    {
        return $this->belongsTo(User::class, 'device_model_assigned_by_user_id');
    }

    public function legacyImporter(): BelongsTo
    {
        return $this->belongsTo(User::class, 'legacy_imported_by_user_id');
    }

    public function incidents(): HasMany
    {
        return $this->hasMany(Incident::class);
    }

    public function activeIncident(): ?Incident
    {
        if ($this->relationLoaded('incidents')) {
            return $this->incidents
                ->first(fn (Incident $incident): bool => $incident->isActive());
        }

        return $this->incidents()
            ->with('assignee')
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->latest()
            ->first();
    }

    public function latestIncident(): ?Incident
    {
        if ($this->relationLoaded('incidents')) {
            return $this->incidents->first();
        }

        return $this->incidents()->latest()->first();
    }

    public function openIncidentsCount(): int
    {
        if ($this->relationLoaded('incidents')) {
            return $this->incidents
                ->filter(fn (Incident $incident): bool => $incident->isActive())
                ->count();
        }

        return $this->incidents()
            ->whereIn('status', IncidentStatus::operationallyActive())
            ->count();
    }

    public function refundRequests(): HasMany
    {
        return $this->hasMany(RefundRequest::class);
    }

    public function remarks(): MorphMany
    {
        return $this->morphMany(Remark::class, 'remarkable');
    }

    public function isLegacyImported(): bool
    {
        return filled($this->legacy_source);
    }

    public function legacyImportTooltipTitle(): ?string
    {
        if (! $this->isLegacyImported()) {
            return null;
        }

        $agent = $this->legacyImporter?->firstName()
            ?? $this->legacyImporter?->name
            ?? 'Unknown';

        $date = AppDateFormatter::datetime($this->legacy_imported_at) ?? 'Unknown date';

        return "Legacy imported order • Imported by {$agent} • {$date}";
    }

    public function legacyImportMetadataLine(): ?string
    {
        if (! $this->isLegacyImported()) {
            return null;
        }

        $agent = $this->legacyImporter?->firstName()
            ?? $this->legacyImporter?->name
            ?? 'Unknown';

        return "Imported from legacy system by {$agent}";
    }
}
