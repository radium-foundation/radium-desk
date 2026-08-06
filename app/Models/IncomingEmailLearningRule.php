<?php

namespace App\Models;

use App\Enums\IncomingEmailLearningDecisionType;
use App\Enums\IncomingEmailLearningRuleType;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IncomingEmailLearningRule extends Model
{
    protected $fillable = [
        'rule_type',
        'match_value',
        'decision_type',
        'decision_value',
        'confidence',
        'created_by',
        'times_used',
        'last_used_at',
        'enabled',
    ];

    protected function casts(): array
    {
        return [
            'rule_type' => IncomingEmailLearningRuleType::class,
            'decision_type' => IncomingEmailLearningDecisionType::class,
            'confidence' => 'integer',
            'times_used' => 'integer',
            'last_used_at' => 'datetime',
            'enabled' => 'boolean',
        ];
    }

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeEnabled(Builder $query): Builder
    {
        return $query->where('enabled', true);
    }

    public function recordUsage(): void
    {
        $this->forceFill([
            'times_used' => $this->times_used + 1,
            'last_used_at' => now(),
        ])->save();
    }
}
