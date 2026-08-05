<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class PerformanceIntelligenceSnapshot extends Model
{
    protected $fillable = [
        'user_id',
        'snapshot_date',
        'version',
        'outcome_score',
        'reach_score',
        'contribution_score',
        'commitment_score',
        'quality_score',
        'composite_score',
        'breakdown',
        'inputs',
        'explanations',
        'feature_flags',
        'calculation_duration_ms',
        'calculated_at',
    ];

    protected function casts(): array
    {
        return [
            'snapshot_date' => 'date',
            'outcome_score' => 'integer',
            'reach_score' => 'integer',
            'contribution_score' => 'integer',
            'commitment_score' => 'integer',
            'quality_score' => 'integer',
            'composite_score' => 'float',
            'breakdown' => 'array',
            'inputs' => 'array',
            'explanations' => 'array',
            'feature_flags' => 'array',
            'calculation_duration_ms' => 'integer',
            'calculated_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
