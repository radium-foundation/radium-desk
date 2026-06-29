<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RemarkMention extends Model
{
    protected $fillable = [
        'remark_id',
        'user_id',
    ];

    public function remark(): BelongsTo
    {
        return $this->belongsTo(Remark::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
