<?php

namespace App\Models\Commerce;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommerceCatalogBrand extends Model
{
    protected $fillable = [
        'commerce_site_id',
        'external_slug',
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
     * @return BelongsTo<CommerceSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(CommerceSite::class, 'commerce_site_id');
    }

    /**
     * @return HasMany<CommerceCatalogModel, $this>
     */
    public function models(): HasMany
    {
        return $this->hasMany(CommerceCatalogModel::class, 'brand_id');
    }
}
