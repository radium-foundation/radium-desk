<?php

namespace App\Models;

use App\Enums\ApprovalStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class ApprovalNumber extends Model
{
    use SoftDeletes;

    public const MAX_INCIDENTS = 35;

    protected $fillable = [
        'approval_number',
        'description',
        'status',
        'created_by',
    ];

    protected function casts(): array
    {
        return [
            'status' => ApprovalStatus::class,
        ];
    }

    protected $attributes = [
        'status' => 'open',
    ];

    public function creator(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }

    public function incidents(): BelongsToMany
    {
        return $this->belongsToMany(Incident::class, 'approval_incident')
            ->withPivot(['linked_by', 'created_at']);
    }
}
