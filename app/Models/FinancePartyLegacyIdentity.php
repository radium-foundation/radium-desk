<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

/**
 * Empty-by-design mapping table for a later Admin import.
 * Do not write rows until a dedicated migration gate.
 */
class FinancePartyLegacyIdentity extends Model
{
    protected $table = 'finance_party_legacy_identities';

    protected $fillable = [
        'party_id',
        'source',
        'external_id',
    ];

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinanceParty::class, 'party_id');
    }
}
