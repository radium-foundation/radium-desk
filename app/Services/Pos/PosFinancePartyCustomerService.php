<?php

namespace App\Services\Pos;

use App\Enums\FinancePartyAddressKind;
use App\Enums\FinancePartyKind;
use App\Enums\FinancePartyRoleType;
use App\Enums\PosCustomerType;
use App\Models\FinanceParty;
use App\Models\FinancePartyAddress;
use App\Models\FinancePartyGstRegistration;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Services\Finance\PartyMasterService;
use App\Services\StatutoryInvoice\BuyerGstin;
use App\Support\Finance\IndianStates;
use App\Support\Finance\PanNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

final class PosFinancePartyCustomerService
{
    public function __construct(
        private readonly PartyMasterService $parties,
    ) {}

    /**
     * @return array{
     *     found: bool,
     *     party_id?: int,
     *     name?: string,
     *     phone?: string,
     *     email?: ?string,
     *     customer_type?: string,
     *     gst_registrations?: list<array{id:int,gstin:string,registered_name:?string,state:string,is_primary:bool}>,
     *     billing_address?: ?string,
     *     billing_state?: ?string,
     *     billing_city?: ?string,
     *     billing_postal_code?: ?string,
     *     gstin?: ?string
     * }
     */
    public function lookupByPhone(string $phone): array
    {
        $phone = $this->normalisePhone($phone);
        if ($phone === '') {
            return ['found' => false];
        }

        $inventoryCustomer = InventoryCustomer::query()->where('phone', $phone)->first();
        $party = null;
        if ($inventoryCustomer?->finance_party_id !== null) {
            $party = FinanceParty::query()
                ->with(['gstRegistrations' => fn ($q) => $q->where('is_active', true)->orderByDesc('is_primary')])
                ->with(['defaultBillingAddress'])
                ->find($inventoryCustomer->finance_party_id);
        }

        if ($party === null) {
            $party = FinanceParty::query()
                ->where('phone', $phone)
                ->where('is_active', true)
                ->with(['gstRegistrations' => fn ($q) => $q->where('is_active', true)->orderByDesc('is_primary')])
                ->with(['defaultBillingAddress'])
                ->first();
        }

        if ($party === null && $inventoryCustomer === null) {
            return ['found' => false];
        }

        $billing = $party?->defaultBillingAddress;
        $primaryGst = $party?->gstRegistrations->firstWhere('is_primary', true)
            ?? $party?->gstRegistrations->first();

        return [
            'found' => true,
            'party_id' => $party?->id,
            'name' => $party?->legal_name ?? $inventoryCustomer?->name,
            'phone' => $phone,
            'email' => $party?->email ?? $inventoryCustomer?->email,
            'customer_type' => ($primaryGst !== null || $inventoryCustomer?->gstin)
                ? PosCustomerType::B2b->value
                : PosCustomerType::B2c->value,
            'gst_registrations' => $party?->gstRegistrations
                ->map(static fn (FinancePartyGstRegistration $row): array => [
                    'id' => $row->id,
                    'gstin' => $row->gstin,
                    'registered_name' => $row->registered_name,
                    'state' => $row->state,
                    'is_primary' => (bool) $row->is_primary,
                ])->values()->all() ?? [],
            'billing_address' => $billing?->line1,
            'billing_state' => $billing?->state,
            'billing_city' => $billing?->city,
            'billing_postal_code' => $billing?->postal_code,
            'gstin' => $primaryGst?->gstin ?? $inventoryCustomer?->gstin,
        ];
    }

