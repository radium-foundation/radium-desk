<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\MorphTo;

class Remark extends Model
{
    protected $fillable = [
        'user_id',
        'remarkable_type',
        'remarkable_id',
        'body',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function remarkable(): MorphTo
    {
        return $this->morphTo();
    }

    public function mentions(): HasMany
    {
        return $this->hasMany(RemarkMention::class);
    }
}
