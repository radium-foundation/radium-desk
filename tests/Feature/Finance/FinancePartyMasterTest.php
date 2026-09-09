<?php

namespace Tests\Feature\Finance;

use App\Enums\FinancePartyAddressKind;
use App\Enums\FinancePartyKind;
use App\Models\FinanceParty;
use App\Models\FinancePartyLegacyIdentity;
use App\Models\FinancePartyVendorBankAccount;
use App\Models\User;
use App\Services\Finance\PartyMasterService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class FinancePartyMasterTest extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    private User $admin;

    private User $operationsAdmin;

    private PartyMasterService $parties;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->disableRequestForgeryProtection();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->operationsAdmin = User::factory()->create(['is_active' => true]);
        $this->operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->parties = app(PartyMasterService::class);
    }

    public function test_admin_can_create_and_edit_a_customer_party(): void
    {
        $this->actingAs($this->admin)
            ->post(route('finance.parties.store'), $this->identityPayload(
                legalName: 'Radium Retail Pvt Ltd',
                roles: ['customer'],
                phone: '9876500001',
            ))
            ->assertRedirect();

        $party = FinanceParty::query()->firstOrFail();
        $this->assertSame('PTY-'.str_pad((string) $party->id, 6, '0', STR_PAD_LEFT), $party->code);
        $this->assertSame('Radium Retail Pvt Ltd', $party->legal_name);
        $this->assertTrue($party->is_active);
        $this->assertTrue($party->fresh(['roles'])->isCustomer());
        $this->assertFalse($party->fresh(['roles'])->isVendor());

        $this->actingAs($this->admin)
            ->put(route('finance.parties.update', $party), $this->identityPayload(
                legalName: 'Radium Retail Private Limited',
                roles: ['customer'],
                phone: '9876500001',
            ))
            ->assertRedirect(route('finance.parties.show', $party));

        $this->assertSame('Radium Retail Private Limited', $party->fresh()->legal_name);
    }

    public function test_party_can_be_deactivated_and_reactivated(): void
    {
        $party = $this->createParty(['legal_name' => 'Quiet Customer'], ['customer']);

        $this->actingAs($this->admin)
            ->patch(route('finance.parties.deactivate', $party))
            ->assertRedirect(route('finance.parties.show', $party));

        $this->assertFalse($party->fresh()->is_active);

        $this->actingAs($this->admin)
            ->patch(route('finance.parties.activate', $party))
            ->assertRedirect(route('finance.parties.show', $party));

        $this->assertTrue($party->fresh()->is_active);
    }

    public function test_party_list_search_and_role_filters(): void
    {
        $customer = $this->createParty(['legal_name' => 'Alpha Customer Co', 'phone' => '9000000001'], ['customer']);
        $vendor = $this->createParty(['legal_name' => 'Beta Vendor Co', 'phone' => '9000000002'], ['vendor']);
        $both = $this->createParty(['legal_name' => 'Gamma Trading Co', 'phone' => '9000000003'], ['customer', 'vendor']);

        $this->actingAs($this->admin)
            ->get(route('finance.parties.index', ['q' => 'Alpha']))
            ->assertOk()
            ->assertSee('Alpha Customer Co')
            ->assertDontSee('Beta Vendor Co');

        $this->actingAs($this->admin)
            ->get(route('finance.parties.index', ['role' => 'customer']))
            ->assertOk()
            ->assertSee($customer->legal_name)
            ->assertSee($both->legal_name)
            ->assertDontSee($vendor->legal_name);

        $this->actingAs($this->admin)
            ->get(route('finance.parties.index', ['role' => 'vendor']))
            ->assertOk()
            ->assertSee($vendor->legal_name)
            ->assertSee($both->legal_name)
            ->assertDontSee($customer->legal_name);

        $this->actingAs($this->admin)
            ->get(route('finance.parties.index', ['role' => 'both']))
            ->assertOk()
            ->assertSee($both->legal_name)
            ->assertDontSee($customer->legal_name)
            ->assertDontSee($vendor->legal_name);
    }

    public function test_duplicate_phone_numbers_are_allowed(): void
    {
        $this->createParty(['legal_name' => 'First Phone Party', 'phone' => '9999911111'], ['customer']);
        $this->createParty(['legal_name' => 'Second Phone Party', 'phone' => '9999911111'], ['vendor']);

        $this->assertSame(2, FinanceParty::query()->where('phone', '9999911111')->count());
    }

    public function test_customer_vendor_and_both_roles_share_one_legal_identity(): void
    {
        $party = $this->createParty(['legal_name' => 'Shared Entity LLP'], ['customer', 'vendor']);

        $this->assertTrue($party->isCustomer());
        $this->assertTrue($party->isVendor());
        $this->assertSame(1, FinanceParty::query()->where('legal_name', 'Shared Entity LLP')->count());
        $this->assertSame('VND-'.str_pad((string) $party->id, 6, '0', STR_PAD_LEFT), $party->vendorRole->vendor_code);

        $this->actingAs($this->admin)
            ->put(route('finance.parties.customer-terms.update', $party), [
                'payment_terms' => 'Net 15',
                'credit_days' => 15,
                'credit_limit' => '25000.00',
                'preferred_payment_method' => 'UPI',
            ])
            ->assertRedirect(route('finance.parties.show', $party));

        $this->actingAs($this->admin)
            ->put(route('finance.parties.vendor-terms.update', $party), [
                'vendor_code' => 'VND-SHARED',
                'payment_terms' => 'Net 30',
                'credit_days' => 30,
                'credit_limit' => '100000.00',
                'preferred_payment_method' => 'NEFT',
            ])
            ->assertRedirect(route('finance.parties.show', $party));

        $party->refresh()->load(['customerRole', 'vendorRole']);
        $this->assertSame('Net 15', $party->customerRole->payment_terms);
        $this->assertSame(15, $party->customerRole->credit_days);
        $this->assertSame('Net 30', $party->vendorRole->payment_terms);
        $this->assertSame('VND-SHARED', $party->vendorRole->vendor_code);
        $this->assertSame('Shared Entity LLP', $party->legal_name);
    }

    public function test_party_can_have_multiple_addresses_with_billing_and_shipping_defaults(): void
    {
        $party = $this->createParty(['legal_name' => 'Address Party'], ['customer']);

        $this->actingAs($this->admin)
            ->post(route('finance.parties.addresses.store', $party), [
                'label' => 'HQ',
                'kind' => FinancePartyAddressKind::Billing->value,
                'line1' => '1 Connaught Place',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'postal_code' => '110001',
                'is_default_billing' => '1',
            ])
            ->assertRedirect(route('finance.parties.show', $party));

        $this->actingAs($this->admin)
            ->post(route('finance.parties.addresses.store', $party), [
                'label' => 'Warehouse',
                'kind' => FinancePartyAddressKind::Shipping->value,
                'line1' => '12 Whitefield',
                'city' => 'Bengaluru',
                'state' => 'Karnataka',
                'postal_code' => '560066',
                'is_default_shipping' => '1',
            ])
            ->assertRedirect(route('finance.parties.show', $party));

        $party->load(['addresses', 'defaultBillingAddress', 'defaultShippingAddress']);
        $this->assertCount(2, $party->addresses);
        $this->assertSame('1 Connaught Place', $party->defaultBillingAddress?->line1);
        $this->assertSame('12 Whitefield', $party->defaultShippingAddress?->line1);
        $this->assertSame(1, $party->addresses->where('is_default_billing', true)->count());
        $this->assertSame(1, $party->addresses->where('is_default_shipping', true)->count());
    }

    public function test_party_can_have_multiple_contacts_with_one_primary(): void
    {
        $party = $this->createParty(['legal_name' => 'Contact Party'], ['customer']);

        $this->actingAs($this->admin)
            ->post(route('finance.parties.contacts.store', $party), [
                'name' => 'Priya Accounts',
                'designation' => 'Accounts',
                'phone' => '9811111111',
                'email' => 'accounts@example.test',
                'is_primary' => '1',
            ])
            ->assertRedirect(route('finance.parties.show', $party));

        $this->actingAs($this->admin)
            ->post(route('finance.parties.contacts.store', $party), [
                'name' => 'Rahul Dispatch',
                'designation' => 'Dispatch',
                'phone' => '9822222222',
            ])
            ->assertRedirect(route('finance.parties.show', $party));

        $party->load(['contacts', 'primaryContact']);
        $this->assertCount(2, $party->contacts);
        $this->assertSame('Priya Accounts', $party->primaryContact?->name);
        $this->assertSame(1, $party->contacts->where('is_primary', true)->count());
    }

    public function test_gst_registrations_validate_and_allow_multiple_per_party(): void
    {
        $party = $this->createParty(['legal_name' => 'GST Party'], ['customer']);

        $this->actingAs($this->admin)
            ->post(route('finance.parties.gst.store', $party), [
                'gstin' => '07AAAAA0000A1Z5',
                'is_primary' => '1',
            ])
            ->assertRedirect(route('finance.parties.show', $party));

        $this->actingAs($this->admin)
            ->post(route('finance.parties.gst.store', $party), [
                'gstin' => '29AAAAA0000A1Z5',
            ])
            ->assertRedirect(route('finance.parties.show', $party));

        $party->load(['gstRegistrations', 'primaryGstRegistration']);
        $this->assertCount(2, $party->gstRegistrations);
        $this->assertSame('07AAAAA0000A1Z5', $party->primaryGstRegistration?->gstin);
        $this->assertSame('AAAAA0000A', $party->primaryGstRegistration?->pan);
        $this->assertSame('Delhi', $party->primaryGstRegistration?->state);
        $this->assertSame('07', $party->primaryGstRegistration?->state_code);

        $other = $this->createParty(['legal_name' => 'Other GST Party'], ['vendor']);
        $this->actingAs($this->admin)
            ->post(route('finance.parties.gst.store', $other), [
                'gstin' => '07AAAAA0000A1Z5',
            ])
            ->assertRedirect(route('finance.parties.show', $other));
    }

    public function test_invalid_gstin_is_rejected_beyond_length(): void
    {
        $party = $this->createParty(['legal_name' => 'Invalid GST Party'], ['customer']);

        $this->actingAs($this->admin)
            ->from(route('finance.parties.show', $party))
            ->post(route('finance.parties.gst.store', $party), [
                'gstin' => '123456789012',
            ])
            ->assertRedirect(route('finance.parties.show', $party))
            ->assertSessionHasErrors('gstin');

        $this->actingAs($this->admin)
            ->from(route('finance.parties.show', $party))
            ->post(route('finance.parties.gst.store', $party), [
                'gstin' => '99AAAAA0000A1Z5',
            ])
            ->assertRedirect(route('finance.parties.show', $party))
            ->assertSessionHasErrors('gstin');

        $this->assertSame(0, $party->gstRegistrations()->count());
    }

    public function test_unauthorized_users_cannot_view_or_manage_parties(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);
        $party = $this->createParty(['legal_name' => 'Secret Party'], ['customer']);

        $this->actingAs($agent)
            ->get(route('finance.parties.index'))
            ->assertForbidden();

        $this->actingAs($agent)
            ->get(route('finance.parties.show', $party))
            ->assertForbidden();

        $this->actingAs($agent)
            ->post(route('finance.parties.store'), $this->identityPayload())
            ->assertForbidden();

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo([
            RolePermissionSeeder::PERMISSION_FINANCE_VIEW,
            RolePermissionSeeder::PERMISSION_FINANCE_PARTIES_VIEW,
        ]);

        $this->actingAs($viewer)
            ->get(route('finance.parties.index'))
            ->assertOk();

        $this->actingAs($viewer)
            ->get(route('finance.parties.create'))
            ->assertForbidden();

        $this->actingAs($viewer)
            ->post(route('finance.parties.store'), $this->identityPayload())
            ->assertForbidden();
    }

    public function test_vendor_bank_details_are_hidden_without_bank_permission(): void
    {
        $party = $this->createParty(['legal_name' => 'Banked Vendor'], ['vendor']);
        $this->parties->saveVendorBankAccount($party, [
            'bank_name' => 'HDFC Bank',
            'account_holder_name' => 'Banked Vendor',
            'account_number' => '12345678901234',
            'ifsc' => 'HDFC0001234',
            'is_primary' => true,
        ]);

        $this->actingAs($this->operationsAdmin)
            ->get(route('finance.parties.show', $party))
            ->assertOk()
            ->assertSee('****1234')
            ->assertDontSee('12345678901234')
            ->assertDontSee('HDFC0001234');

        $this->actingAs($this->operationsAdmin)
            ->post(route('finance.parties.vendor-banks.store', $party), [
                'bank_name' => 'SBI',
                'account_holder_name' => 'Banked Vendor',
                'account_number' => '998877665544',
                'ifsc' => 'SBIN0004321',
            ])
            ->assertForbidden();

        $this->actingAs($this->admin)
            ->get(route('finance.parties.show', $party))
            ->assertOk()
            ->assertSee('12345678901234')
            ->assertSee('HDFC0001234');

        $serialized = FinancePartyVendorBankAccount::query()->firstOrFail()->toArray();
        $this->assertArrayNotHasKey('account_number', $serialized);
        $this->assertArrayNotHasKey('ifsc', $serialized);
        $this->assertSame('1234', $serialized['last_four']);
    }

    public function test_party_listing_does_not_n_plus_one(): void
    {
        foreach (['List One', 'List Two', 'List Three'] as $name) {
            $party = $this->createParty(['legal_name' => $name], ['customer', 'vendor']);
            $this->parties->saveContact($party, [
                'name' => $name.' contact',
                'is_primary' => true,
            ]);
            $this->parties->saveGstRegistration($party, [
                'gstin' => '07AAAAA0000A1Z5',
                'is_primary' => true,
            ]);
        }

        Model::preventLazyLoading();

        try {
            $this->actingAs($this->admin)
                ->get(route('finance.parties.index'))
                ->assertOk()
                ->assertSee('List One')
                ->assertSee('List Two')
                ->assertSee('List Three');
        } finally {
            Model::preventLazyLoading(false);
        }
    }

    public function test_document_snapshot_freezes_current_party_facts_without_touching_invoices(): void
    {
        $party = $this->createParty([
            'legal_name' => 'Snapshot Legal',
            'trade_name' => 'Snapshot Trade',
            'phone' => '9000090000',
        ], ['customer']);

        $this->parties->saveAddress($party, [
            'kind' => FinancePartyAddressKind::Billing->value,
            'line1' => '9 Karol Bagh',
            'state' => 'Delhi',
            'postal_code' => '110005',
            'is_default_billing' => true,
        ]);
        $this->parties->saveGstRegistration($party, [
            'gstin' => '07AAAAA0000A1Z5',
            'is_primary' => true,
        ]);
        $this->parties->saveContact($party, [
            'name' => 'Snapshot Contact',
            'phone' => '9000090001',
            'email' => 'snap@example.test',
            'is_primary' => true,
        ]);

        $snapshot = $party->fresh()->documentSnapshot();

        $this->assertSame($party->id, $snapshot->partyId);
        $this->assertSame($party->code, $snapshot->partyCode);
        $this->assertSame('Snapshot Legal', $snapshot->legalName);
        $this->assertSame('Snapshot Trade', $snapshot->tradeName);
        $this->assertSame('07AAAAA0000A1Z5', $snapshot->gstin);
        $this->assertSame('AAAAA0000A', $snapshot->pan);
        $this->assertSame('9 Karol Bagh', $snapshot->billingAddress['line1'] ?? null);
        $this->assertSame('Delhi', $snapshot->placeOfSupply);
        $this->assertSame('110005', $snapshot->postalCode);
        $this->assertSame('Snapshot Contact', $snapshot->contactName);
        $this->assertSame(0, FinancePartyLegacyIdentity::query()->count());
    }

    public function test_vendor_master_settings_placeholder_redirects_to_party_list(): void
    {
        $this->actingAs($this->admin)
            ->get(route('finance.settings.vendor-master'))
            ->assertRedirect(route('finance.parties.index', ['role' => 'vendor']));
    }

    /**
     * @param  array<string, mixed>  $identity
     * @param  list<string>  $roles
     */
    private function createParty(array $identity, array $roles): FinanceParty
    {
        return $this->parties->create(array_merge([
            'legal_name' => 'Test Party',
            'kind' => FinancePartyKind::Organisation,
        ], $identity), $roles)->load(['roles', 'customerRole', 'vendorRole']);
    }

    /**
     * @param  list<string>  $roles
     * @return array<string, mixed>
     */
    private function identityPayload(
        string $legalName = 'New Party Co',
        array $roles = ['customer'],
        ?string $phone = '9876500999',
    ): array {
        return [
            'legal_name' => $legalName,
            'trade_name' => 'New Party',
            'kind' => FinancePartyKind::Organisation->value,
            'phone' => $phone,
            'email' => 'party@example.test',
            'notes' => 'Foundation test',
            'roles' => $roles,
        ];
    }
}
