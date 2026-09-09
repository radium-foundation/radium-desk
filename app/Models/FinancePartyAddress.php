<?php

namespace App\Models;

use App\Enums\FinancePartyAddressKind;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FinancePartyAddress extends Model
{
    protected $table = 'finance_party_addresses';

    protected $fillable = [
        'party_id',
        'label',
        'kind',
        'line1',
        'line2',
        'city',
        'district',
        'state',
        'state_code',
        'postal_code',
        'country',
        'landmark',
        'is_default_billing',
        'is_default_shipping',
        'is_active',
    ];

    protected function casts(): array
    {
        return [
            'kind' => FinancePartyAddressKind::class,
            'is_default_billing' => 'boolean',
            'is_default_shipping' => 'boolean',
            'is_active' => 'boolean',
        ];
    }

    public function party(): BelongsTo
    {
        return $this->belongsTo(FinanceParty::class, 'party_id');
    }

    /**
     * @return array{label:?string,kind:string,line1:string,line2:?string,city:?string,district:?string,state:string,state_code:?string,postal_code:string,country:string,landmark:?string}
     */
    public function snapshotLines(): array
    {
        return [
            'label' => $this->label,
            'kind' => $this->kind->value,
            'line1' => $this->line1,
            'line2' => $this->line2,
            'city' => $this->city,
            'district' => $this->district,
            'state' => $this->state,
            'state_code' => $this->state_code,
            'postal_code' => $this->postal_code,
            'country' => $this->country,
            'landmark' => $this->landmark,
        ];
    }
}
