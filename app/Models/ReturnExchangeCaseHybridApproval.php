<?php

namespace App\Models;

use App\Enums\ReturnExchangeHybridApprovalDecision;
use App\Enums\ReturnExchangeHybridApprovalType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnExchangeCaseHybridApproval extends Model
{
    protected $fillable = [
        'return_exchange_case_id',
        'approval_type',
        'decision',
        'reason',
        'decided_by',
        'decided_at',
        'idempotency_key',
    ];

    protected function casts(): array
    {
        return [
            'approval_type' => ReturnExchangeHybridApprovalType::class,
            'decision' => ReturnExchangeHybridApprovalDecision::class,
            'decided_at' => 'datetime',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ReturnExchangeCase::class, 'return_exchange_case_id');
    }

    public function decider(): BelongsTo
    {
        return $this->belongsTo(User::class, 'decided_by');
    }
}
