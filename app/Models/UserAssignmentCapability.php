<?php

namespace App\Models;

use App\Enums\Assignment\AssignmentCapability;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class UserAssignmentCapability extends Model
{
    protected $fillable = [
        'user_id',
        'capability',
    ];

    protected function casts(): array
    {
        return [
            'capability' => AssignmentCapability::class,
        ];
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
