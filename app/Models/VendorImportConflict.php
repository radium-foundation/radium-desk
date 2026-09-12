<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class VendorImportConflict extends Model
{
    protected $fillable = [
        'vendor_import_batch_id',
        'legacy_supplier_id',
        'existing_vendor_id',
        'conflict_reason',
        'legacy_payload',
        'resolution_status',
    ];

    protected function casts(): array
    {
        return [
            'legacy_payload' => 'array',
        ];
    }

    public function batch(): BelongsTo
    {
        return $this->belongsTo(VendorImportBatch::class, 'vendor_import_batch_id');
    }

    public function existingVendor(): BelongsTo
    {
        return $this->belongsTo(Vendor::class, 'existing_vendor_id');
    }
}
