<?php

namespace App\Models;

use App\Enums\IraInsightFeedbackResponse;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class IraInsightFeedback extends Model
{
    protected $table = 'ira_insight_feedback';

    protected $fillable = [
        'insight_key',
        'insight_type',
        'insight_payload',
        'response',
        'user_id',
        'responded_at',
    ];

    protected function casts(): array
    {
        return [
            'insight_payload' => 'array',
            'response' => IraInsightFeedbackResponse::class,
            'responded_at' => 'datetime',
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
