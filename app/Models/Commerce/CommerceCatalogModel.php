<?php

namespace App\Models\Commerce;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommerceCatalogModel extends Model
{
    protected $fillable = [
        'brand_id',
        'display_name',
        'sort_order',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'sort_order' => 'integer',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return BelongsTo<CommerceCatalogBrand, $this>
     */
    public function brand(): BelongsTo
    {
        return $this->belongsTo(CommerceCatalogBrand::class, 'brand_id');
    }

    /**
     * @return HasMany<CommerceCatalogPlan, $this>
     */
    public function plans(): HasMany
    {
        return $this->hasMany(CommerceCatalogPlan::class, 'model_id');
    }
}
