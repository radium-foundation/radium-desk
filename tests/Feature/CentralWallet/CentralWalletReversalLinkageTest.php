<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CentralWalletReversalLinkageTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-reversal-test-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
        ]);
    }

    public function test_reversal_requires_original_ledger_entry_id(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'reversal-missing-original',
            'entry_type' => 'reversal',
            'amount' => '5.00',
            'source_system' => 'radiumbox.com',
        ])->assertStatus(422);
    }

    public function test_structured_reversal_links_original_entry(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'credit-for-reversal',
            'entry_type' => 'credit',
            'amount' => '50.00',
            'source_system' => 'radiumbox.com',
        ])->assertCreated();

        $debit = $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'debit-for-reversal',
            'entry_type' => 'debit',
            'amount' => '20.00',
            'source_system' => 'radiumbox.com',
            'business_reference' => 'order:REFUND1',
        ])->assertCreated();

        $originalId = (int) $debit->json('ledger_entry_id');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'reversal-linked',
            'entry_type' => 'reversal',
            'amount' => '20.00',
            'source_system' => 'radiumbox.com',
            'business_reference' => 'refund:REFUND1',
            'original_ledger_entry_id' => $originalId,
        ])->assertCreated();

        $reversal = CentralWalletLedgerEntry::query()->where('entry_type', 'reversal')->first();
        $this->assertNotNull($reversal);
        $this->assertSame($originalId, (int) $reversal->original_ledger_entry_id);

        $this->assertDatabaseHas('central_wallet_audit_events', [
            'event_type' => 'ledger.reversal_linked',
            'central_wallet_id' => $cwid,
        ]);
    }

    public function test_reversal_idempotent_replay(): void
    {
        $cwid = $this->createWalletAs('radiumbox.com');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'credit-for-reversal-idem',
            'entry_type' => 'credit',
            'amount' => '30.00',
            'source_system' => 'radiumbox.com',
        ])->assertCreated();

        $debit = $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'debit-for-reversal-idem',
            'entry_type' => 'debit',
            'amount' => '10.00',
            'source_system' => 'radiumbox.com',
        ])->assertCreated();

        $payload = [
            'idempotency_key' => 'reversal-idem',
            'entry_type' => 'reversal',
            'amount' => '10.00',
            'source_system' => 'radiumbox.com',
            'original_ledger_entry_id' => (int) $debit->json('ledger_entry_id'),
        ];

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", $payload)->assertCreated();
        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", $payload)
            ->assertStatus(201)
            ->assertJsonPath('idempotent_replay', true);

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', 'reversal')->count());
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
