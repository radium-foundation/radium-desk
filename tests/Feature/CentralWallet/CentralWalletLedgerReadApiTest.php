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

class CentralWalletLedgerReadApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-ledger-read-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.ledger_read.default_page_size' => 2,
            'central_wallet.ledger_read.max_page_size' => 5,
            'central_wallet.ledger_read.max_date_range_days' => 31,
        ]);
    }

    public function test_read_endpoints_require_authentication(): void
    {
        $this->getJson('/api/central-wallet/v1/ledger-entries')->assertUnauthorized();
        $this->getJson('/api/central-wallet/v1/ledger-entries/1')->assertUnauthorized();

        $cwid = $this->createWalletAs('radiumbox.com');

        $this->flushHeaders();
        $this->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries")->assertUnauthorized();
    }

    public function test_read_endpoints_reject_invalid_token(): void
    {
        $this->withToken('invalid')
            ->withHeader('X-Site-Code', 'radiumbox.com')
            ->getJson('/api/central-wallet/v1/ledger-entries')
            ->assertUnauthorized();
    }

    public function test_single_entry_returns_expected_fields_for_caller(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $entryId = $this->appendLedgerEntry($cwid, 'radiumbox.com', [
            'source_reference' => '1137',
            'business_reference' => 'RADBOX:RDE1',
        ]);

        $response = $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/ledger-entries/{$entryId}")
            ->assertOk();

        $response->assertJsonPath('ledger_entry_id', $entryId)
            ->assertJsonPath('central_wallet_id', $cwid)
            ->assertJsonPath('entry_type', 'credit')
            ->assertJsonPath('amount', '10.00')
            ->assertJsonPath('currency', 'INR')
            ->assertJsonPath('status', 'posted')
            ->assertJsonPath('source_system', 'radiumbox.com')
            ->assertJsonPath('source_reference', '1137')
            ->assertJsonPath('business_reference', 'RADBOX:RDE1')
            ->assertJsonMissing(['metadata', 'idempotency_key']);

        $this->assertArrayNotHasKey('metadata', $response->json());
    }

    public function test_single_entry_returns_not_found_for_nonexistent_id(): void
    {
        $this->authenticatedAs('radiumbox.com')
            ->getJson('/api/central-wallet/v1/ledger-entries/999999')
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
    }

    public function test_cross_caller_single_entry_lookup_returns_not_found(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $entryId = $this->appendLedgerEntry($cwid, 'radiumbox.com');

        $this->authenticatedAs('rdservice.in')
            ->getJson("/api/central-wallet/v1/ledger-entries/{$entryId}")
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
    }

    public function test_single_entry_rejects_central_wallet_id_mismatch(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $otherCwid = $this->createWalletAs('radiumbox.com');
        $entryId = $this->appendLedgerEntry($cwid, 'radiumbox.com');

        $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/ledger-entries/{$entryId}?central_wallet_id={$otherCwid}")
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
    }

    public function test_wallet_scoped_list_requires_caller_access(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $this->appendLedgerEntry($cwid, 'radiumbox.com');

        $this->authenticatedAs('rdservice.in')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries")
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
    }

    public function test_wallet_scoped_list_allows_active_account_link_without_prior_entries(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');

        $this->authenticatedAs('radiumbox.com')->postJson('/api/central-wallet/v1/account-links', [
            'idempotency_key' => 'link-read-1',
            'central_wallet_id' => $cwid,
            'site_code' => 'radiumbox.com',
            'local_user_id' => '42',
            'created_by' => 'service:test',
        ])->assertCreated();

        $linkId = DB::table('central_wallet_account_links')->value('id');
        DB::table('central_wallet_account_links')->where('id', $linkId)->update([
            'status' => AccountLinkStatus::Active->value,
            'linked_at' => now(),
            'verification_method' => 'm4_expedited',
        ]);

        $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries")
            ->assertOk()
            ->assertJsonPath('pagination.has_more', false)
            ->assertJsonCount(0, 'data');
    }

    public function test_wallet_scoped_list_filters_by_caller_and_orders_deterministically(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');
        $firstId = $this->createLedgerRow($cwid, 'radiumbox.com', '2026-09-01 10:00:00', '100');
        $secondId = $this->createLedgerRow($cwid, 'radiumbox.com', '2026-09-01 10:00:00', '101');
        $thirdId = $this->createLedgerRow($cwid, 'radiumbox.com', '2026-09-02 10:00:00', '102');

        $this->createLedgerRow($cwid, 'rdservice.in', '2026-09-03 10:00:00', '999');

        $firstPage = $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries?limit=2")
            ->assertOk();

        $firstPage->assertJsonPath('pagination.limit', 2)
            ->assertJsonPath('pagination.has_more', true)
            ->assertJsonPath('data.0.ledger_entry_id', $firstId)
            ->assertJsonPath('data.1.ledger_entry_id', $secondId);

        $cursor = (string) $firstPage->json('pagination.next_cursor');

        $secondPage = $this->authenticatedAs('radiumbox.com')
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries?limit=2&cursor={$cursor}")
            ->assertOk();

        $secondPage->assertJsonPath('pagination.has_more', false)
            ->assertJsonPath('data.0.ledger_entry_id', $thirdId)
            ->assertJsonCount(1, 'data');
    }

    public function test_generic_sweep_is_caller_scoped_and_supports_filters(): void
    {
        $cwidA = $this->createWalletAs('radiumbox.com');
        $cwidB = $this->createWalletAs('radiumbox.com');

        $targetId = $this->createLedgerRow($cwidA, 'radiumbox.com', '2026-09-10 12:00:00', '200', [
            'source_reference' => '50042',
            'business_reference' => 'RADBOX:RDE2',
        ]);
        $this->createLedgerRow($cwidB, 'radiumbox.com', '2026-09-11 12:00:00', '201');
        $this->createLedgerRow($cwidA, 'rdservice.in', '2026-09-12 12:00:00', '202');

        $this->authenticatedAs('radiumbox.com')
            ->getJson('/api/central-wallet/v1/ledger-entries?source_reference=50042')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ledger_entry_id', $targetId);

        $this->authenticatedAs('radiumbox.com')
            ->getJson('/api/central-wallet/v1/ledger-entries?central_wallet_id='.$cwidB)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.central_wallet_id', $cwidB);

        $this->authenticatedAs('radiumbox.com')
            ->getJson('/api/central-wallet/v1/ledger-entries?ledger_entry_id='.$targetId)
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ledger_entry_id', $targetId);

        $this->authenticatedAs('radiumbox.com')
            ->getJson('/api/central-wallet/v1/ledger-entries?posted_from=2026-09-10T00:00:00Z&posted_to=2026-09-11T00:00:00Z')
            ->assertOk()
            ->assertJsonCount(1, 'data')
            ->assertJsonPath('data.0.ledger_entry_id', $targetId);
    }

    public function test_generic_sweep_rejects_source_system_override(): void
    {
        $this->authenticatedAs('radiumbox.com')
            ->getJson('/api/central-wallet/v1/ledger-entries?source_system=rdservice.in')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_error');
    }

    public function test_invalid_cursor_limit_and_date_range_fail_safely(): void
    {
        $this->authenticatedAs('radiumbox.com')
            ->getJson('/api/central-wallet/v1/ledger-entries?cursor=not-a-valid-cursor')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_error');

        $this->authenticatedAs('radiumbox.com')
            ->getJson('/api/central-wallet/v1/ledger-entries?limit=99')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_error');

        $this->authenticatedAs('radiumbox.com')
            ->getJson('/api/central-wallet/v1/ledger-entries?posted_from=2026-09-10T00:00:00Z&posted_to=2026-10-15T00:00:00Z')
            ->assertStatus(422)
            ->assertJsonPath('error', 'validation_error');
    }

    public function test_error_responses_do_not_leak_secrets(): void
    {
        $response = $this->withHeaders([
            'Authorization' => 'Bearer wrong-token',
            'X-Site-Code' => 'radiumbox.com',
        ])->getJson('/api/central-wallet/v1/ledger-entries');

        $response->assertUnauthorized();
        $body = strtolower(json_encode($response->json(), JSON_THROW_ON_ERROR));
        $this->assertStringNotContainsString(self::TOKEN, $body);
        $this->assertStringNotContainsString('stack', $body);
    }

    private function createWalletAs(string $siteCode): string
    {
        $response = $this->authenticatedAs($siteCode)->postJson('/api/central-wallet/v1/wallets', [
            'idempotency_key' => 'create-'.$siteCode.'-'.uniqid('', true),
        ]);

        $response->assertCreated();

        return (string) $response->json('central_wallet_id');
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function appendLedgerEntry(string $cwid, string $siteCode, array $overrides = []): int
    {
        $payload = array_merge([
            'idempotency_key' => 'credit-'.uniqid('', true),
            'entry_type' => 'credit',
            'amount' => '10.00',
            'source_system' => $siteCode,
        ], $overrides);

        $response = $this->authenticatedAs($siteCode)
            ->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", $payload)
            ->assertCreated();

        return (int) $response->json('ledger_entry_id');
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

    private function authenticatedAs(string $siteCode): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $siteCode,
        ]);
    }
}
