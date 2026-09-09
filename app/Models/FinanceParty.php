<?php

namespace App\Models;

use App\Enums\FinancePartyKind;
use App\Enums\FinancePartyRoleType;
use App\Services\Finance\Data\PartyDocumentSnapshot;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\Relations\HasOne;

class FinanceParty extends Model
{
    use HasFactory;

    protected $table = 'finance_parties';

    protected $fillable = [
        'code',
        'legal_name',
        'trade_name',
        'kind',
        'phone',
        'email',
        'is_active',
        'notes',
    ];

    protected function casts(): array
    {
        return [
            'kind' => FinancePartyKind::class,
            'is_active' => 'boolean',
        ];
    }

    public function roles(): HasMany
    {
        return $this->hasMany(FinancePartyRole::class, 'party_id');
    }

    public function customerRole(): HasOne
    {
        return $this->hasOne(FinancePartyRole::class, 'party_id')
            ->where('role', FinancePartyRoleType::Customer->value);
    }

    public function vendorRole(): HasOne
    {
        return $this->hasOne(FinancePartyRole::class, 'party_id')
            ->where('role', FinancePartyRoleType::Vendor->value);
    }

    public function addresses(): HasMany
    {
        return $this->hasMany(FinancePartyAddress::class, 'party_id');
    }

    public function contacts(): HasMany
    {
        return $this->hasMany(FinancePartyContact::class, 'party_id');
    }

    public function gstRegistrations(): HasMany
    {
        return $this->hasMany(FinancePartyGstRegistration::class, 'party_id');
    }

    public function vendorBankAccounts(): HasMany
    {
        return $this->hasMany(FinancePartyVendorBankAccount::class, 'party_id');
    }

    public function legacyIdentities(): HasMany
    {
        return $this->hasMany(FinancePartyLegacyIdentity::class, 'party_id');
    }

    public function defaultBillingAddress(): HasOne
    {
        return $this->hasOne(FinancePartyAddress::class, 'party_id')
            ->where('is_default_billing', true)
            ->where('is_active', true);
    }

    public function defaultShippingAddress(): HasOne
    {
        return $this->hasOne(FinancePartyAddress::class, 'party_id')
            ->where('is_default_shipping', true)
            ->where('is_active', true);
    }

    public function primaryContact(): HasOne
    {
        return $this->hasOne(FinancePartyContact::class, 'party_id')
            ->where('is_primary', true)
            ->where('is_active', true);
    }

    public function primaryGstRegistration(): HasOne
    {
        return $this->hasOne(FinancePartyGstRegistration::class, 'party_id')
            ->where('is_primary', true)
            ->where('is_active', true);
    }

    public function isCustomer(): bool
    {
        return $this->roles->contains(fn (FinancePartyRole $role): bool => $role->isCustomer());
    }

    public function isVendor(): bool
    {
        return $this->roles->contains(fn (FinancePartyRole $role): bool => $role->isVendor());
    }

    public function displayName(): string
    {
        return $this->trade_name !== null && trim($this->trade_name) !== ''
            ? $this->trade_name
            : $this->legal_name;
    }

    /**
     * Frozen facts for a future invoice/PO/PI snapshot. Issued documents must
     * copy these values rather than live-joining this master.
     */
    public function documentSnapshot(): PartyDocumentSnapshot
    {
        $this->loadMissing([
            'defaultBillingAddress',
            'defaultShippingAddress',
            'primaryGstRegistration',
            'primaryContact',
        ]);

        $billing = $this->defaultBillingAddress;
        $shipping = $this->defaultShippingAddress ?? $billing;
        $gst = $this->primaryGstRegistration;
        $contact = $this->primaryContact;

        return new PartyDocumentSnapshot(
            partyId: (int) $this->id,
            partyCode: $this->code,
            legalName: $this->legal_name,
            tradeName: $this->trade_name,
            gstin: $gst?->gstin,
            pan: $gst?->pan,
            billingAddress: $billing?->snapshotLines(),
            shippingAddress: $shipping?->snapshotLines(),
            state: $billing?->state ?? $gst?->state,
            postalCode: $billing?->postal_code,
            placeOfSupply: $gst?->state ?? $billing?->state,
            contactName: $contact?->name ?? $this->displayName(),
            contactPhone: $contact?->phone ?? $this->phone,
            contactEmail: $contact?->email ?? $this->email,
        );
    }

    /**
     * @param  Builder<self>  $query
     * @return Builder<self>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->where('is_active', true);
    }
}
