<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class InventorySaleSerial extends Model
{
    protected $fillable = [
        'sale_id',
        'sale_line_id',
        'serial_id',
    ];

    public function sale(): BelongsTo
    {
        return $this->belongsTo(InventorySale::class, 'sale_id');
    }

    public function line(): BelongsTo
    {
        return $this->belongsTo(InventorySaleLine::class, 'sale_line_id');
    }

    public function serial(): BelongsTo
    {
        return $this->belongsTo(InventorySerial::class, 'serial_id');
    }
}
