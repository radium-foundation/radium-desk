<?php

namespace App\Models\Commerce;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;

class CommerceSite extends Model
{
    protected $fillable = [
        'site_id',
        'display_name',
        'allowed_origins',
        'is_enabled',
    ];

    protected function casts(): array
    {
        return [
            'allowed_origins' => 'array',
            'is_enabled' => 'boolean',
        ];
    }

    /**
     * @return HasMany<CommerceSiteApiKey, $this>
     */
    public function apiKeys(): HasMany
    {
        return $this->hasMany(CommerceSiteApiKey::class);
    }

    /**
     * @return HasMany<CommerceCatalogBrand, $this>
     */
    public function catalogBrands(): HasMany
    {
        return $this->hasMany(CommerceCatalogBrand::class, 'commerce_site_id');
    }
}
