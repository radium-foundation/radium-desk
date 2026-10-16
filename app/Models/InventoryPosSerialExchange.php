<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventoryPosSerialExchange extends Model
{
    protected $fillable = [
        'idempotency_key',
        'sale_id',
        'sale_serial_id',
        'from_serial_id',
        'to_serial_id',
        'reason',
        'actor_user_id',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'sale_id');
    }

    public function saleSerial(): BelongsTo
    {
        return $this->belongsTo(InventorySaleSerial::class, 'sale_serial_id');
    }

    public function fromSerial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'from_serial_id');
    }

    public function toSerial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'to_serial_id');
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }
}
