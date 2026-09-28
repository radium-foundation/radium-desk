<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAuditEvent;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletIdempotencyRecord;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CentralWalletApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-test-integration-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.idempotency_retention_days' => 90,
        ]);
    }

    public function test_health_requires_authentication_when_api_enabled(): void
    {
        $this->getJson('/api/central-wallet/v1/health')
            ->assertUnauthorized();

        $this->withToken(self::TOKEN)
            ->getJson('/api/central-wallet/v1/health')
            ->assertOk()
            ->assertJson(['status' => 'ok', 'service' => 'central_wallet']);
    }

    public function test_creates_uuid_v4_central_wallet(): void
    {
        $response = $this->authenticated()->postJson('/api/central-wallet/v1/wallets', [
            'idempotency_key' => 'create-wallet-1',
        ]);

        $response->assertCreated();
        $cwid = (string) $response->json('central_wallet_id');

        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $cwid,
        );

        $this->assertDatabaseHas('central_wallet_audit_events', [
            'event_type' => 'wallet.created',
            'central_wallet_id' => $cwid,
        ]);
    }

    public function test_account_link_uses_site_and_local_user_not_email(): void
    {
        $cwid = $this->createWallet();

        $response = $this->authenticated()->postJson('/api/central-wallet/v1/account-links', [
            'idempotency_key' => 'link-1',
            'central_wallet_id' => $cwid,
            'site_code' => 'rdservice.in',
            'local_user_id' => '77',
            'created_by' => 'service:test',
            'verification_method' => 'm2_dual_otp',
        ]);

        $response->assertCreated()
            ->assertJsonPath('site_code', 'rdservice.in')
            ->assertJsonPath('local_user_id', '77')
            ->assertJsonPath('status', AccountLinkStatus::PendingVerification->value);

        $this->assertDatabaseMissing('central_wallet_account_links', [
            'site_code' => 'user@example.com',
        ]);
    }

    public function test_active_link_lookup_and_explicit_state_transition(): void
    {
        $cwid = $this->createWallet();

        $this->authenticated()->postJson('/api/central-wallet/v1/account-links', [
            'idempotency_key' => 'link-2',
            'central_wallet_id' => $cwid,
            'site_code' => 'rdservice.in',
            'local_user_id' => '88',
            'created_by' => 'service:test',
        ])->assertCreated();

        $this->authenticated()->getJson('/api/central-wallet/v1/account-links?site_code=rdservice.in&local_user_id=88')
            ->assertOk()
            ->assertJsonPath('link', null);

        $linkId = DB::table('central_wallet_account_links')->value('id');
        DB::table('central_wallet_account_links')->where('id', $linkId)->update([
            'status' => AccountLinkStatus::Active->value,
            'linked_at' => now(),
            'verification_method' => 'm4_expedited',
        ]);

        $this->authenticated()->getJson('/api/central-wallet/v1/account-links?site_code=rdservice.in&local_user_id=88')
            ->assertOk()
            ->assertJsonPath('link.central_wallet_id', $cwid)
            ->assertJsonPath('link.status', AccountLinkStatus::Active->value);
    }

    public function test_ledger_is_append_only_and_balance_updates(): void
    {
        $cwid = $this->createWallet();

        $this->authenticated()->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'credit-1',
            'entry_type' => 'credit',
            'amount' => '100.00',
            'source_system' => 'test_harness',
            'source_reference' => 'desk_refund:REF-1',
            'business_reference' => 'order:RD1',
        ])->assertCreated();

        $entryCount = CentralWalletLedgerEntry::query()->count();
        $this->assertSame(1, $entryCount);

        $this->authenticated()->getJson("/api/central-wallet/v1/wallets/{$cwid}/balance")
            ->assertOk()
            ->assertJsonPath('available_balance', '100.00');

        $this->authenticated()->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'debit-1',
            'entry_type' => 'debit',
            'amount' => '25.50',
            'source_system' => 'test_harness',
            'source_reference' => 'txn:1',
            'business_reference' => 'order:RD1',
        ])->assertCreated();

        $this->assertSame(2, CentralWalletLedgerEntry::query()->count());

        $this->authenticated()->getJson("/api/central-wallet/v1/wallets/{$cwid}/balance")
            ->assertOk()
            ->assertJsonPath('available_balance', '74.50');
    }

    public function test_idempotency_prevents_duplicate_financial_effects(): void
    {
        $cwid = $this->createWallet();

        $payload = [
            'idempotency_key' => 'credit-dup',
            'entry_type' => 'credit',
            'amount' => '10.00',
            'source_system' => 'test_harness',
        ];

        $firstResponse = $this->authenticated()->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", $payload)
            ->assertCreated()
            ->assertJsonMissing(['idempotent_replay' => true]);

        $firstEntryId = $firstResponse->json('ledger_entry_id');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->count());

        $this->authenticated()->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", $payload)
            ->assertStatus(201)
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('ledger_entry_id', $firstEntryId)
            ->assertJsonPath('amount', '10.00')
            ->assertJsonPath('entry_type', 'credit');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->count());
        $this->assertSame(1, CentralWalletIdempotencyRecord::query()->where('idempotency_key', 'credit-dup')->count());
    }

    public function test_wallet_create_idempotency_replay_returns_full_body(): void
    {
        $first = $this->authenticated()->postJson('/api/central-wallet/v1/wallets', [
            'idempotency_key' => 'create-replay-key',
        ])->assertCreated();

        $cwid = (string) $first->json('central_wallet_id');

        $this->authenticated()->postJson('/api/central-wallet/v1/wallets', [
            'idempotency_key' => 'create-replay-key',
        ])
            ->assertStatus(201)
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('central_wallet_id', $cwid)
            ->assertJsonPath('status', 'active');
    }

    public function test_concurrent_idempotency_requests_do_not_duplicate_entries(): void
    {
        $cwid = $this->createWallet();

        DB::transaction(function () use ($cwid): void {
            $this->authenticated()->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
                'idempotency_key' => 'credit-concurrent',
                'entry_type' => 'credit',
                'amount' => '5.00',
                'source_system' => 'test_harness',
            ])->assertCreated();
        });

        $this->authenticated()->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'credit-concurrent',
            'entry_type' => 'credit',
            'amount' => '5.00',
            'source_system' => 'test_harness',
        ])->assertStatus(201);

        $this->assertSame(1, CentralWalletLedgerEntry::query()->count());
    }

    public function test_idempotency_key_reuse_with_different_payload_returns_conflict(): void
    {
        $cwid = $this->createWallet();

        $this->authenticated()->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'same-key',
            'entry_type' => 'credit',
            'amount' => '1.00',
            'source_system' => 'test_harness',
        ])->assertCreated();

        $this->authenticated()->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'same-key',
            'entry_type' => 'credit',
            'amount' => '2.00',
            'source_system' => 'test_harness',
        ])->assertStatus(409);
    }

    public function test_wallet_reservation_endpoint_returns_not_implemented(): void
    {
        $this->authenticated()->postJson('/api/central-wallet/v1/wallet-reservations', [])
            ->assertStatus(501);
    }

    public function test_correlation_id_is_returned(): void
    {
        $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Correlation-Id' => 'corr-test-123',
        ])->getJson('/api/central-wallet/v1/health')
            ->assertOk()
            ->assertHeader('X-Correlation-Id', 'corr-test-123');
    }

    public function test_audit_events_recorded_for_wallet_creation(): void
    {
        $this->createWallet();

        $this->assertGreaterThan(0, CentralWalletAuditEvent::query()->where('event_type', 'wallet.created')->count());
    }

    public function test_confirm_pending_account_link_activates_link_for_caller_site(): void
    {
        $cwid = $this->createWallet();

        $create = $this->authenticatedAs('radiumbox.com')->postJson('/api/central-wallet/v1/account-links', [
            'idempotency_key' => 'link-confirm-1',
            'central_wallet_id' => $cwid,
            'site_code' => 'radiumbox.com',
            'local_user_id' => '501',
            'created_by' => 'service:radiumbox',
            'verification_method' => 'm2_dual_otp',
        ])->assertCreated();

        $linkId = (int) $create->json('link_id');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/account-links/{$linkId}/confirm", [
            'idempotency_key' => 'confirm-1',
            'verification_method' => 'm2_dual_otp',
            'actor_id' => 'customer:501',
        ])
            ->assertOk()
            ->assertJsonPath('status', AccountLinkStatus::Active->value)
            ->assertJsonPath('verification_method', 'm2_dual_otp');

        $this->authenticatedAs('radiumbox.com')->getJson('/api/central-wallet/v1/account-links?site_code=radiumbox.com&local_user_id=501')
            ->assertOk()
            ->assertJsonPath('link.central_wallet_id', $cwid)
            ->assertJsonPath('link.status', AccountLinkStatus::Active->value);
    }

    public function test_confirm_is_idempotent_for_active_link(): void
    {
        $cwid = $this->createWallet();
        $linkId = $this->createPendingLink('radiumbox.com', '502', $cwid);

        $payload = [
            'idempotency_key' => 'confirm-idem',
            'verification_method' => 'm2_dual_otp',
            'actor_id' => 'customer:502',
        ];

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/account-links/{$linkId}/confirm", $payload)
            ->assertOk();

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/account-links/{$linkId}/confirm", $payload)
            ->assertOk()
            ->assertJsonPath('status', AccountLinkStatus::Active->value);
    }

    public function test_confirm_rejects_cross_site_link(): void
    {
        $cwid = $this->createWallet();
        $linkId = $this->createPendingLink('radiumbox.com', '503', $cwid);

        $this->authenticatedAs('rdservice.in')->postJson("/api/central-wallet/v1/account-links/{$linkId}/confirm", [
            'idempotency_key' => 'confirm-cross',
            'verification_method' => 'm2_dual_otp',
            'actor_id' => 'customer:503',
        ])->assertNotFound();
    }

    public function test_confirm_rejects_verification_method_mismatch(): void
    {
        $cwid = $this->createWallet();

        $create = $this->authenticatedAs('radiumbox.com')->postJson('/api/central-wallet/v1/account-links', [
            'idempotency_key' => 'link-method-mismatch',
            'central_wallet_id' => $cwid,
            'site_code' => 'radiumbox.com',
            'local_user_id' => '505',
            'created_by' => 'service:test',
            'verification_method' => 'm2_dual_otp',
        ])->assertCreated();

        $linkId = (int) $create->json('link_id');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/account-links/{$linkId}/confirm", [
            'idempotency_key' => 'confirm-method-mismatch',
            'verification_method' => 'm3_ops',
            'actor_id' => 'customer:505',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'verification_method_mismatch');
    }

    public function test_confirm_rejects_revoked_link(): void
    {
        $cwid = $this->createWallet();
        $linkId = $this->createPendingLink('radiumbox.com', '504', $cwid);

        DB::table('central_wallet_account_links')->where('id', $linkId)->update([
            'status' => AccountLinkStatus::Revoked->value,
            'revoked_at' => now(),
        ]);

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/account-links/{$linkId}/confirm", [
            'idempotency_key' => 'confirm-revoked',
            'verification_method' => 'm2_dual_otp',
            'actor_id' => 'customer:504',
        ])->assertStatus(409)
            ->assertJsonPath('error', 'link_revoked');
    }

    private function createPendingLink(string $siteCode, string $localUserId, string $cwid): int
    {
        $response = $this->authenticatedAs($siteCode)->postJson('/api/central-wallet/v1/account-links', [
            'idempotency_key' => 'link-'.uniqid('', true),
            'central_wallet_id' => $cwid,
            'site_code' => $siteCode,
            'local_user_id' => $localUserId,
            'created_by' => 'service:test',
        ])->assertCreated();

        return (int) $response->json('link_id');
    }

    private function authenticatedAs(string $siteCode): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $siteCode,
        ]);
    }

    private function createWallet(): string
    {
        $response = $this->authenticated()->postJson('/api/central-wallet/v1/wallets', [
            'idempotency_key' => 'create-'.uniqid('', true),
        ]);

        $response->assertCreated();

        return (string) $response->json('central_wallet_id');
    }

    private function authenticated(): self
    {
        return $this->withToken(self::TOKEN);
    }
}
