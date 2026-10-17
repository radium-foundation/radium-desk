<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ReturnExchangeCaseLine extends Model
{
    protected $fillable = [
        'return_exchange_case_id',
        'line_no',
        'description',
        'inventory_product_id',
        'inventory_sale_line_id',
        'qty',
        'serial_number',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'qty' => 'integer',
        ];
    }

    public function case(): BelongsTo
    {
        return $this->belongsTo(ReturnExchangeCase::class, 'return_exchange_case_id');
    }

    public function product(): BelongsTo
    {
        return $this->belongsTo(InventoryProduct::class, 'inventory_product_id');
    }

    public function saleLine(): BelongsTo
    {
        return $this->belongsTo(InventorySaleLine::class, 'inventory_sale_line_id');
    }
}
