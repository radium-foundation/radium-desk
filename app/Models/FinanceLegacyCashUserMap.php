<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinanceLegacyCashUserMap extends Model
{
    public const STATUS_MAPPED = 'mapped';

    public const STATUS_UNMAPPED = 'unmapped';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    protected $fillable = [
        'legacy_source',
        'legacy_admin_id',
        'legacy_admin_name',
        'desk_user_id',
        'mapping_status',
        'notes',
    ];

    public function deskUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'desk_user_id');
    }

    public function isMapped(): bool
    {
        return $this->mapping_status === self::STATUS_MAPPED && $this->desk_user_id !== null;
    }
}
