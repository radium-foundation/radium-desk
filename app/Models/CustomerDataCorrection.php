<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CustomerDataCorrection extends Model
{
    protected $fillable = [
        'order_id',
        'corrected_by',
        'status',
        'reason',
    ];

    public function order(): BelongsTo
    {
        return $this->belongsTo(Order::class);
    }

    public function correctedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'corrected_by');
    }

    public function items(): HasMany
    {
        return $this->hasMany(CustomerDataCorrectionItem::class);
    }
}
