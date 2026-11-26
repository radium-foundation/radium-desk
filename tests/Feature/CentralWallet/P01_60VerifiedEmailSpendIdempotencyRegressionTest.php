<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Application\WalletSpendEligibilityService;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\ReservationState;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletReservation;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * P-01-60 reservation idempotency, boundary balance and commit-binding regressions (SQLite).
 * Not a substitute for InnoDB multi-connection concurrency proof.
 */
final class P01_60VerifiedEmailSpendIdempotencyRegressionTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-p01-60-idem-token';

    private const SITE = 'radiumbox.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.reservations.enabled' => true,
            'central_wallet.spend_authorization.p01_60_verified_email_spend_enabled' => true,
        ]);
    }

    public function test_p01_60_boundary_balance_allows_only_one_twenty_rupee_reservation(): void
    {
        [$customerId, $cwid] = $this->seedP01_60Fixture('boundary@example.test', '71001');
        $this->postCredit($cwid, '30.00');
        $auth = $this->spendAuth('boundary@example.test', '71001');

        $this->p01_60Reserve($cwid, '20.00', 'p01-60-boundary-a', 'order:B-A', $auth)->assertCreated();
        $this->p01_60Reserve($cwid, '20.00', 'p01-60-boundary-b', 'order:B-B', $auth)
            ->assertStatus(422)
            ->assertJsonPath('error', 'insufficient_balance');

        $this->assertSame(1, CentralWalletReservation::query()->where('state', ReservationState::Active)->count());
        $this->assertSame(0, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());
    }

    public function test_p01_60_reservation_idempotent_replay_preserves_authorization_metadata(): void
    {
        [$customerId, $cwid] = $this->seedP01_60Fixture('idem@example.test', '71002');
        $this->postCredit($cwid, '80.00');
        $auth = $this->spendAuth('idem@example.test', '71002');

        $payload = [
            'idempotency_key' => 'p01-60-idem-replay',
            'central_wallet_id' => $cwid,
            'amount' => '15.00',
            'business_reference' => 'order:IDEM',
            'spend_authorization' => $auth,
        ];

        $first = $this->authenticatedAs(self::SITE)->postJson('/api/central-wallet/v1/wallet-reservations', $payload)->assertCreated();
        $reservationId = (string) $first->json('reservation_id');

        $this->authenticatedAs(self::SITE)->postJson('/api/central-wallet/v1/wallet-reservations', $payload)
            ->assertStatus(201)
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('reservation_id', $reservationId);

        $reservation = CentralWalletReservation::query()->findOrFail($reservationId);
        $authorization = $reservation->metadata['authorization'] ?? null;
        $this->assertIsArray($authorization);
        $this->assertSame(WalletSpendEligibilityService::MODE_P01_60_VERIFIED_EMAIL, $authorization['authorization_mode'] ?? null);
        $this->assertSame($cwid, $authorization['central_wallet_id'] ?? null);
        $this->assertSame('71002', (string) ($authorization['local_user_id'] ?? ''));
    }

    public function test_p01_60_commit_idempotent_replay_is_single_debit(): void
    {
        [, $cwid] = $this->seedP01_60Fixture('commit-idem@example.test', '71003');
        $this->postCredit($cwid, '50.00');
        $auth = $this->spendAuth('commit-idem@example.test', '71003');

        $reservationId = (string) $this->p01_60Reserve($cwid, '12.00', 'p01-60-commit-idem', 'order:C-IDEM', $auth)
            ->json('reservation_id');

        $payload = ['idempotency_key' => 'p01-60-commit-same-key'];
        $first = $this->authenticatedAs(self::SITE)->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", $payload)
            ->assertOk();
        $ledgerEntryId = $first->json('ledger_entry_id');

        $this->authenticatedAs(self::SITE)->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", $payload)
            ->assertOk()
            ->assertJsonPath('idempotent_replay', true)
            ->assertJsonPath('ledger_entry_id', $ledgerEntryId);

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());
    }

    public function test_p01_60_concurrent_style_commit_with_different_idempotency_keys_yields_single_debit(): void
    {
        [, $cwid] = $this->seedP01_60Fixture('commit-race@example.test', '71004');
        $this->postCredit($cwid, '50.00');
        $auth = $this->spendAuth('commit-race@example.test', '71004');

        $reservationId = (string) $this->p01_60Reserve($cwid, '18.00', 'p01-60-commit-race', 'order:C-RACE', $auth)
            ->json('reservation_id');

        $this->authenticatedAs(self::SITE)->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", [
            'idempotency_key' => 'commit-race-a',
        ])->assertOk();

        $this->authenticatedAs(self::SITE)->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", [
            'idempotency_key' => 'commit-race-b',
        ])->assertOk();

        $this->assertSame(1, CentralWalletLedgerEntry::query()->where('entry_type', LedgerEntryType::Debit)->count());
        $this->assertDatabaseHas('central_wallet_reservations', [
            'id' => $reservationId,
            'state' => ReservationState::Committed->value,
        ]);
    }

    public function test_p01_60_commit_rejects_tampered_authorization_wallet_in_metadata(): void
    {
        [, $cwid] = $this->seedP01_60Fixture('tamper@example.test', '71005');
        $otherCwid = (string) Str::uuid();
        \DB::table('central_wallets')->insert([
            'id' => $otherCwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->postCredit($cwid, '40.00');
        $auth = $this->spendAuth('tamper@example.test', '71005');

        $reservationId = (string) $this->p01_60Reserve($cwid, '10.00', 'p01-60-tamper', 'order:TAMPER', $auth)
            ->json('reservation_id');

        $reservation = CentralWalletReservation::query()->findOrFail($reservationId);
        $metadata = is_array($reservation->metadata) ? $reservation->metadata : [];
        $metadata['authorization'] = [
            'authorization_mode' => WalletSpendEligibilityService::MODE_P01_60_VERIFIED_EMAIL,
            'site_code' => self::SITE,
            'local_user_id' => '71005',
            'central_wallet_id' => $otherCwid,
            'authorized_at_create' => true,
        ];
        $reservation->update(['metadata' => $metadata]);

        $this->authenticatedAs(self::SITE)->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", [
            'idempotency_key' => 'commit-tamper',
        ])->assertStatus(422);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function seedP01_60Fixture(string $email, string $localUserId): array
    {
        $cwid = (string) Str::uuid();
        $customerId = (string) Str::uuid();

        \DB::table('central_wallets')->insert([
            'id' => $cwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customerId,
            'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
            'provider' => 'desk_email',
            'subject_hash' => app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail($email),
            'verified_at' => now(),
        ]);

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::SITE,
            'local_user_id' => $localUserId,
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'canonical_account_identity',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        return [$customerId, $cwid];
    }

    /**
     * @return array<string, mixed>
     */
    private function spendAuth(string $email, string $localUserId): array
    {
        return [
            'site_code' => self::SITE,
            'local_user_id' => $localUserId,
            'email' => $email,
            'email_verified' => true,
        ];
    }

    private function p01_60Reserve(string $cwid, string $amount, string $idempotencyKey, string $businessReference, array $auth)
    {
        return $this->authenticatedAs(self::SITE)->postJson('/api/central-wallet/v1/wallet-reservations', [
            'idempotency_key' => $idempotencyKey,
            'central_wallet_id' => $cwid,
            'amount' => $amount,
            'business_reference' => $businessReference,
            'spend_authorization' => $auth,
        ]);
    }

    private function postCredit(string $cwid, string $amount): void
    {
        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $cwid,
            'entry_type' => LedgerEntryType::Credit,
            'amount' => $amount,
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted,
            'source_system' => self::SITE,
            'correlation_id' => (string) Str::uuid(),
            'posted_at' => now(),
        ]);
    }

    private function authenticatedAs(string $site): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $site,
            'Accept' => 'application/json',
        ]);
    }
}
