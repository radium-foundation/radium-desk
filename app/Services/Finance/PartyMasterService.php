<?php

namespace App\Services\Finance;

use App\Enums\FinancePartyAddressKind;
use App\Enums\FinancePartyKind;
use App\Enums\FinancePartyRoleType;
use App\Models\FinanceParty;
use App\Models\FinancePartyAddress;
use App\Models\FinancePartyContact;
use App\Models\FinancePartyGstRegistration;
use App\Models\FinancePartyRole;
use App\Models\FinancePartyVendorBankAccount;
use App\Support\Finance\GstStateCodes;
use App\Support\Finance\IfscCode;
use App\Support\Finance\PanNumber;
use App\Support\Finance\PartyGstin;
use Illuminate\Support\Facades\DB;
use InvalidArgumentException;

final class PartyMasterService
{
    /**
     * @param  array<string, mixed>  $identity
     * @param  list<string>  $roles
     */
    public function create(array $identity, array $roles): FinanceParty
    {
        $this->assertRoles($roles);

        return DB::transaction(function () use ($identity, $roles): FinanceParty {
            $party = FinanceParty::query()->create([
                'code' => 'PTY-TMP-'.uniqid(),
                'legal_name' => trim((string) $identity['legal_name']),
                'trade_name' => $this->nullableString($identity['trade_name'] ?? null),
                'kind' => $identity['kind'] instanceof FinancePartyKind
                    ? $identity['kind']
                    : FinancePartyKind::from((string) $identity['kind']),
                'phone' => $this->nullableString($identity['phone'] ?? null),
                'email' => $this->nullableString($identity['email'] ?? null),
                'is_active' => true,
                'notes' => $this->nullableString($identity['notes'] ?? null),
            ]);

            $party->update(['code' => sprintf('PTY-%06d', $party->id)]);
            $this->syncRoles($party, $roles, $identity);

            return $party->fresh(['roles']) ?? $party;
        });
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  list<string>  $roles
     */
    public function updateIdentity(FinanceParty $party, array $identity, array $roles): FinanceParty
    {
        $this->assertRoles($roles);

        return DB::transaction(function () use ($party, $identity, $roles): FinanceParty {
            $party->update([
                'legal_name' => trim((string) $identity['legal_name']),
                'trade_name' => $this->nullableString($identity['trade_name'] ?? null),
                'kind' => $identity['kind'] instanceof FinancePartyKind
                    ? $identity['kind']
                    : FinancePartyKind::from((string) $identity['kind']),
                'phone' => $this->nullableString($identity['phone'] ?? null),
                'email' => $this->nullableString($identity['email'] ?? null),
                'notes' => $this->nullableString($identity['notes'] ?? null),
            ]);

            $this->syncRoles($party, $roles, $identity);

            return $party->fresh(['roles']) ?? $party;
        });
    }

    public function deactivate(FinanceParty $party): FinanceParty
    {
        $party->update(['is_active' => false]);

        return $party->fresh() ?? $party;
    }

    public function activate(FinanceParty $party): FinanceParty
    {
        $party->update(['is_active' => true]);

        return $party->fresh() ?? $party;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveAddress(FinanceParty $party, array $payload, ?FinancePartyAddress $address = null): FinancePartyAddress
    {
        $state = trim((string) $payload['state']);
        $stateCode = GstStateCodes::codeForName($state) ?? $this->nullableString($payload['state_code'] ?? null);

        $attributes = [
            'label' => $this->nullableString($payload['label'] ?? null),
            'kind' => $payload['kind'] instanceof FinancePartyAddressKind
                ? $payload['kind']
                : FinancePartyAddressKind::from((string) $payload['kind']),
            'line1' => trim((string) $payload['line1']),
            'line2' => $this->nullableString($payload['line2'] ?? null),
            'city' => $this->nullableString($payload['city'] ?? null),
            'district' => $this->nullableString($payload['district'] ?? null),
            'state' => $state,
            'state_code' => $stateCode,
            'postal_code' => trim((string) $payload['postal_code']),
            'country' => $this->nullableString($payload['country'] ?? null) ?? 'India',
            'landmark' => $this->nullableString($payload['landmark'] ?? null),
            'is_default_billing' => (bool) ($payload['is_default_billing'] ?? false),
            'is_default_shipping' => (bool) ($payload['is_default_shipping'] ?? false),
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ];

        return DB::transaction(function () use ($party, $address, $attributes): FinancePartyAddress {
            $row = $address ?? new FinancePartyAddress(['party_id' => $party->id]);
            $row->fill($attributes);
            $row->party_id = $party->id;
            $row->save();

            if ($row->is_default_billing) {
                FinancePartyAddress::query()
                    ->where('party_id', $party->id)
                    ->where('id', '!=', $row->id)
                    ->update(['is_default_billing' => false]);
            }

            if ($row->is_default_shipping) {
                FinancePartyAddress::query()
                    ->where('party_id', $party->id)
                    ->where('id', '!=', $row->id)
                    ->update(['is_default_shipping' => false]);
            }

            return $row->fresh() ?? $row;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveContact(FinanceParty $party, array $payload, ?FinancePartyContact $contact = null): FinancePartyContact
    {
        $attributes = [
            'name' => trim((string) $payload['name']),
            'designation' => $this->nullableString($payload['designation'] ?? null),
            'phone' => $this->nullableString($payload['phone'] ?? null),
            'email' => $this->nullableString($payload['email'] ?? null),
            'is_primary' => (bool) ($payload['is_primary'] ?? false),
            'is_active' => (bool) ($payload['is_active'] ?? true),
        ];

        return DB::transaction(function () use ($party, $contact, $attributes): FinancePartyContact {
            $row = $contact ?? new FinancePartyContact(['party_id' => $party->id]);
            $row->fill($attributes);
            $row->party_id = $party->id;
            $row->save();

            if ($row->is_primary) {
                FinancePartyContact::query()
                    ->where('party_id', $party->id)
                    ->where('id', '!=', $row->id)
                    ->update(['is_primary' => false]);
            } elseif (! FinancePartyContact::query()
                ->where('party_id', $party->id)
                ->where('id', '!=', $row->id)
                ->where('is_primary', true)
                ->exists()) {
                $row->is_primary = true;
                $row->save();
            }

            return $row->fresh() ?? $row;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveGstRegistration(FinanceParty $party, array $payload, ?FinancePartyGstRegistration $registration = null): FinancePartyGstRegistration
    {
        $gstin = PartyGstin::normalize((string) $payload['gstin']);
        if ($gstin === null || ! PartyGstin::isValid($gstin)) {
            throw new InvalidArgumentException('GSTIN is not a valid Indian GST identification number.');
        }

        $stateCode = PartyGstin::stateCode($gstin);
        $stateName = PartyGstin::stateName($gstin);
        if ($stateCode === null || $stateName === null) {
            throw new InvalidArgumentException('GSTIN state code is not a known GST jurisdiction.');
        }

        $pan = PanNumber::normalize($payload['pan'] ?? null) ?? PanNumber::fromGstin($gstin);

        return DB::transaction(function () use ($party, $registration, $gstin, $stateCode, $stateName, $pan, $payload): FinancePartyGstRegistration {
            $row = $registration ?? new FinancePartyGstRegistration(['party_id' => $party->id]);
            $row->fill([
                'gstin' => $gstin,
                'registered_name' => $this->nullableString($payload['registered_name'] ?? null) ?? $party->legal_name,
                'state' => $stateName,
                'state_code' => $stateCode,
                'pan' => $pan,
                'is_primary' => (bool) ($payload['is_primary'] ?? false),
                'is_active' => (bool) ($payload['is_active'] ?? true),
            ]);
            $row->party_id = $party->id;
            $row->save();

            if ($row->is_primary) {
                FinancePartyGstRegistration::query()
                    ->where('party_id', $party->id)
                    ->where('id', '!=', $row->id)
                    ->update(['is_primary' => false]);
            } elseif (! FinancePartyGstRegistration::query()
                ->where('party_id', $party->id)
                ->where('id', '!=', $row->id)
                ->where('is_primary', true)
                ->exists()) {
                $row->is_primary = true;
                $row->save();
            }

            return $row->fresh() ?? $row;
        });
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveVendorTerms(FinanceParty $party, array $payload): FinancePartyRole
    {
        $role = $party->vendorRole()->first();
        if ($role === null) {
            throw new InvalidArgumentException('Party is not a vendor.');
        }

        $role->update([
            'vendor_code' => $this->nullableString($payload['vendor_code'] ?? null) ?? $role->vendor_code,
            'payment_terms' => $this->nullableString($payload['payment_terms'] ?? null),
            'credit_days' => isset($payload['credit_days']) && $payload['credit_days'] !== '' && $payload['credit_days'] !== null
                ? (int) $payload['credit_days']
                : null,
            'credit_limit' => isset($payload['credit_limit']) && $payload['credit_limit'] !== '' && $payload['credit_limit'] !== null
                ? $payload['credit_limit']
                : null,
            'preferred_payment_method' => $this->nullableString($payload['preferred_payment_method'] ?? null),
        ]);

        return $role->fresh() ?? $role;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveCustomerTerms(FinanceParty $party, array $payload): FinancePartyRole
    {
        $role = $party->customerRole()->first();
        if ($role === null) {
            throw new InvalidArgumentException('Party is not a customer.');
        }

        $role->update([
            'payment_terms' => $this->nullableString($payload['payment_terms'] ?? null),
            'credit_days' => isset($payload['credit_days']) && $payload['credit_days'] !== '' && $payload['credit_days'] !== null
                ? (int) $payload['credit_days']
                : null,
            'credit_limit' => isset($payload['credit_limit']) && $payload['credit_limit'] !== '' && $payload['credit_limit'] !== null
                ? $payload['credit_limit']
                : null,
            'preferred_payment_method' => $this->nullableString($payload['preferred_payment_method'] ?? null),
        ]);

        return $role->fresh() ?? $role;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function saveVendorBankAccount(FinanceParty $party, array $payload): FinancePartyVendorBankAccount
    {
        if (! $party->vendorRole()->exists()) {
            throw new InvalidArgumentException('Party is not a vendor.');
        }

        $accountNumber = preg_replace('/\s+/', '', trim((string) $payload['account_number'])) ?? '';
        $digits = preg_replace('/\D+/', '', $accountNumber) ?? '';
        $ifsc = IfscCode::normalize((string) $payload['ifsc']);
        if ($ifsc === null || ! IfscCode::isValid($ifsc)) {
            throw new InvalidArgumentException('IFSC is invalid.');
        }

        return DB::transaction(function () use ($party, $payload, $accountNumber, $digits, $ifsc): FinancePartyVendorBankAccount {
            $row = new FinancePartyVendorBankAccount;
            $row->fill([
                'party_id' => $party->id,
                'bank_name' => trim((string) $payload['bank_name']),
                'account_holder_name' => trim((string) $payload['account_holder_name']),
                'account_number' => $accountNumber,
                'last_four' => substr($digits, -4),
                'ifsc' => $ifsc,
                'is_primary' => (bool) ($payload['is_primary'] ?? false),
                'is_active' => true,
            ]);
            $row->save();

            if ($row->is_primary) {
                FinancePartyVendorBankAccount::query()
                    ->where('party_id', $party->id)
                    ->where('id', '!=', $row->id)
                    ->update(['is_primary' => false]);
            }

            return $row->fresh() ?? $row;
        });
    }

    /**
     * @param  list<string>  $roles
     * @param  array<string, mixed>  $identity
     */
    private function syncRoles(FinanceParty $party, array $roles, array $identity): void
    {
        $wanted = array_values(array_unique($roles));

        FinancePartyRole::query()
            ->where('party_id', $party->id)
            ->whereNotIn('role', $wanted)
            ->delete();

        foreach ($wanted as $role) {
            $existing = FinancePartyRole::query()->firstOrNew([
                'party_id' => $party->id,
                'role' => $role,
            ]);

            if ($role === FinancePartyRoleType::Vendor->value && $existing->vendor_code === null) {
                $existing->vendor_code = $this->nullableString($identity['vendor_code'] ?? null);
            }

            $existing->save();

            if ($role === FinancePartyRoleType::Vendor->value && $existing->vendor_code === null) {
                $existing->update(['vendor_code' => sprintf('VND-%06d', $party->id)]);
            }
        }
    }

    /**
     * @param  list<string>  $roles
     */
    private function assertRoles(array $roles): void
    {
        $roles = array_values(array_unique($roles));
        if ($roles === []) {
            throw new InvalidArgumentException('A party must be a customer, a vendor, or both.');
        }

        foreach ($roles as $role) {
            FinancePartyRoleType::from($role);
        }
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
