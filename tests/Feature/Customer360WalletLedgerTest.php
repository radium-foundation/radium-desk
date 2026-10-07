<?php

namespace Tests\Feature;

use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\ReservationState;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletReservation;
use App\Enums\IncidentSource;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\IncidentReferenceService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

class Customer360WalletLedgerTest extends TestCase
{
    use RefreshDatabase;

    private const EMAIL = 'ravithelavi@gmail.com';

    private const CUSTOMER_ID = '46c69a65-7fb9-4d36-948f-32d00475fd0e';

    private const CWID = '50ff2e87-6030-4ae8-b93a-163884db90c5';

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_agent_sees_the_verified_customers_unified_wallet(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->verifiedCustomerCase($agent);
        $this->createVerifiedFiveEntries();

        $html = $this->walletHtml($agent, $incident);

        $this->assertStringContainsString('Central Wallet', $html);
        $this->assertStringContainsString('One customer wallet', $html);
        $this->assertStringContainsString('₹399.00', $html);
        $this->assertStringContainsString('Active reservations', $html);
        $this->assertStringContainsString('₹0.00', $html);
        $this->assertStringContainsString('····90c5', $html);
        $this->assertStringNotContainsString(self::CWID, $html);
        $this->assertStringContainsString('#55', $html);
        $this->assertStringContainsString('#56', $html);
        $this->assertStringContainsString('#57', $html);
        $this->assertStringContainsString('#58', $html);
        $this->assertStringContainsString('#59', $html);
        $this->assertStringContainsString('REF-2026-000300', $html);
        $this->assertStringContainsString('RD13874', $html);
        $this->assertStringContainsString('RN172', $html);
        $this->assertStringContainsString('PROBE-NO-OP', $html);
        $this->assertStringContainsString('PROBE-NO-OP-CORRECTION', $html);
        $this->assertStringContainsString('rdservice.in', $html);
        $this->assertStringContainsString('rdservice.net', $html);
        $this->assertStringContainsString('Reverses #58', $html);
        $this->assertStringContainsString('credit', $html);
        $this->assertStringContainsString('debit', $html);
        $this->assertStringContainsString('reversal', $html);
        $this->assertStringContainsString('users_wallet:2540', $html);
    }

    public function test_browser_supplied_cwid_cannot_open_another_wallet(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->verifiedCustomerCase($agent);
        $this->createVerifiedFiveEntries();

        $otherCwid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeaaaa';
        $this->walletFor($otherCwid);
        $this->ledgerRow($otherCwid, [
            'entry_type' => 'credit',
            'amount' => '999.00',
            'source_system' => 'radiumbox.com',
            'business_reference' => 'OTHER-WALLET-SECRET',
            'posted_at' => '2026-10-07 12:00:00',
        ]);

        $html = $this->walletHtml($agent, $incident, [
            'central_wallet_id' => $otherCwid,
            'cwid' => $otherCwid,
            'customer_email' => 'other-customer@example.com',
        ]);

        $this->assertStringContainsString('₹399.00', $html);
        $this->assertStringContainsString('RN172', $html);
        $this->assertStringNotContainsString('OTHER-WALLET-SECRET', $html);
        $this->assertStringNotContainsString($otherCwid, $html);
        $this->assertStringNotContainsString('₹999.00', $html);
    }

    public function test_radiumbox_source_is_labeled_without_splitting_the_wallet(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->verifiedCustomerCase($agent);
        $this->ledgerRow(self::CWID, [
            'entry_type' => 'credit',
            'amount' => '10.00',
            'source_system' => 'radiumbox.com',
            'business_reference' => 'RB-ONE-WALLET',
            'posted_at' => '2026-10-01 10:00:00',
        ]);

        $html = $this->walletHtml($agent, $incident);

        $this->assertStringContainsString('RadiumBox', $html);
        $this->assertStringContainsString('RB-ONE-WALLET', $html);
        $this->assertStringNotContainsString('separate wallet', strtolower($html));
    }

