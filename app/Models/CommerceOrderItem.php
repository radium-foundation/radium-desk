<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceOrderItem extends Model
{
    protected $fillable = [
        'commerce_order_id',
        'line_no',
        'sku',
        'variant',
        'description',
        'hsn_sac',
        'qty',
        'unit_price',
        'discount',
        'gst_percentage',
        'taxable_value',
        'tax_total',
        'cgst',
        'sgst',
        'igst',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'qty' => 'integer',
            'unit_price' => 'decimal:2',
            'discount' => 'decimal:2',
            'gst_percentage' => 'decimal:2',
            'taxable_value' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'cgst' => 'decimal:2',
            'sgst' => 'decimal:2',
            'igst' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function order(): BelongsTo
    {
        return $this->belongsTo(CommerceOrder::class, 'commerce_order_id');
    }
}
