<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\ReservationState;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletIdempotencyRecord;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Log;
use Psr\Log\LoggerInterface;
use Tests\TestCase;

class CentralWalletDirectLedgerDebitGateTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-direct-debit-gate-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.direct_ledger_debit.enabled' => true,
            'central_wallet.reservations.enabled' => true,
            'central_wallet.reservation_ttl_seconds' => 900,
        ]);
    }

    public function test_gate_on_external_direct_debit_succeeds(): void
    {
        $cwid = $this->fundWallet('40.00');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'debit-gate-on',
            'entry_type' => 'debit',
            'amount' => '10.00',
            'source_system' => 'radiumbox.com',
            'business_reference' => 'order:GATE-ON',
        ])->assertCreated()
            ->assertJsonPath('entry_type', 'debit');

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());
    }

    public function test_gate_off_rejects_external_direct_debit_before_ledger_mutation(): void
    {
        config(['central_wallet.direct_ledger_debit.enabled' => false]);

        $cwid = $this->fundWallet('40.00');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'debit-gate-off',
            'entry_type' => 'debit',
            'amount' => '10.00',
            'source_system' => 'radiumbox.com',
            'business_reference' => 'order:GATE-OFF',
        ])->assertStatus(503)
            ->assertJsonPath('error', 'direct_ledger_debit_disabled');

        $this->assertSame(0, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());
    }

    public function test_gate_off_leaves_ledger_balance_unchanged(): void
    {
        config(['central_wallet.direct_ledger_debit.enabled' => false]);

        $cwid = $this->fundWallet('40.00');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'debit-gate-off-balance',
            'entry_type' => 'debit',
            'amount' => '10.00',
            'source_system' => 'radiumbox.com',
        ])->assertStatus(503);

        $this->authenticatedAs('radiumbox.com')->getJson("/api/central-wallet/v1/wallets/{$cwid}/balance")
            ->assertOk()
            ->assertJsonPath('ledger_balance', '40.00')
            ->assertJsonPath('available_balance', '40.00');
    }

    public function test_gate_off_leaves_reservation_state_unchanged(): void
    {
        config(['central_wallet.direct_ledger_debit.enabled' => false]);

        $cwid = $this->fundWallet('50.00');
        $reservationId = (string) $this->reserve($cwid, '20.00', 'reserve-gate-off', 'order:RES-GATE')->json('reservation_id');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'debit-while-reserved',
            'entry_type' => 'debit',
            'amount' => '5.00',
            'source_system' => 'radiumbox.com',
        ])->assertStatus(503);

        $this->assertDatabaseHas('central_wallet_reservations', [
            'id' => $reservationId,
            'state' => ReservationState::Active->value,
        ]);
    }

    public function test_gate_off_does_not_record_idempotency_for_rejected_debit(): void
    {
        config(['central_wallet.direct_ledger_debit.enabled' => false]);

        $cwid = $this->fundWallet('30.00');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'idem-gate-off',
            'entry_type' => 'debit',
            'amount' => '5.00',
            'source_system' => 'radiumbox.com',
        ])->assertStatus(503);

        $this->assertSame(0, CentralWalletIdempotencyRecord::query()
            ->where('caller_id', 'radiumbox.com')
            ->where('idempotency_key', 'idem-gate-off')
            ->count());
    }

    public function test_gate_off_same_idempotency_key_can_succeed_after_gate_reenabled(): void
    {
        config(['central_wallet.direct_ledger_debit.enabled' => false]);

        $cwid = $this->fundWallet('30.00');

        $payload = [
            'idempotency_key' => 'idem-gate-toggle',
            'entry_type' => 'debit',
            'amount' => '5.00',
            'source_system' => 'radiumbox.com',
        ];

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", $payload)
            ->assertStatus(503);

        config(['central_wallet.direct_ledger_debit.enabled' => true]);

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", $payload)
            ->assertCreated();
    }

    public function test_gate_off_reservation_commit_still_succeeds(): void
    {
        config(['central_wallet.direct_ledger_debit.enabled' => false]);

        $cwid = $this->fundWallet('60.00');
        $reservationId = (string) $this->reserve($cwid, '20.00', 'reserve-commit-gate-off', 'order:COMMIT-GATE')->json('reservation_id');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", [
            'idempotency_key' => 'commit-gate-off',
        ])->assertOk()
            ->assertJsonPath('state', ReservationState::Committed->value);

        $debit = CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->first();
        $this->assertNotNull($debit);
        $this->assertSame($reservationId, $debit->reservation_id);
    }

    public function test_gate_off_allows_external_credits(): void
    {
        config(['central_wallet.direct_ledger_debit.enabled' => false]);

        $cwid = $this->createWallet();

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'credit-gate-off',
            'entry_type' => 'credit',
            'amount' => '12.00',
            'source_system' => 'radiumbox.com',
        ])->assertCreated();

        $this->assertDatabaseHas('central_wallet_ledger_entries', [
            'central_wallet_id' => $cwid,
            'entry_type' => 'credit',
            'amount' => '12.00',
        ]);
    }

    public function test_gate_off_allows_external_adjustments(): void
    {
        config(['central_wallet.direct_ledger_debit.enabled' => false]);

        $cwid = $this->fundWallet('20.00');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'adjustment-gate-off',
            'entry_type' => 'adjustment',
            'amount' => '3.00',
            'source_system' => 'radiumbox.com',
        ])->assertCreated();

        $this->assertDatabaseHas('central_wallet_ledger_entries', [
            'central_wallet_id' => $cwid,
            'entry_type' => 'adjustment',
        ]);
    }

    public function test_gate_off_allows_external_reversals(): void
    {
        $cwid = $this->fundWallet('50.00');

        $debit = $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'debit-before-reversal',
            'entry_type' => 'debit',
            'amount' => '20.00',
            'source_system' => 'radiumbox.com',
            'business_reference' => 'order:REV-GATE',
        ])->assertCreated();

        config(['central_wallet.direct_ledger_debit.enabled' => false]);

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'reversal-gate-off',
            'entry_type' => 'reversal',
            'amount' => '20.00',
            'source_system' => 'radiumbox.com',
            'original_ledger_entry_id' => (int) $debit->json('ledger_entry_id'),
        ])->assertCreated();

        $this->assertDatabaseHas('central_wallet_ledger_entries', [
            'central_wallet_id' => $cwid,
            'entry_type' => 'reversal',
        ]);
    }

    public function test_gate_off_rejects_external_debit_even_for_internal_service_source_system_body(): void
    {
        config(['central_wallet.direct_ledger_debit.enabled' => false]);

        $cwid = $this->fundWallet('25.00');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'debit-external-caller',
            'entry_type' => 'debit',
            'amount' => '5.00',
            'source_system' => 'radiumbox.com',
        ])->assertStatus(503);
    }

    public function test_gate_off_allows_internal_service_direct_debit(): void
    {
        config(['central_wallet.direct_ledger_debit.enabled' => false]);

        $cwid = $this->fundWallet('25.00');

        $this->withoutHeader('X-Site-Code')
            ->withHeaders([
                'Authorization' => 'Bearer '.self::TOKEN,
            ])->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
                'idempotency_key' => 'internal-debit-gate-off',
                'entry_type' => 'debit',
                'amount' => '5.00',
                'source_system' => 'radiumbox.com',
            ])->assertCreated();
    }

    public function test_gate_off_emits_safe_operational_log_without_secrets(): void
    {
        config(['central_wallet.direct_ledger_debit.enabled' => false]);

        $cwid = $this->fundWallet('20.00');

        $logChannel = \Mockery::mock(LoggerInterface::class);
        Log::shouldReceive('channel')
            ->once()
            ->with((string) config('central_wallet.log_channel', 'stack'))
            ->andReturn($logChannel);
        $logChannel->shouldReceive('info')
            ->once()
            ->withArgs(function (string $message, array $context): bool {
                return $message === 'central_wallet.direct_ledger_debit.rejected'
                    && ($context['caller_id'] ?? null) === 'radiumbox.com'
                    && ($context['reason'] ?? null) === 'direct_ledger_debit_disabled'
                    && ! array_key_exists('Authorization', $context)
                    && ! array_key_exists('token', $context);
            });

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'debit-log',
            'entry_type' => 'debit',
            'amount' => '5.00',
            'source_system' => 'radiumbox.com',
        ])->assertStatus(503);
    }

    public function test_source_system_spoof_protection_unchanged_when_gate_off(): void
    {
        config(['central_wallet.direct_ledger_debit.enabled' => false]);

        $cwid = $this->createWallet();

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'spoof-credit',
            'entry_type' => 'credit',
            'amount' => '5.00',
            'source_system' => 'rdservice.in',
        ])->assertStatus(422)
            ->assertJsonPath('error', 'source_system_mismatch');
    }

    private function fundWallet(string $amount): string
    {
        $cwid = $this->createWallet();

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'fund-'.uniqid('', true),
            'entry_type' => 'credit',
            'amount' => $amount,
            'source_system' => 'radiumbox.com',
        ])->assertCreated();

        return $cwid;
    }

    private function createWallet(): string
    {
        $response = $this->authenticatedAs('radiumbox.com')->postJson('/api/central-wallet/v1/wallets', [
            'idempotency_key' => 'create-'.uniqid('', true),
        ]);

        $response->assertCreated();

        return (string) $response->json('central_wallet_id');
    }

    private function reserve(string $cwid, string $amount, string $idempotencyKey, string $businessReference)
    {
        return $this->authenticatedAs('radiumbox.com')->postJson('/api/central-wallet/v1/wallet-reservations', [
            'idempotency_key' => $idempotencyKey,
            'central_wallet_id' => $cwid,
            'amount' => $amount,
            'business_reference' => $businessReference,
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
