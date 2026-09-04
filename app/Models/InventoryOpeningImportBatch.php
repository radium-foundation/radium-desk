<?php

namespace App\Models;

use App\Enums\InventoryOpeningImportStatus;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class InventoryOpeningImportBatch extends Model
{
    protected $fillable = [
        'source_checksum',
        'source_filename',
        'stored_path',
        'status',
        'opening_date',
        'sku_created_count',
        'variant_created_count',
        'rows_valid',
        'rows_invalid',
        'rows_applied',
        'summary',
        'actor_user_id',
        'applied_at',
    ];

    protected function casts(): array
    {
        return [
            'status' => InventoryOpeningImportStatus::class,
            'opening_date' => 'date',
            'summary' => 'array',
            'applied_at' => 'datetime',
        ];
    }

    public function actor(): BelongsTo
    {
        return $this->belongsTo(User::class, 'actor_user_id');
    }

    public function rows(): HasMany
    {
        return $this->hasMany(InventoryOpeningImportRow::class, 'batch_id');
    }
}
