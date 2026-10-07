<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralWalletCustomerLedgerHistoryReadApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-customer-history-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.ledger_read.default_page_size' => 10,
            'central_wallet.ledger_read.max_page_size' => 50,
            'central_wallet.ledger_read.customer_history.enabled' => true,
            'central_wallet.ledger_read.customer_history.authorized_callers' => [
                'radiumbox.com',
                'rdservice.in',
                'rdservice.net',
            ],
            'central_wallet.ledger_read.customer_history.authorized_source_systems' => [
                'radiumbox.com',
                'rdservice.in',
                'rdservice.net',
            ],
        ]);
    }

    public function test_customer_history_requires_authentication(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');

        $this->flushHeaders();
        $this->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3")
            ->assertUnauthorized();
    }

    public function test_customer_history_rejects_invalid_token(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');

        $this->withToken('invalid')
            ->withHeader('X-Site-Code', 'radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3")
            ->assertUnauthorized();
    }

    public function test_customer_history_requires_local_user_id(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');

        $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history")
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_error');
    }

    public function test_customer_history_is_disabled_when_feature_flag_off(): void
    {
        config(['central_wallet.ledger_read.customer_history.enabled' => false]);

        $cwid = $this->createWalletAs('radiumbox.com');
        $this->createTrustedLink($cwid, 'radiumbox.com', '3');

        $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3")
            ->assertStatus(503)
            ->assertJsonPath('error', 'customer_history_read_disabled');
    }

    public function test_customer_history_rejects_wrong_cwid_for_trusted_user(): void
    {
        $cwidA = $this->createWalletAs('radiumbox.com');
        $cwidB = $this->createWalletAs('radiumbox.com');
        $this->createTrustedLink($cwidA, 'radiumbox.com', '3');

        $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwidB}/ledger-history?local_user_id=3")
            ->assertForbidden()
            ->assertJsonPath('error', 'trusted_account_link_required');
    }

    public function test_customer_history_rejects_untrusted_verification_method(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $this->createTrustedLink($cwid, 'radiumbox.com', '3', 'm4_expedited');

        $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3")
            ->assertForbidden()
            ->assertJsonPath('error', 'trusted_identity_required');
    }

    public function test_customer_history_rejects_revoked_account_link(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $linkId = $this->createTrustedLink($cwid, 'radiumbox.com', '3');

        DB::table('central_wallet_account_links')->where('id', $linkId)->update([
            'status' => AccountLinkStatus::Revoked->value,
        ]);

        $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3")
            ->assertForbidden()
            ->assertJsonPath('error', 'trusted_account_link_required');
    }

    public function test_radiumbox_customer_history_returns_cross_spoke_entries_for_trusted_user(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $this->createTrustedLink($cwid, 'radiumbox.com', '3');

        $creditId = $this->createLedgerRow($cwid, 'rdservice.in', '2026-10-02 11:55:00', '300', [
            'entry_type' => 'credit',
            'amount' => '499.00',
            'business_reference' => 'REF-2026-000300',
        ]);
        $debitRdIn = $this->createLedgerRow($cwid, 'rdservice.in', '2026-10-02 15:56:00', '301', [
            'entry_type' => 'debit',
            'amount' => '50.00',
            'business_reference' => 'RD13874',
        ]);
        $debitNet = $this->createLedgerRow($cwid, 'rdservice.net', '2026-10-02 20:30:00', '302', [
            'entry_type' => 'debit',
            'amount' => '50.00',
            'business_reference' => 'RN172',
        ]);
        $this->createLedgerRow($cwid, 'other-system.example', '2026-10-03 10:00:00', '999');

        $response = $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3")
            ->assertOk();

        $response->assertJsonCount(3, 'data')
            ->assertJsonPath('data.0.ledger_entry_id', $debitNet)
            ->assertJsonPath('data.1.ledger_entry_id', $debitRdIn)
            ->assertJsonPath('data.2.ledger_entry_id', $creditId)
            ->assertJsonPath('data.0.business_reference', 'RN172')
            ->assertJsonPath('data.2.business_reference', 'REF-2026-000300');
    }

    public function test_site_scoped_ledger_entries_remain_caller_filtered(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $this->createTrustedLink($cwid, 'radiumbox.com', '3');

        $this->createLedgerRow($cwid, 'rdservice.in', '2026-10-02 11:55:00', '300');
        $boxEntry = $this->createLedgerRow($cwid, 'radiumbox.com', '2026-10-02 12:00:00', '301');

        $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries")
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ledger_entry_id', $boxEntry);

        $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_rdservice_in_and_net_callers_can_read_cross_spoke_customer_history(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $this->createTrustedLink($cwid, 'rdservice.in', '3');
        $this->createTrustedLink($cwid, 'rdservice.net', '3');

        $this->createLedgerRow($cwid, 'rdservice.in', '2026-10-02 11:55:00', '300');
        $this->createLedgerRow($cwid, 'rdservice.net', '2026-10-02 20:30:00', '301');

        $this->authenticatedAs('rdservice.in')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3")
            ->assertOk()
            ->assertJsonCount(2, 'data');

        $this->authenticatedAs('rdservice.net')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3")
            ->assertOk()
            ->assertJsonCount(2, 'data');
    }

    public function test_customer_history_returns_empty_array_when_no_entries_exist(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $this->createTrustedLink($cwid, 'radiumbox.com', '3');

        $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3")
            ->assertOk()
            ->assertJsonPath('pagination.has_more', false)
            ->assertJsonCount(0, 'data');
    }

    public function test_customer_history_rejects_invalid_cwid(): void
    {
        $this->authenticatedAs('radiumbox.com')
            ->getJson('/api/central-wallet/v1/wallets/not-a-uuid/ledger-history?local_user_id=3')
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_cwid');
    }

    public function test_customer_history_rejects_unauthorized_caller(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $this->createTrustedLink($cwid, 'radiumbox.com', '3');

        $this->authenticatedAs('other.example')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3")
            ->assertForbidden()
            ->assertJsonPath('error', 'unauthorized_caller');
    }

    public function test_customer_history_paginates_with_limit(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $this->createTrustedLink($cwid, 'radiumbox.com', '3', 'm2_dual_otp');
        $this->createVerifiedFiveEntries($cwid);

        $first = $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3&limit=2")
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.limit', 2)
            ->assertJsonPath('pagination.has_more', true);

        $cursor = (string) $first->json('pagination.next_cursor');
        $this->assertNotSame('', $cursor);

        $second = $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3&limit=2&cursor=".urlencode($cursor))
            ->assertOk()
            ->assertJsonCount(2, 'data')
            ->assertJsonPath('pagination.has_more', true);

        $this->assertNotSame($first->json('data.0.ledger_entry_id'), $second->json('data.0.ledger_entry_id'));
    }

    public function test_verified_five_posted_entries_are_returned_without_mutating_the_ledger(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $this->createTrustedLink($cwid, 'radiumbox.com', '3', 'm2_dual_otp');
        $this->createVerifiedFiveEntries($cwid);

        $before = CentralWalletLedgerEntry::query()
            ->where('central_wallet_id', $cwid)
            ->orderBy('id')
            ->get(['id', 'entry_type', 'amount', 'source_system', 'business_reference', 'status'])
            ->map(fn (CentralWalletLedgerEntry $entry): array => [
                'id' => $entry->id,
                'entry_type' => $entry->entry_type->value,
                'amount' => (string) $entry->amount,
                'source_system' => $entry->source_system,
                'business_reference' => $entry->business_reference,
                'status' => $entry->status->value,
            ])
            ->all();

        $response = $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3&limit=50")
            ->assertOk()
            ->assertJsonCount(5, 'data')
            ->assertJsonPath('pagination.has_more', false)
            ->assertJsonPath('data.0.entry_type', 'reversal')
            ->assertJsonPath('data.0.amount', '1.00')
            ->assertJsonPath('data.0.business_reference', 'PROBE-NO-OP-CORRECTION')
            ->assertJsonPath('data.1.entry_type', 'debit')
            ->assertJsonPath('data.1.amount', '1.00')
            ->assertJsonPath('data.1.business_reference', 'PROBE-NO-OP')
            ->assertJsonPath('data.2.entry_type', 'debit')
            ->assertJsonPath('data.2.amount', '50.00')
            ->assertJsonPath('data.2.business_reference', 'RN172')
            ->assertJsonPath('data.2.source_system', 'rdservice.net')
            ->assertJsonPath('data.3.entry_type', 'debit')
            ->assertJsonPath('data.3.amount', '50.00')
            ->assertJsonPath('data.3.business_reference', 'RD13874')
            ->assertJsonPath('data.4.entry_type', 'credit')
            ->assertJsonPath('data.4.amount', '499.00')
            ->assertJsonPath('data.4.business_reference', 'REF-2026-000300')
            ->assertJsonPath('data.4.source_system', 'rdservice.in');

        $net = 0.0;
        foreach ($response->json('data') as $row) {
            $amount = (float) $row['amount'];
            $net += $row['entry_type'] === 'debit' ? -$amount : $amount;
        }

        $this->assertSame(399.0, round($net, 2));

        $after = CentralWalletLedgerEntry::query()
            ->where('central_wallet_id', $cwid)
            ->orderBy('id')
            ->get(['id', 'entry_type', 'amount', 'source_system', 'business_reference', 'status'])
            ->map(fn (CentralWalletLedgerEntry $entry): array => [
                'id' => $entry->id,
                'entry_type' => $entry->entry_type->value,
                'amount' => (string) $entry->amount,
                'source_system' => $entry->source_system,
                'business_reference' => $entry->business_reference,
                'status' => $entry->status->value,
            ])
            ->all();

        $this->assertSame($before, $after);
        $this->assertSame(5, CentralWalletLedgerEntry::query()->where('central_wallet_id', $cwid)->count());
    }

    private function createWalletAs(string $siteCode): string
    {
        $response = $this->authenticatedAs($siteCode)->postJson('/api/central-wallet/v1/wallets', [
            'idempotency_key' => 'create-'.$siteCode.'-'.uniqid('', true),
        ]);

        $response->assertCreated();

        return (string) $response->json('central_wallet_id');
    }

    private function createTrustedLink(string $cwid, string $siteCode, string $localUserId, string $verificationMethod = 'verified_email'): int
    {
        $response = $this->authenticatedAs($siteCode)->postJson('/api/central-wallet/v1/account-links', [
            'idempotency_key' => 'link-'.$siteCode.'-'.$localUserId.'-'.uniqid('', true),
            'central_wallet_id' => $cwid,
            'site_code' => $siteCode,
            'local_user_id' => $localUserId,
            'created_by' => 'service:test',
        ])->assertCreated();

        $linkId = (int) $response->json('link_id');

        DB::table('central_wallet_account_links')->where('id', $linkId)->update([
            'status' => AccountLinkStatus::Active->value,
            'linked_at' => now(),
            'verification_method' => $verificationMethod,
        ]);

        return $linkId;
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createLedgerRow(
        string $cwid,
        string $sourceSystem,
        string $postedAt,
        string $sourceReference,
        array $overrides = [],
    ): int {
        $entry = CentralWalletLedgerEntry::query()->create(array_merge([
            'central_wallet_id' => $cwid,
            'entry_type' => 'credit',
            'amount' => '10.00',
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted,
            'source_system' => $sourceSystem,
            'source_reference' => $sourceReference,
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => null,
            'posted_at' => CarbonImmutable::parse($postedAt),
        ], $overrides));

        return $entry->id;
    }

    private function createVerifiedFiveEntries(string $cwid): void
    {
        $this->createLedgerRow($cwid, 'rdservice.in', '2026-10-02 11:55:57', 'users_wallet:2540', [
            'entry_type' => 'credit',
            'amount' => '499.00',
            'business_reference' => 'REF-2026-000300',
        ]);
        $this->createLedgerRow($cwid, 'rdservice.in', '2026-10-02 15:56:47', 'checkout:rd_service', [
            'entry_type' => 'debit',
            'amount' => '50.00',
            'business_reference' => 'RD13874',
        ]);
        $this->createLedgerRow($cwid, 'rdservice.net', '2026-10-02 22:51:49', 'checkout:rd_service', [
            'entry_type' => 'debit',
            'amount' => '50.00',
            'business_reference' => 'RN172',
        ]);
        $this->createLedgerRow($cwid, 'rdservice.in', '2026-10-04 20:12:29', '', [
            'entry_type' => 'debit',
            'amount' => '1.00',
            'source_reference' => null,
            'business_reference' => 'PROBE-NO-OP',
        ]);
        $this->createLedgerRow($cwid, 'rdservice.in', '2026-10-04 20:17:02', 'corrective_reversal:ledger_entry:58', [
            'entry_type' => 'reversal',
            'amount' => '1.00',
            'business_reference' => 'PROBE-NO-OP-CORRECTION',
        ]);
    }

    private function authenticatedAs(string $siteCode): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $siteCode,
        ]);
    }
}
