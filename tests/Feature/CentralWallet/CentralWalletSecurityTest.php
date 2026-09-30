<?php

namespace Tests\Feature\CentralWallet;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralWalletSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-security-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
        ]);
    }

    public function test_rejects_missing_bearer_token(): void
    {
        $this->getJson('/api/central-wallet/v1/health')->assertUnauthorized();
    }

    public function test_rejects_invalid_bearer_token(): void
    {
        $this->withToken('wrong-token')
            ->getJson('/api/central-wallet/v1/health')
            ->assertUnauthorized();
    }

    public function test_rejects_when_integration_token_not_configured(): void
    {
        config(['central_wallet.integration_token' => '']);

        $this->withToken(self::TOKEN)
            ->getJson('/api/central-wallet/v1/health')
            ->assertUnauthorized();
    }

    public function test_mutating_endpoints_require_authentication(): void
    {
        $this->postJson('/api/central-wallet/v1/wallets', [
            'idempotency_key' => 'unauth',
        ])->assertUnauthorized();
    }

    public function test_idempotency_is_scoped_by_site_caller_header(): void
    {
        $cwid = $this->createWalletAs('rdservice.in');

        $payload = [
            'idempotency_key' => 'scoped-credit',
            'entry_type' => 'credit',
            'amount' => '3.00',
        ];

        $first = $this->authenticatedAs('rdservice.in')
            ->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", $payload)
            ->assertCreated();

        $firstEntryId = $first->json('ledger_entry_id');

        $second = $this->authenticatedAs('radiumbox.com')
            ->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", $payload)
            ->assertCreated();

        $this->assertNotSame($firstEntryId, $second->json('ledger_entry_id'));
        $this->assertDatabaseCount('central_wallet_ledger_entries', 2);
    }

    private function createWalletAs(string $siteCode): string
    {
        $response = $this->authenticatedAs($siteCode)->postJson('/api/central-wallet/v1/wallets', [
            'idempotency_key' => 'create-'.$siteCode.'-'.uniqid('', true),
        ]);

        $response->assertCreated();

        return (string) $response->json('central_wallet_id');
    }

    private function authenticatedAs(string $siteCode): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $siteCode,
        ]);
    }
}
