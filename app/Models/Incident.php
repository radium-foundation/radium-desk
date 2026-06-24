<?php

namespace App\Models;

use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphMany;
use Illuminate\Database\Eloquent\SoftDeletes;

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
}