    /**
     * @param  array<string, mixed>  $input
     * @return array{
     *     customer: array{name:string,phone:string,email?:?string,gstin?:?string,finance_party_id?:?int},
     *     statutory: array<string, mixed>
     * }
     */
    public function resolveForSale(InventoryBranch $branch, array $input, PosCustomerType $customerType): array
    {
        $name = trim((string) ($input['customer_name'] ?? ''));
        $phone = $this->normalisePhone((string) ($input['customer_phone'] ?? ''));
        $email = $this->nullableString($input['customer_email'] ?? null);

        if ($name === '' || $phone === '') {
            throw ValidationException::withMessages([
                'customer_phone' => 'Customer name and phone are required.',
            ]);
        }

        $partyId = isset($input['finance_party_id']) ? (int) $input['finance_party_id'] : null;
        $gstRegistrationId = isset($input['finance_party_gst_registration_id'])
            ? (int) $input['finance_party_gst_registration_id']
            : null;

        $party = $partyId > 0
            ? FinanceParty::query()->with(['gstRegistrations', 'defaultBillingAddress'])->find($partyId)
            : null;

        $buyerGstin = BuyerGstin::normalize($input['buyer_gstin'] ?? null);
        $billingAddress = $this->nullableString($input['billing_address'] ?? null);
        $billingState = $this->nullableString($input['billing_state'] ?? null);
        $billingCity = $this->nullableString($input['billing_city'] ?? null);
        $billingPostal = $this->nullableString($input['billing_postal_code'] ?? null);
        $buyerPan = PanNumber::normalize($input['buyer_pan'] ?? null);

        if ($customerType === PosCustomerType::B2b) {
            $this->assertB2bFields($name, $buyerGstin, $billingAddress, $billingState, $billingPostal);
        } elseif ($buyerGstin !== null) {
            if (! BuyerGstin::isValid($buyerGstin)) {
                throw ValidationException::withMessages([
                    'buyer_gstin' => 'Enter a valid 15-character GSTIN or leave it blank for B2C.',
                ]);
            }
        }

        $registration = null;
        if ($gstRegistrationId > 0) {
            $registration = FinancePartyGstRegistration::query()
                ->where('id', $gstRegistrationId)
                ->where('is_active', true)
                ->first();
            if ($registration === null) {
                throw ValidationException::withMessages([
                    'finance_party_gst_registration_id' => 'Select a valid GST registration.',
                ]);
            }
            if ($party !== null && $registration->party_id !== $party->id) {
                throw ValidationException::withMessages([
                    'finance_party_gst_registration_id' => 'The GST registration does not belong to the selected customer.',
                ]);
            }
            $party = $party ?? $registration->party;
            $buyerGstin = $registration->gstin;
            $buyerPan = $registration->pan ?? $buyerPan;
            if ($billingState === null) {
                $billingState = $registration->state;
            }
        }

        if ($party === null) {
            $party = $this->createPartyForWalkIn(
                $customerType,
                $name,
                $phone,
                $email,
                $buyerGstin,
                $billingAddress,
                $billingState,
                $billingCity,
                $billingPostal,
            );
        } elseif ($customerType === PosCustomerType::B2b && $buyerGstin !== null && $registration === null) {
            $existing = $party->gstRegistrations->firstWhere('gstin', $buyerGstin);
            if ($existing === null) {
                $registration = $this->parties->saveGstRegistration($party, [
                    'gstin' => $buyerGstin,
                    'registered_name' => $name,
                    'is_primary' => $party->gstRegistrations->isEmpty(),
                ]);
            } else {
                $registration = $existing;
            }
        }

        if ($customerType === PosCustomerType::B2b && $billingAddress !== null && $billingState !== null && $billingPostal !== null) {
            $this->ensureBillingAddress($party, $billingAddress, $billingState, $billingCity, $billingPostal);
        }

        $gstRegistrationId = $registration?->id ?? ($gstRegistrationId > 0 ? $gstRegistrationId : null);

        return [
            'customer' => [
                'name' => $name,
                'phone' => $phone,
                'email' => $email,
                'gstin' => $buyerGstin,
                'finance_party_id' => $party->id,
            ],
            'statutory' => [
                'customer_type' => $customerType->value,
                'snapshot_buyer_name' => $name,
                'snapshot_buyer_phone' => $phone,
                'snapshot_buyer_email' => $email,
                'buyer_gstin' => $buyerGstin,
                'buyer_pan' => $buyerPan,
                'billing_address' => $billingAddress,
                'billing_state' => $billingState,
                'billing_city' => $billingCity,
                'billing_postal_code' => $billingPostal,
                'finance_party_id' => $party->id,
                'finance_party_gst_registration_id' => $gstRegistrationId,
            ],
        ];
    }

