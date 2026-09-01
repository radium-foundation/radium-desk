<?php

namespace App\Models;

use App\Enums\InventoryReservationStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryReservation extends Model
{
    protected $fillable = [
        'reservation_no',
        'branch_id',
        'sale_id',
        'status',
        'notes',
        'created_by',
        'released_at',
        'consumed_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InventoryReservationStatus::class,
            'released_at' => 'datetime',
            'consumed_at' => 'datetime',
        ];
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'branch_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'sale_id');
    }

    public function lines(): HasMany
    {
        return $this->hasMany(InventoryReservationLine::class, 'reservation_id');
    }

    public function createdBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'created_by');
    }
}
