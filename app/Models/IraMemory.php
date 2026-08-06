<?php

namespace App\Models;

use App\Enums\IraMemoryCreatedFrom;
use App\Enums\IraMemoryDecisionKind;
use App\Enums\IraMemoryPatternKind;
use App\Enums\IraMemorySource;
use App\Enums\IraMemoryStatus;
use App\Enums\IraMemoryType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;

class IraMemory extends Model
{
    use SoftDeletes;

    public const UNIQUENESS_GUARD_LIVE = 'live';

    protected $table = 'ira_memories';

    protected $fillable = [
        'uuid',
        'memory_type',
        'source',
        'pattern_kind',
        'pattern_value',
        'decision_kind',
        'decision_value',
        'reason',
        'confidence',
        'status',
        'times_used',
        'last_used_at',
        'created_by_user_id',
        'created_from',
        'created_from_type',
        'created_from_id',
        'merged_into_memory_id',
        'expires_at',
        'suggestion_origin',
        'approval_status',
        'score',
        'metadata',
        'uniqueness_guard',
    ];

    protected function casts(): array
    {
        return [
            'memory_type' => IraMemoryType::class,
            'source' => IraMemorySource::class,
            'pattern_kind' => IraMemoryPatternKind::class,
            'decision_kind' => IraMemoryDecisionKind::class,
            'status' => IraMemoryStatus::class,
            'created_from' => IraMemoryCreatedFrom::class,
            'confidence' => 'integer',
            'times_used' => 'integer',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
            'score' => 'decimal:4',
            'metadata' => 'array',
        ];
    }

    protected static function booted(): void
    {
        static::creating(function (self $memory): void {
            if (blank($memory->uuid)) {
                $memory->uuid = (string) Str::uuid();
            }

            if ($memory->uniqueness_guard === null || $memory->uniqueness_guard === '') {
                $memory->uniqueness_guard = self::UNIQUENESS_GUARD_LIVE;
            }

            if ($memory->status === null) {
                $memory->status = IraMemoryStatus::Active;
            }

            if ($memory->source === null) {
                $memory->source = IraMemorySource::Email;
            }

            if (
                $memory->memory_type === null
                && $memory->decision_kind !== null
            ) {
                $memory->memory_type = IraMemoryType::fromDecisionKind($memory->decision_kind);
            }
        });
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    public function mergedInto(): BelongsTo
    {
        return $this->belongsTo(self::class, 'merged_into_memory_id');
    }

    public function mergeSources(): HasMany
    {
        return $this->hasMany(self::class, 'merged_into_memory_id');
    }

    public function relationsFrom(): HasMany
    {
        return $this->hasMany(IraMemoryRelation::class, 'memory_id');
    }

    public function relationsTo(): HasMany
    {
        return $this->hasMany(IraMemoryRelation::class, 'related_memory_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('status', IraMemoryStatus::Active->value);
    }

    public function resolveRouteBinding($value, $field = null)
    {
        return $this->where($field ?? $this->getRouteKeyName(), $value)
            ->withTrashed()
            ->firstOrFail();
    }

    public function recordUsage(): void
    {
        $this->forceFill([
            'times_used' => $this->times_used + 1,
            'last_used_at' => now(),
        ])->save();
    }
}
