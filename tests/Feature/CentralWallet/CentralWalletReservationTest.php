<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\ReservationService;
use App\CentralWallet\Domain\Enums\ReservationState;
use App\CentralWallet\Infrastructure\Jobs\ExpireActiveReservationsJob;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Tests\TestCase;

class CentralWalletReservationTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-reservation-test-token';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.reservations.enabled' => true,
            'central_wallet.reservation_ttl_seconds' => 900,
        ]);
    }

    public function test_reservations_disabled_returns_service_unavailable(): void
    {
        config(['central_wallet.reservations.enabled' => false]);

        $this->authenticatedAs('radiumbox.com')->postJson('/api/central-wallet/v1/wallet-reservations', [
            'idempotency_key' => 'reserve-disabled',
            'central_wallet_id' => $this->createWallet(),
            'amount' => '1.00',
            'business_reference' => 'order:1',
        ])->assertStatus(503);
    }

    public function test_successful_reservation_reduces_available_balance(): void
    {
        $cwid = $this->fundWallet('100.00');

        $response = $this->reserve($cwid, '25.00', 'reserve-1', 'order:RBP1');

        $response->assertCreated()
            ->assertJsonPath('state', ReservationState::Active->value)
            ->assertJsonPath('amount', '25.00');

        $this->authenticatedAs('radiumbox.com')->getJson("/api/central-wallet/v1/wallets/{$cwid}/balance")
            ->assertOk()
            ->assertJsonPath('ledger_balance', '100.00')
            ->assertJsonPath('reserved_balance', '25.00')
            ->assertJsonPath('available_balance', '75.00');
    }

    public function test_insufficient_balance_rejects_reservation(): void
    {
        $cwid = $this->fundWallet('10.00');

        $this->reserve($cwid, '15.00', 'reserve-insufficient', 'order:RBP2')
            ->assertStatus(422)
            ->assertJsonPath('error', 'insufficient_balance');

        $this->assertDatabaseHas('central_wallet_audit_events', [
            'event_type' => 'reservation.insufficient_balance',
            'central_wallet_id' => $cwid,
        ]);
    }

    public function test_invalid_amount_rejected(): void
    {
        $cwid = $this->fundWallet('10.00');

        $this->authenticatedAs('radiumbox.com')->postJson('/api/central-wallet/v1/wallet-reservations', [
            'idempotency_key' => 'bad-amount',
            'central_wallet_id' => $cwid,
            'amount' => '0.00',
            'business_reference' => 'order:RBP3',
        ])->assertStatus(422);
    }

    public function test_missing_idempotency_key_rejected(): void
    {
        $cwid = $this->fundWallet('10.00');

        $this->authenticatedAs('radiumbox.com')->postJson('/api/central-wallet/v1/wallet-reservations', [
            'central_wallet_id' => $cwid,
            'amount' => '1.00',
            'business_reference' => 'order:RBP4',
        ])->assertStatus(422);
    }

    public function test_reservation_idempotent_replay(): void
    {
        $cwid = $this->fundWallet('50.00');

        $payload = [
            'idempotency_key' => 'reserve-idem',
            'central_wallet_id' => $cwid,
            'amount' => '10.00',
            'business_reference' => 'order:RBP5',
        ];

        $first = $this->authenticatedAs('radiumbox.com')->postJson('/api/central-wallet/v1/wallet-reservations', $payload)
            ->assertCreated();

        $reservationId = (string) $first->json('reservation_id');

        $this->authenticatedAs('radiumbox.com')->postJson('/api/central-wallet/v1/wallet-reservations', $payload)
            ->assertStatus(201)
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('reservation_id', $reservationId);

        $this->assertSame(1, CentralWalletReservation::query()->count());
    }

    public function test_reservation_idempotency_conflict_on_different_amount(): void
    {
        $cwid = $this->fundWallet('50.00');

        $this->authenticatedAs('radiumbox.com')->postJson('/api/central-wallet/v1/wallet-reservations', [
            'idempotency_key' => 'conflict-key',
            'central_wallet_id' => $cwid,
            'amount' => '10.00',
            'business_reference' => 'order:RBP6',
        ])->assertCreated();

        $this->authenticatedAs('radiumbox.com')->postJson('/api/central-wallet/v1/wallet-reservations', [
            'idempotency_key' => 'conflict-key',
            'central_wallet_id' => $cwid,
            'amount' => '11.00',
            'business_reference' => 'order:RBP6',
        ])->assertStatus(409);
    }

    public function test_multiple_active_reservations_reduce_available_balance(): void
    {
        $cwid = $this->fundWallet('100.00');

        $this->reserve($cwid, '20.00', 'reserve-a', 'order:A')->assertCreated();
        $this->reserve($cwid, '30.00', 'reserve-b', 'order:B')->assertCreated();

        $this->authenticatedAs('radiumbox.com')->getJson("/api/central-wallet/v1/wallets/{$cwid}/balance")
            ->assertOk()
            ->assertJsonPath('reserved_balance', '50.00')
            ->assertJsonPath('available_balance', '50.00');
    }

    public function test_commit_creates_single_ledger_debit(): void
    {
        $cwid = $this->fundWallet('100.00');
        $reservationId = (string) $this->reserve($cwid, '40.00', 'reserve-commit', 'order:COMMIT1')->json('reservation_id');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", [
            'idempotency_key' => 'commit-1',
        ])
            ->assertOk()
            ->assertJsonPath('state', ReservationState::Committed->value);

        $this->assertSame(2, CentralWalletLedgerEntry::query()->count());
        $debit = CentralWalletLedgerEntry::query()->where('entry_type', 'debit')->first();
        $this->assertSame('40.00', (string) $debit->amount);
        $this->assertSame($reservationId, $debit->reservation_id);

        $this->authenticatedAs('radiumbox.com')->getJson("/api/central-wallet/v1/wallets/{$cwid}/balance")
            ->assertOk()
            ->assertJsonPath('ledger_balance', '60.00')
            ->assertJsonPath('reserved_balance', '0.00')
            ->assertJsonPath('available_balance', '60.00');
    }

    public function test_commit_retry_is_idempotent(): void
    {
        $cwid = $this->fundWallet('50.00');
        $reservationId = (string) $this->reserve($cwid, '10.00', 'reserve-commit-retry', 'order:COMMIT2')->json('reservation_id');

        $payload = ['idempotency_key' => 'commit-retry'];

        $first = $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", $payload)
            ->assertOk();

        $ledgerEntryId = $first->json('ledger_entry_id');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", $payload)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('ledger_entry_id', $ledgerEntryId);

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', 'debit')->count());
    }

    public function test_cannot_commit_released_reservation(): void
    {
        $cwid = $this->fundWallet('50.00');
        $reservationId = (string) $this->reserve($cwid, '10.00', 'reserve-release-then-commit', 'order:REL1')->json('reservation_id');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/release", [
            'idempotency_key' => 'release-1',
        ])->assertOk();

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", [
            'idempotency_key' => 'commit-after-release',
        ])->assertStatus(422);
    }

    public function test_release_restores_available_balance(): void
    {
        $cwid = $this->fundWallet('80.00');
        $reservationId = (string) $this->reserve($cwid, '25.00', 'reserve-release', 'order:REL2')->json('reservation_id');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/release", [
            'idempotency_key' => 'release-2',
        ])->assertOk()
            ->assertJsonPath('state', ReservationState::Released->value);

        $this->authenticatedAs('radiumbox.com')->getJson("/api/central-wallet/v1/wallets/{$cwid}/balance")
            ->assertOk()
            ->assertJsonPath('reserved_balance', '0.00')
            ->assertJsonPath('available_balance', '80.00');
    }

    public function test_release_retry_is_idempotent(): void
    {
        $cwid = $this->fundWallet('80.00');
        $reservationId = (string) $this->reserve($cwid, '15.00', 'reserve-release-retry', 'order:REL3')->json('reservation_id');
        $payload = ['idempotency_key' => 'release-retry'];

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/release", $payload)->assertOk();
        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/release", $payload)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true);
    }

    public function test_cannot_release_committed_reservation(): void
    {
        $cwid = $this->fundWallet('50.00');
        $reservationId = (string) $this->reserve($cwid, '10.00', 'reserve-commit-then-release', 'order:REL4')->json('reservation_id');

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", [
            'idempotency_key' => 'commit-before-release',
        ])->assertOk();

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/release", [
            'idempotency_key' => 'release-committed',
        ])->assertStatus(422);
    }

    public function test_expiry_job_marks_active_reservation_expired(): void
    {
        $cwid = $this->fundWallet('60.00');
        $reservationId = (string) $this->reserve($cwid, '20.00', 'reserve-expire', 'order:EXP1')->json('reservation_id');

        CentralWalletReservation::query()->where('id', $reservationId)->update([
            'expires_at' => now()->subMinute(),
        ]);

        (new ExpireActiveReservationsJob)->handle(app(ReservationService::class));

        $this->assertDatabaseHas('central_wallet_reservations', [
            'id' => $reservationId,
            'state' => ReservationState::Expired->value,
        ]);

        $this->authenticatedAs('radiumbox.com')->getJson("/api/central-wallet/v1/wallets/{$cwid}/balance")
            ->assertOk()
            ->assertJsonPath('reserved_balance', '0.00')
            ->assertJsonPath('available_balance', '60.00');
    }

    public function test_cannot_commit_expired_reservation(): void
    {
        $cwid = $this->fundWallet('60.00');
        $reservationId = (string) $this->reserve($cwid, '20.00', 'reserve-expire-commit', 'order:EXP2')->json('reservation_id');

        CentralWalletReservation::query()->where('id', $reservationId)->update([
            'expires_at' => now()->subMinute(),
        ]);

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", [
            'idempotency_key' => 'commit-expired',
        ])->assertStatus(422);
    }

    public function test_direct_debit_respects_active_reservations(): void
    {
        $cwid = $this->fundWallet('100.00');
        $this->reserve($cwid, '70.00', 'reserve-block-debit', 'order:DEB1')->assertCreated();

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-entries", [
            'idempotency_key' => 'debit-over-reserved',
            'entry_type' => 'debit',
            'amount' => '50.00',
            'source_system' => 'radiumbox.com',
            'business_reference' => 'order:DEB1',
        ])->assertStatus(422);
    }

    public function test_concurrent_reservations_do_not_over_allocate(): void
    {
        $cwid = $this->fundWallet('30.00');

        DB::transaction(function () use ($cwid): void {
            $this->reserve($cwid, '20.00', 'reserve-concurrent-1', 'order:C1')->assertCreated();
        });

        $this->reserve($cwid, '20.00', 'reserve-concurrent-2', 'order:C2')
            ->assertStatus(422)
            ->assertJsonPath('error', 'insufficient_balance');
    }

    public function test_reservation_audit_events_recorded(): void
    {
        $cwid = $this->fundWallet('40.00');
        $reservationId = (string) $this->reserve($cwid, '10.00', 'reserve-audit', 'order:AUD1')->json('reservation_id');

        $this->assertDatabaseHas('central_wallet_audit_events', ['event_type' => 'reservation.created']);

        $this->authenticatedAs('radiumbox.com')->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", [
            'idempotency_key' => 'commit-audit',
        ])->assertOk();

        $this->assertDatabaseHas('central_wallet_audit_events', ['event_type' => 'reservation.committed']);
    }

    public function test_cross_site_caller_cannot_access_reservation(): void
    {
        $cwid = $this->fundWallet('40.00');
        $reservationId = (string) $this->reserve($cwid, '10.00', 'reserve-forbidden', 'order:FOR1', 'radiumbox.com')->json('reservation_id');

        $this->authenticatedAs('rdservice.in')->getJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}")
            ->assertForbidden();
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

    private function reserve(string $cwid, string $amount, string $idempotencyKey, string $businessReference, string $site = 'radiumbox.com')
    {
        return $this->authenticatedAs($site)->postJson('/api/central-wallet/v1/wallet-reservations', [
            'idempotency_key' => $idempotencyKey,
            'central_wallet_id' => $cwid,
            'amount' => $amount,
            'business_reference' => $businessReference,
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

    private function authenticatedAs(string $siteCode): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $siteCode,
        ]);
    }

    private function authenticated(): self
    {
        return $this->withToken(self::TOKEN);
    }
}
