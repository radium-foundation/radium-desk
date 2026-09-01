<?php

namespace App\Models;

use App\Enums\InventoryMovementType;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryMovement extends Model
{
    protected $fillable = [
        'occurred_at',
        'type',
        'product_id',
        'variant_id',
        'serial_id',
        'branch_id',
        'from_branch_id',
        'to_branch_id',
        'qty',
        'sale_id',
        'transfer_id',
        'reservation_id',
        'adjustment_id',
        'from_status',
        'to_status',
        'notes',
        'actor_user_id',
    ];

    protected function casts(): array
    {
        return [
            'occurred_at' => 'datetime',
            'type' => InventoryMovementType::class,
            'qty' => 'integer',
        ];
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'product_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'serial_id');
    }

    public function branch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'branch_id');
    }

    public function fromBranch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'from_branch_id');
    }

    public function toBranch(): BelongsTo
    {
        return $this->belongsTo(InventoryBranch::class, 'to_branch_id');
    }

    public function sale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'sale_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
