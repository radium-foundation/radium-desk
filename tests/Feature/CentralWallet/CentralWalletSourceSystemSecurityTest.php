<?php

namespace Tests\Feature\CentralWallet;

use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralWalletSourceSystemSecurityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-source-system-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
        ]);
    }

    public function test_rejects_source_system_spoof_for_authenticated_site(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'spoof-1',
            'entry_type' => 'credit',
            'amount' => '5.00',
            'source_system' => 'rdservice.in',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'source_system_mismatch');
    }

    public function test_derives_source_system_from_site_header_when_body_omitted(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'derived-1',
            'entry_type' => 'credit',
            'amount' => '7.00',
        ])->assertCreated();

        $this->assertDatabaseHas('central_wallet_ledger_entries', [
            'central_wallet_id' => $cwid,
            'source_system' => 'radiumbox.com',
        ]);
    }

    public function test_matching_source_system_in_body_is_accepted(): void
    {
        $cwid = $this->createWalletAs('rdservice.in');

        $this->authenticatedAs('rdservice.in')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'match-1',
            'entry_type' => 'credit',
            'amount' => '3.00',
            'source_system' => 'rdservice.in',
        ])->assertCreated();
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
