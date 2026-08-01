<?php

namespace App\Models;

use App\Enums\RecognitionDayContext;
use App\Enums\RecognitionRecommendation;
use App\Enums\RecognitionReviewStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class WorkRecognitionReview extends Model
{
    protected $table = 'workforce_recognition_reviews';

    protected $fillable = [
        'user_id',
        'work_date',
        'day_context',
        'status',
        'login_seconds',
        'productive_seconds',
        'evidence_snapshot',
        'ira_score',
        'ira_recommendation',
        'ira_rationale',
        'decision',
        'decision_reason',
        'decided_by',
        'decided_at',
        'department_pack',
        'source_version',
    ];

    protected function casts(): array
    {
        return [
            'work_date' => 'date',
            'day_context' => RecognitionDayContext::class,
            'status' => RecognitionReviewStatus::class,
            'evidence_snapshot' => 'array',
            'ira_score' => 'decimal:2',
            'ira_recommendation' => RecognitionRecommendation::class,
            'decision' => RecognitionRecommendation::class,
            'decided_at' => 'datetime',
            'login_seconds' => 'integer',
            'productive_seconds' => 'integer',
            'source_version' => 'integer',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }

    public function isPending(): bool
    {
        return $this->status === RecognitionReviewStatus::PendingReview;
    }

    public function isApprovedBenefit(): bool
    {
        return $this->status === RecognitionReviewStatus::Decided
            && $this->decision !== null
            && $this->decision->isBenefit();
    }

    public function isNoBenefit(): bool
    {
        return $this->status === RecognitionReviewStatus::Decided
            && $this->decision === RecognitionRecommendation::NoBenefit;
    }
}
