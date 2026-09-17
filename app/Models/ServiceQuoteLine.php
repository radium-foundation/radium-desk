<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceQuoteLine extends Model
{
    protected $fillable = [
        'quote_id',
        'line_no',
        'service_item_id',
        'description',
        'sac_code',
        'gst_rate',
        'qty',
        'unit_price_ex_gst',
        'discount',
        'taxable_value',
        'tax_total',
        'line_total',
    ];

    protected function casts(): array
    {
        return [
            'line_no' => 'integer',
            'gst_rate' => 'decimal:2',
            'qty' => 'integer',
            'unit_price_ex_gst' => 'decimal:2',
            'discount' => 'decimal:2',
            'taxable_value' => 'decimal:2',
            'tax_total' => 'decimal:2',
            'line_total' => 'decimal:2',
        ];
    }

    public function quote(): BelongsTo
    {
        return $this->belongsTo(ServiceQuote::class, 'quote_id');
    }

    public function serviceItem(): BelongsTo
    {
        return $this->belongsTo(ServiceItem::class, 'service_item_id');
    }
}
