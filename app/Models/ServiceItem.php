<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class ServiceItem extends Model
{
    protected $fillable = [
        'category_id',
        'code',
        'name',
        'description',
        'duration_label',
        'sac_code',
        'gst_rate',
        'price_ex_gst',
        'price_incl_gst',
        'parent_device_model_id',
        'legacy_admin_product_id',
        'metadata',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'gst_rate' => 'decimal:2',
            'price_ex_gst' => 'decimal:2',
            'price_incl_gst' => 'decimal:2',
            'metadata' => 'array',
            'is_active' => 'boolean',
        ];
    }

    public function category(): BelongsTo
    {
        return $this->belongsTo(ServiceCategory::class, 'category_id');
    }

    public function parentDeviceModel(): BelongsTo
    {
        return $this->belongsTo(DeviceModel::class, 'parent_device_model_id');
    }
}
