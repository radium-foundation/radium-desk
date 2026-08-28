<?php

namespace App\Models\Commerce;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class CommerceSiteApiKey extends Model
{
    protected $fillable = [
        'commerce_site_id',
        'name',
        'key_hash',
        'key_prefix',
        'is_active',
        'last_used_at',
        'expires_at',
    ];

    protected function casts(): array
    {
        return [
            'is_active' => 'boolean',
            'last_used_at' => 'datetime',
            'expires_at' => 'datetime',
        ];
    }

    /**
     * @return BelongsTo<CommerceSite, $this>
     */
    public function site(): BelongsTo
    {
        return $this->belongsTo(CommerceSite::class, 'commerce_site_id');
    }
}
