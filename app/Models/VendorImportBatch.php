<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;

class VendorImportBatch extends Model
{
    protected $fillable = [
        'batch_reference',
        'source_database',
        'source_table',
        'records_attempted',
        'records_imported',
        'records_skipped',
        'records_conflicted',
        'imported_by_user_id',
        'summary',
    ];

    protected function casts(): array
    {
        return [
            'summary' => 'array',
        ];
    }

    public function importedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'imported_by_user_id');
    }

    public function conflicts(): HasMany
    {
        return $this->hasMany(VendorImportConflict::class);
    }
}