    public function test_history_paginates_with_next_cursor_newest_first(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->verifiedCustomerCase($agent);

        for ($index = 1; $index <= 26; $index++) {
            $this->ledgerRow(self::CWID, [
                'entry_type' => 'credit',
                'amount' => '1.00',
                'source_system' => 'rdservice.in',
                'business_reference' => sprintf('ROW-%03d', $index),
                'posted_at' => now()->subMinutes(27 - $index),
            ]);
        }

        $first = $this->walletHtml($agent, $incident);
        $this->assertStringContainsString('ROW-026', $first);
        $this->assertStringNotContainsString('ROW-001', $first);
        $this->assertMatchesRegularExpression('/data-next-cursor="[^"]+"/', $first);

        preg_match('/data-next-cursor="([^"]+)"/', $first, $matches);
        $second = $this->walletHtml($agent, $incident, ['cursor' => html_entity_decode($matches[1])]);

        $this->assertStringContainsString('ROW-001', $second);
        $this->assertStringNotContainsString('ROW-026', $second);
    }

    public function test_empty_wallet_is_an_empty_state(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->verifiedCustomerCase($agent);

        $html = $this->walletHtml($agent, $incident);

        $this->assertStringContainsString('₹0.00', $html);
        $this->assertStringContainsString('No posted Central Wallet transactions for this customer.', $html);
        $this->assertStringNotContainsString('temporarily unavailable', $html);
        $this->assertStringNotContainsString('not linked to one verified Desk customer', $html);
    }

    public function test_central_wallet_failure_does_not_render_zero_balance(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->verifiedCustomerCase($agent);
        $this->createVerifiedFiveEntries();
        config(['central_wallet.ledger_read.customer_history.authorized_source_systems' => []]);

        $html = $this->walletHtml($agent, $incident);

        $this->assertStringContainsString('temporarily unavailable', $html);
        $this->assertStringContainsString('The balance is not shown.', $html);
        $this->assertStringNotContainsString('₹', $html);
        $this->assertStringNotContainsString('399.00', $html);
        $this->assertStringNotContainsString('0.00', $html);
    }

