<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class ServiceCategory extends Model
{
    protected $fillable = [
        'code',
        'name',
        'sort_order',
        'is_active',
        'legacy_admin_attribute_id',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_active' => 'boolean',
            'legacy_admin_attribute_id' => 'integer',
        ];
    }

    public function items(): HasMany
    {
        return $this->hasMany(ServiceItem::class, 'category_id');
    }
}
