<?php

namespace App\Models;

use App\Enums\HardwareFulfilmentPackageEvidenceKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class HardwareFulfilmentPackageEvidence extends Model
{
    protected $table = 'hardware_fulfilment_package_evidences';

    protected $fillable = [
        'hardware_fulfilment_id',
        'kind',
        'disk',
        'path',
        'original_filename',
        'mime_type',
        'size_bytes',
        'uploaded_by_user_id',
        'uploaded_at',
    ];

    protected function casts(): array
    {
        return [
            'kind' => HardwareFulfilmentPackageEvidenceKind::class,
            'size_bytes' => 'integer',
            'uploaded_at' => 'datetime',
        ];
    }

    public function fulfilment(): BelongsTo
    {
        return $this->belongsTo(HardwareFulfilment::class, 'hardware_fulfilment_id');
    }

    public function uploadedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'uploaded_by_user_id');
    }
}