    private function createPartyForWalkIn(
        PosCustomerType $customerType,
        string $name,
        string $phone,
        ?string $email,
        ?string $buyerGstin,
        ?string $billingAddress,
        ?string $billingState,
        ?string $billingCity,
        ?string $billingPostal,
    ): FinanceParty {
        return DB::transaction(function () use (
            $customerType,
            $name,
            $phone,
            $email,
            $buyerGstin,
            $billingAddress,
            $billingState,
            $billingCity,
            $billingPostal,
        ): FinanceParty {
            $party = $this->parties->create([
                'legal_name' => $name,
                'kind' => $customerType === PosCustomerType::B2b
                    ? FinancePartyKind::Organisation
                    : FinancePartyKind::Person,
                'phone' => $phone,
                'email' => $email,
            ], [FinancePartyRoleType::Customer->value]);

            if ($customerType === PosCustomerType::B2b && $buyerGstin !== null) {
                $this->parties->saveGstRegistration($party, [
                    'gstin' => $buyerGstin,
                    'registered_name' => $name,
                    'is_primary' => true,
                ]);
            }

            if ($customerType === PosCustomerType::B2b && $billingAddress !== null && $billingState !== null && $billingPostal !== null) {
                $this->parties->saveAddress($party, [
                    'kind' => FinancePartyAddressKind::Billing->value,
                    'line1' => $billingAddress,
                    'city' => $billingCity,
                    'state' => $billingState,
                    'postal_code' => $billingPostal,
                    'is_default_billing' => true,
                ]);
            }

            return $party->fresh(['gstRegistrations', 'defaultBillingAddress']) ?? $party;
        });
    }

    private function ensureBillingAddress(
        FinanceParty $party,
        string $line1,
        string $state,
        ?string $city,
        string $postalCode,
    ): void {
        $existing = $party->defaultBillingAddress;
        if ($existing instanceof FinancePartyAddress
            && trim($existing->line1) === trim($line1)
            && trim($existing->state) === trim($state)
            && trim((string) $existing->postal_code) === trim($postalCode)) {
            return;
        }

        $this->parties->saveAddress($party, [
            'kind' => FinancePartyAddressKind::Billing->value,
            'line1' => $line1,
            'city' => $city,
            'state' => $state,
            'postal_code' => $postalCode,
            'is_default_billing' => true,
        ], $existing);
    }

    private function assertB2bFields(
        string $name,
        ?string $buyerGstin,
        ?string $billingAddress,
        ?string $billingState,
        ?string $billingPostal,
    ): void {
        $errors = [];
        if (trim($name) === '') {
            $errors['customer_name'] = 'B2B invoice requires the customer legal name.';
        }
        if ($buyerGstin === null || ! BuyerGstin::isValid($buyerGstin)) {
            $errors['buyer_gstin'] = 'B2B invoice requires a valid 15-character GSTIN.';
        }
        if ($billingAddress === null) {
            $errors['billing_address'] = 'B2B invoice requires a billing address.';
        }
        if ($billingState === null || ! IndianStates::contains($billingState)) {
            $errors['billing_state'] = 'B2B invoice requires the billing state.';
        }
        if ($billingPostal === null || strlen($billingPostal) < 6) {
            $errors['billing_postal_code'] = 'B2B invoice requires a valid PIN code.';
        }

        if ($errors !== []) {
            throw ValidationException::withMessages($errors);
        }
    }

    private function normalisePhone(string $phone): string
    {
        return preg_replace('/\s+/', '', $phone) ?? '';
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