    public function test_unresolved_customer_is_not_shown_as_zero_and_account_links_are_not_guessed(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->incidentFor($agent, 'unlinked-customer@example.com', 'RD1');

        $otherCwid = 'bbbbbbbb-cccc-4ddd-8eee-ffffffffffff';
        $this->walletFor($otherCwid);
        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $otherCwid,
            'desk_customer_id' => null,
            'site_code' => 'rdservice.net',
            'local_user_id' => '3',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'verified_email',
            'created_by' => 'test',
        ]);
        $this->ledgerRow($otherCwid, [
            'entry_type' => 'credit',
            'amount' => '50.00',
            'source_system' => 'rdservice.net',
            'business_reference' => 'UNLINKED-SHOULD-NOT-SHOW',
            'posted_at' => '2026-10-02 22:51:49',
        ]);

        $html = $this->walletHtml($agent, $incident);

        $this->assertStringContainsString('not linked to one verified Desk customer', $html);
        $this->assertStringNotContainsString('₹', $html);
        $this->assertStringNotContainsString('UNLINKED-SHOULD-NOT-SHOW', $html);
        $this->assertStringNotContainsString($otherCwid, $html);
    }

    public function test_duplicate_verified_email_credentials_do_not_open_either_wallet(): void
    {
        Schema::table('central_customer_identity_credentials', function ($table): void {
            $table->dropUnique('central_customer_credentials_subject_uq');
        });

        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->verifiedCustomerCase($agent);
        $this->ledgerRow(self::CWID, [
            'entry_type' => 'credit',
            'amount' => '399.00',
            'source_system' => 'rdservice.in',
            'business_reference' => 'KNOWN-WALLET',
            'posted_at' => '2026-10-02 11:55:57',
        ]);

        $otherCustomerId = '11111111-2222-4333-8444-555555555555';
        $otherCwid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeaaaa';
        $this->walletFor($otherCwid);
        CentralCustomer::query()->create([
            'id' => $otherCustomerId,
            'central_wallet_id' => $otherCwid,
            'status' => 'active',
        ]);
        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $otherCustomerId,
            'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
            'provider' => 'desk_email',
            'subject_hash' => app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail(self::EMAIL),
            'verified_at' => now(),
        ]);
        $this->ledgerRow($otherCwid, [
            'entry_type' => 'credit',
            'amount' => '999.00',
            'source_system' => 'radiumbox.com',
            'business_reference' => 'OTHER-WALLET-SECRET',
            'posted_at' => '2026-10-07 12:00:00',
        ]);

        $html = $this->walletHtml($agent, $incident);

        $this->assertStringContainsString('not linked to one verified Desk customer', $html);
        $this->assertStringNotContainsString('₹', $html);
        $this->assertStringNotContainsString('KNOWN-WALLET', $html);
        $this->assertStringNotContainsString('OTHER-WALLET-SECRET', $html);
    }

    public function test_disagreeing_recorded_desk_customer_does_not_open_either_wallet(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->verifiedCustomerCase($agent);
        $this->createVerifiedFiveEntries();

        $otherCustomerId = '11111111-2222-4333-8444-555555555555';
        $otherCwid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeaaaa';
        $this->walletFor($otherCwid);
        CentralCustomer::query()->create([
            'id' => $otherCustomerId,
            'central_wallet_id' => $otherCwid,
            'status' => 'active',
        ]);
        $this->ledgerRow($otherCwid, [
            'entry_type' => 'credit',
            'amount' => '999.00',
            'source_system' => 'radiumbox.com',
            'business_reference' => 'OTHER-WALLET-SECRET',
            'posted_at' => '2026-10-07 12:00:00',
        ]);
        $incident->order?->update(['customer_id' => $otherCustomerId]);

        $html = $this->walletHtml($agent, $incident->fresh());

        $this->assertStringContainsString('not linked to one verified Desk customer', $html);
        $this->assertStringNotContainsString('₹', $html);
        $this->assertStringNotContainsString('RN172', $html);
        $this->assertStringNotContainsString('OTHER-WALLET-SECRET', $html);
        $this->assertStringNotContainsString($otherCwid, $html);
    }

    public function test_matching_recorded_desk_customer_keeps_the_verified_wallet(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->verifiedCustomerCase($agent);
        $this->createVerifiedFiveEntries();
        $incident->order?->update(['customer_id' => self::CUSTOMER_ID]);

        $html = $this->walletHtml($agent, $incident->fresh());

        $this->assertStringContainsString('₹399.00', $html);
        $this->assertStringContainsString('····90c5', $html);
        $this->assertStringContainsString('RN172', $html);
        $this->assertStringNotContainsString(self::CWID, $html);
    }

    public function test_legacy_order_customer_id_is_not_a_wallet_key(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->verifiedCustomerCase($agent);
        $this->createVerifiedFiveEntries();
        $incident->order?->update(['customer_id' => 'BOX-USER-3']);

        $html = $this->walletHtml($agent, $incident->fresh());

        $this->assertStringContainsString('₹399.00', $html);
        $this->assertStringContainsString('····90c5', $html);
        $this->assertStringNotContainsString(self::CWID, $html);
    }

    public function test_verified_customer_without_a_central_wallet_id_stays_unresolved(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->verifiedCustomerCase($agent);

        Schema::table('central_customers', function ($table): void {
            $table->uuid('central_wallet_id')->nullable()->change();
        });
        CentralCustomer::query()->whereKey(self::CUSTOMER_ID)->update([
            'central_wallet_id' => null,
        ]);

        config(['central_wallet.ledger_read.customer_history.authorized_source_systems' => []]);

        $otherCwid = 'aaaaaaaa-bbbb-4ccc-8ddd-eeeeeeeeaaaa';
        $this->walletFor($otherCwid);
        $this->ledgerRow($otherCwid, [
            'entry_type' => 'credit',
            'amount' => '999.00',
            'source_system' => 'radiumbox.com',
            'business_reference' => 'OTHER-WALLET-SECRET',
            'posted_at' => '2026-10-07 12:00:00',
        ]);

        $html = $this->walletHtml($agent, $incident);

        $this->assertStringContainsString('not linked to one verified Desk customer', $html);
        $this->assertStringNotContainsString('temporarily unavailable', $html);
        $this->assertStringNotContainsString('₹', $html);
        $this->assertStringNotContainsString('0.00', $html);
        $this->assertStringNotContainsString('OTHER-WALLET-SECRET', $html);
        $this->assertStringNotContainsString($otherCwid, $html);
        $this->assertStringNotContainsString(self::CWID, $html);
        $this->assertStringNotContainsString('····90c5', $html);
    }

    public function test_active_reservation_reduces_available_balance(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->verifiedCustomerCase($agent);
        $this->ledgerRow(self::CWID, [
            'entry_type' => 'credit',
            'amount' => '10.00',
            'source_system' => 'radiumbox.com',
            'business_reference' => 'HOLD-CREDIT',
            'posted_at' => '2026-10-01 10:00:00',
        ]);
        CentralWalletReservation::query()->create([
            'id' => (string) Str::uuid(),
            'central_wallet_id' => self::CWID,
            'caller_id' => 'radiumbox.com',
            'amount' => '4.00',
            'currency' => 'INR',
            'state' => ReservationState::Active,
            'business_reference' => 'HOLD-1',
            'correlation_id' => (string) Str::uuid(),
            'expires_at' => now()->addHour(),
        ]);

        $html = $this->walletHtml($agent, $incident);

        $this->assertStringContainsString('₹6.00', $html);
        $this->assertStringContainsString('₹4.00', $html);
    }

    public function test_operations_admin_and_superadmin_keep_wallet_access(): void
    {
        $this->verifiedCustomerCase($this->userWithRole(RolePermissionSeeder::ROLE_AGENT));
        $this->createVerifiedFiveEntries();
        $incident = Incident::query()->firstOrFail();

        foreach ([
            RolePermissionSeeder::ROLE_ADMIN,
            RolePermissionSeeder::ROLE_OPERATIONS_ADMIN,
            RolePermissionSeeder::ROLE_SUPERADMIN,
        ] as $role) {
            $html = $this->walletHtml($this->userWithRole($role), $incident);
            $this->assertStringContainsString('₹399.00', $html);
            $this->assertStringContainsString('RN172', $html);
        }
    }

    public function test_user_without_wallet_permission_cannot_open_the_tab(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo('incidents.view');
        $incident = $this->incidentFor($viewer, self::EMAIL, 'RD2');

        $this->actingAs($viewer)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1')
            ->assertForbidden();

        $this->actingAs($viewer)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertDontSee('data-customer-360-tab="wallet-ledger"', false);
    }

    public function test_wallet_permission_without_incident_access_is_forbidden(): void
    {
        $viewer = User::factory()->create();
        $viewer->givePermissionTo(RolePermissionSeeder::PERMISSION_FINANCE_WALLET_VIEW);
        $owner = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->incidentFor($owner, self::EMAIL, 'RD3');

        $this->actingAs($viewer)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?tab=1')
            ->assertForbidden();
    }

    public function test_agent_drawer_includes_the_wallet_tab(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->incidentFor($agent, self::EMAIL, 'RD4');

        $this->actingAs($agent)
            ->get(route('dashboard.service-cases.customer-360', $incident))
            ->assertOk()
            ->assertSee('data-customer-360-tab="wallet-ledger"', false);
    }

    public function test_compact_drawer_rows_keep_long_identifiers_available(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->verifiedCustomerCase($agent);
        $businessReference = 'RB-'.str_repeat('LONG-REF-', 8);
        $reservationId = 'b288a7b9-1d37-4501-84a0-69c7d1074822';
        $sourceReference = 'users_wallet:2540-with-a-very-long-source-reference';
        $correlationId = 'corr-'.str_repeat('abc123', 8);

        $this->ledgerRow(self::CWID, [
            'entry_type' => 'debit',
            'amount' => '50.00',
            'currency' => 'INR',
            'source_system' => 'rdservice.net',
            'source_reference' => $sourceReference,
            'business_reference' => $businessReference,
            'reservation_id' => $reservationId,
            'correlation_id' => $correlationId,
            'posted_at' => '2026-10-02 22:51:49',
        ]);

        $html = $this->walletHtml($agent, $incident);
        $css = (string) file_get_contents(resource_path('css/app.css'));

        $this->assertStringContainsString('data-c360-wallet-layout="compact"', $html);
        $this->assertStringContainsString('data-entry-type="debit"', $html);
        $this->assertStringContainsString('−₹50.00', $html);
        $this->assertStringContainsString($businessReference, $html);
        $this->assertStringContainsString('title="'.$businessReference.'"', $html);
        $this->assertStringContainsString('data-copy-value="'.$reservationId.'"', $html);
        $this->assertStringContainsString('b288a7b9…4822', $html);
        $this->assertStringContainsString('data-copy-value="'.$sourceReference.'"', $html);
        $this->assertStringContainsString('data-copy-value="'.$correlationId.'"', $html);
        $this->assertStringContainsString('INR', $html);
        $this->assertStringContainsString('rdservice.net', $html);
        $this->assertStringContainsString('02 Oct 2026, 22:51', $html);
        $this->assertStringNotContainsString('<table', $html);
        $this->assertStringContainsString('.c360-wallet-line', $css);
        $this->assertStringContainsString('.c360-wallet-ref', $css);
        $this->assertMatchesRegularExpression(
            '/@media \(max-width: 479\.98px\) \{.*\.c360-wallet-filters/s',
            $css,
        );
    }

    public function test_case_without_verified_email_stays_hidden_when_an_account_link_exists(): void
    {
        $agent = $this->userWithRole(RolePermissionSeeder::ROLE_AGENT);
        $incident = $this->incidentFor($agent, 'rd9064-unverified@example.com', 'RD9064');
        $linkedCustomerId = 'c85258e3-fa6b-4213-97ef-305cb0496238';
        $linkedWalletId = 'dddddddd-eeee-4fff-8aaa-bbbbbbbbd406';

        $this->walletFor($linkedWalletId);
        CentralCustomer::query()->create([
            'id' => $linkedCustomerId,
            'central_wallet_id' => $linkedWalletId,
            'status' => 'active',
        ]);
        DB::table('central_customer_identity_credentials')->insert([
            'desk_customer_id' => $linkedCustomerId,
            'credential_type' => 'migration_cohort_anchor',
            'provider' => 'owner_migration_cohort',
            'subject_hash' => hash('sha256', 'migration-anchor-not-an-email'),
            'verified_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $linkedWalletId,
            'desk_customer_id' => $linkedCustomerId,
            'site_code' => 'rdservice.in',
            'local_user_id' => '557730',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'canonical_account',
            'created_by' => 'test',
        ]);
        $this->ledgerRow($linkedWalletId, [
            'entry_type' => 'credit',
            'amount' => '499.00',
            'source_system' => 'rdservice.in',
            'business_reference' => 'LINKED-WALLET-MUST-STAY-HIDDEN',
            'posted_at' => '2026-10-07 12:00:00',
        ]);

        $html = $this->walletHtml($agent, $incident);

        $this->assertStringContainsString('not linked to one verified Desk customer', $html);
        $this->assertStringNotContainsString('₹', $html);
        $this->assertStringNotContainsString('499.00', $html);
        $this->assertStringNotContainsString('LINKED-WALLET-MUST-STAY-HIDDEN', $html);
        $this->assertStringNotContainsString($linkedWalletId, $html);
    }

    /**
     * @param  array<string, string>  $query
     */
    private function walletHtml(User $viewer, Incident $incident, array $query = []): string
    {
        $query = ['tab' => '1', ...$query];
        Http::fake();

        $response = $this->actingAs($viewer)
            ->getJson(route('dashboard.service-cases.customer-360.wallet-ledger', $incident).'?'.http_build_query($query))
            ->assertOk();

        Http::assertNothingSent();

        return (string) $response->json('html');
    }

    private function userWithRole(string $role): User
    {
        $user = User::factory()->create();
        $user->assignRole($role);

        return $user;
    }

    private function verifiedCustomerCase(User $actor): Incident
    {
        $this->walletFor(self::CWID);
        CentralCustomer::query()->create([
            'id' => self::CUSTOMER_ID,
            'central_wallet_id' => self::CWID,
            'status' => 'active',
        ]);
        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => self::CUSTOMER_ID,
            'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
            'provider' => 'desk_email',
            'subject_hash' => app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail(self::EMAIL),
            'verified_at' => now(),
        ]);

        return $this->incidentFor($actor, self::EMAIL, 'RN172');
    }

    private function incidentFor(User $actor, string $email, string $orderCode): Incident
    {
        $order = Order::query()->create([
            'order_id' => $orderCode,
            'customer_email' => $email,
            'customer_name' => 'Ravi',
            'customer_phone' => '9123456780',
            'status' => 'active',
            'created_by' => $actor->id,
        ]);

        return Incident::query()->create([
            'order_id' => $order->id,
            'reference_no' => app(IncidentReferenceService::class)->generate(),
            'category' => 'General',
            'source' => IncidentSource::Call,
            'title' => 'Wallet case',
            'description' => 'Wallet case.',
            'status' => IncidentStatus::Open,
            'created_by' => $actor->id,
            'updated_by' => $actor->id,
            'assigned_to_user_id' => $actor->id,
        ]);
    }

    private function walletFor(string $cwid): void
    {
        CentralWallet::query()->create([
            'id' => $cwid,
            'status' => 'active',
        ]);
    }

    /**
     * @param  array<string, mixed>  $attributes
     */
    private function ledgerRow(string $cwid, array $attributes = []): CentralWalletLedgerEntry
    {
        $entry = new CentralWalletLedgerEntry;
        $entry->forceFill(array_merge([
            'central_wallet_id' => $cwid,
            'entry_type' => 'credit',
            'amount' => '1.00',
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted,
            'source_system' => 'rdservice.in',
            'source_reference' => null,
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => null,
            'posted_at' => now(),
        ], $attributes));
        $entry->save();

        return $entry;
    }

    private function createVerifiedFiveEntries(): void
    {
        $this->ledgerRow(self::CWID, [
            'id' => 55,
            'entry_type' => 'credit',
            'amount' => '499.00',
            'source_system' => 'rdservice.in',
            'source_reference' => 'users_wallet:2540',
            'business_reference' => 'REF-2026-000300',
            'posted_at' => '2026-10-02 11:55:57',
        ]);
        $this->ledgerRow(self::CWID, [
            'id' => 56,
            'entry_type' => 'debit',
            'amount' => '50.00',
            'source_system' => 'rdservice.in',
            'source_reference' => 'checkout:rd_service',
            'business_reference' => 'RD13874',
            'posted_at' => '2026-10-02 15:56:47',
        ]);
        $this->ledgerRow(self::CWID, [
            'id' => 57,
            'entry_type' => 'debit',
            'amount' => '50.00',
            'source_system' => 'rdservice.net',
            'source_reference' => 'checkout:rd_service',
            'business_reference' => 'RN172',
            'posted_at' => '2026-10-02 22:51:49',
        ]);
        $this->ledgerRow(self::CWID, [
            'id' => 58,
            'entry_type' => 'debit',
            'amount' => '1.00',
            'source_system' => 'rdservice.in',
            'business_reference' => 'PROBE-NO-OP',
            'posted_at' => '2026-10-04 20:12:29',
        ]);
        $this->ledgerRow(self::CWID, [
            'id' => 59,
            'entry_type' => 'reversal',
            'amount' => '1.00',
            'source_system' => 'rdservice.in',
            'source_reference' => 'corrective_reversal:ledger_entry:58',
            'business_reference' => 'PROBE-NO-OP-CORRECTION',
            'original_ledger_entry_id' => 58,
            'posted_at' => '2026-10-04 20:17:02',
        ]);
    }
}
