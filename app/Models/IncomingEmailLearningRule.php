<?php

namespace App\Models;

use App\Enums\IncomingEmailLearningDecisionType;
use App\Enums\IncomingEmailLearningRuleType;
use App\Enums\IraMemoryCreatedFrom;
use App\Enums\IraMemoryDecisionKind;
use App\Enums\IraMemoryPatternKind;
use App\Enums\IraMemorySource;
use App\Enums\IraMemoryStatus;
use App\Enums\IraMemoryType;
use App\Models\Builders\IncomingEmailLearningRuleBuilder;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Compatibility facade over ira_memories for Email Learning Rules.
 *
 * Existing Learning Center / processor APIs continue to use this model.
 * Canonical Memory access should use {@see IraMemory}.
 */
class IncomingEmailLearningRule extends IraMemory
{
    /**
     * @var list<string>
     */
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
        // Legacy Learning Rule attribute names (mapped via accessors).
        'rule_type',
        'match_value',
        'decision_type',
        'created_by',
        'enabled',
    ];

    public function newEloquentBuilder($query): IncomingEmailLearningRuleBuilder
    {
        return new IncomingEmailLearningRuleBuilder($query);
    }

    protected static function booted(): void
    {
        parent::booted();

        static::creating(function (self $rule): void {
            if ($rule->source === null) {
                $rule->source = IraMemorySource::Email;
            }

            if ($rule->created_from === null) {
                $rule->created_from = IraMemoryCreatedFrom::LearningCenter;
            }

            if ($rule->status === null) {
                $rule->status = IraMemoryStatus::Active;
            }

            if (
                $rule->memory_type === null
                && $rule->decision_kind !== null
            ) {
                $rule->memory_type = IraMemoryType::fromDecisionKind($rule->decision_kind);
            }
        });
    }

    protected function casts(): array
    {
        return array_merge(parent::casts(), [
            // Keep legacy enum surfaces available when attributes are read via accessors.
        ]);
    }

    /**
     * @return Attribute<IncomingEmailLearningRuleType|null, IncomingEmailLearningRuleType|IraMemoryPatternKind|string|null>
     */
    protected function ruleType(): Attribute
    {
        return Attribute::make(
            get: function (): ?IncomingEmailLearningRuleType {
                $value = $this->attributes['pattern_kind'] ?? null;

                return $value !== null
                    ? IncomingEmailLearningRuleType::from((string) $value)
                    : null;
            },
            set: function (IncomingEmailLearningRuleType|IraMemoryPatternKind|string|null $value): array {
                if ($value === null) {
                    return ['pattern_kind' => null];
                }

                return [
                    'pattern_kind' => $value instanceof \BackedEnum ? $value->value : $value,
                ];
            },
        );
    }

    /**
     * @return Attribute<string|null, string|null>
     */
    protected function matchValue(): Attribute
    {
        return Attribute::make(
            get: fn (): ?string => $this->attributes['pattern_value'] ?? null,
            set: fn (?string $value): array => ['pattern_value' => $value],
        );
    }

    /**
     * @return Attribute<IncomingEmailLearningDecisionType|null, IncomingEmailLearningDecisionType|IraMemoryDecisionKind|string|null>
     */
    protected function decisionType(): Attribute
    {
        return Attribute::make(
            get: function (): ?IncomingEmailLearningDecisionType {
                $value = $this->attributes['decision_kind'] ?? null;

                return $value !== null
                    ? IncomingEmailLearningDecisionType::from((string) $value)
                    : null;
            },
            set: function (IncomingEmailLearningDecisionType|IraMemoryDecisionKind|string|null $value): array {
                if ($value === null) {
                    return ['decision_kind' => null];
                }

                $raw = $value instanceof \BackedEnum ? $value->value : $value;

                return [
                    'decision_kind' => $raw,
                    'memory_type' => IraMemoryType::fromDecisionKind($raw)->value,
                ];
            },
        );
    }

    /**
     * @return Attribute<int|null, int|null>
     */
    protected function createdBy(): Attribute
    {
        return Attribute::make(
            get: fn (): ?int => isset($this->attributes['created_by_user_id'])
                ? (int) $this->attributes['created_by_user_id']
                : null,
            set: fn (int|string|null $value): array => [
                'created_by_user_id' => $value !== null ? (int) $value : null,
            ],
        );
    }

    /**
     * @return Attribute<bool, bool>
     */
    protected function enabled(): Attribute
    {
        return Attribute::make(
            get: fn (): bool => ($this->attributes['status'] ?? null) === IraMemoryStatus::Active->value,
            set: function (bool|int|string $value): array {
                $enabled = filter_var($value, FILTER_VALIDATE_BOOLEAN);

                return [
                    'status' => $enabled
                        ? IraMemoryStatus::Active->value
                        : IraMemoryStatus::Disabled->value,
                    'uniqueness_guard' => self::UNIQUENESS_GUARD_LIVE,
                ];
            },
        );
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by_user_id');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('status', IraMemoryStatus::Active->value);
    }
}
