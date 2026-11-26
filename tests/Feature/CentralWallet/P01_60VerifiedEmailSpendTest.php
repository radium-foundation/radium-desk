<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\ReservationState;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

final class P01_60VerifiedEmailSpendTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-p01-60-test-token';

    private const SITE = 'radiumbox.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.historical_wallet_visibility.enabled' => true,
            'central_wallet.reservations.enabled' => true,
            'central_wallet.spend_authorization.p01_60_verified_email_spend_enabled' => true,
        ]);
    }

    public function test_canonical_identity_becomes_spendable_with_p01_60_flag_and_verified_email(): void
    {
        $email = 'spend-canonical@example.test';
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail($email);

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::SITE,
            'local_user_id' => '544460',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'canonical_account_identity',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->postCredit($cwid, '699.00');

        $this->authenticatedAs(self::SITE)
            ->getJson('/api/central-wallet/v1/wallet-visibility?'.http_build_query([
                'site_code' => self::SITE,
                'local_user_id' => '544460',
                'email' => $email,
                'email_verified' => '1',
            ]))
            ->assertOk()
            ->assertJsonPath('balance_status', 'verified')
            ->assertJsonPath('spendable', true)
            ->assertJsonPath('central_wallet_id', $cwid);
    }

    public function test_unverified_email_remains_non_spendable_under_p01_60(): void
    {
        $email = 'unverified@example.test';
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail($email);
        $this->postCredit($cwid, '100.00');

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::SITE,
            'local_user_id' => '90001',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'canonical_account_identity',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->authenticatedAs(self::SITE)
            ->getJson('/api/central-wallet/v1/wallet-visibility?'.http_build_query([
                'site_code' => self::SITE,
                'local_user_id' => '90001',
                'email' => $email,
                'email_verified' => '0',
            ]))
            ->assertOk()
            ->assertJsonPath('spendable', false)
            ->assertJsonPath('balance_status', 'unverified');
    }

    public function test_p01_60_reservation_rejects_cwid_mismatch(): void
    {
        $email = 'reserve@example.test';
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail($email);
        $otherCwid = (string) Str::uuid();
        \DB::table('central_wallets')->insert([
            'id' => $otherCwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
        $this->postCredit($cwid, '100.00');

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::SITE,
            'local_user_id' => '70001',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'canonical_account_identity',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->authenticatedAs(self::SITE)->postJson('/api/central-wallet/v1/wallet-reservations', [
            'idempotency_key' => 'p01-60-cwid-mismatch',
            'central_wallet_id' => $otherCwid,
            'amount' => '10.00',
            'business_reference' => 'order:RBP-MIS',
            'spend_authorization' => [
                'site_code' => self::SITE,
                'local_user_id' => '70001',
                'email' => $email,
                'email_verified' => true,
            ],
        ])->assertStatus(422)
            ->assertJsonPath('error', 'cwid_mismatch');
    }

    public function test_p01_60_reservation_succeeds_and_commits_after_flag_disable(): void
    {
        $email = 'pending-commit@example.test';
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail($email);
        $this->postCredit($cwid, '100.00');

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::SITE,
            'local_user_id' => '80001',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'canonical_account_identity',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $create = $this->authenticatedAs(self::SITE)->postJson('/api/central-wallet/v1/wallet-reservations', [
            'idempotency_key' => 'p01-60-pending',
            'central_wallet_id' => $cwid,
            'amount' => '25.00',
            'business_reference' => 'order:RBP-PEND',
            'spend_authorization' => [
                'site_code' => self::SITE,
                'local_user_id' => '80001',
                'email' => $email,
                'email_verified' => true,
            ],
        ])->assertCreated();

        $reservationId = (string) $create->json('reservation_id');

        config(['central_wallet.spend_authorization.p01_60_verified_email_spend_enabled' => false]);

        $this->authenticatedAs(self::SITE)->postJson('/api/central-wallet/v1/wallet-reservations', [
            'idempotency_key' => 'p01-60-new-after-disable',
            'central_wallet_id' => $cwid,
            'amount' => '10.00',
            'business_reference' => 'order:RBP-NEW',
            'spend_authorization' => [
                'site_code' => self::SITE,
                'local_user_id' => '80001',
                'email' => $email,
                'email_verified' => true,
            ],
        ])->assertStatus(422)
            ->assertJsonPath('error', 'p01_60_spend_disabled');

        $this->authenticatedAs(self::SITE)->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", [
            'idempotency_key' => 'p01-60-commit-after-disable',
        ])->assertOk()
            ->assertJsonPath('state', ReservationState::Committed->value);
    }

    public function test_site_mismatch_on_reservation_is_rejected(): void
    {
        $cwid = $this->createWallet();
        $this->postCredit($cwid, '50.00');

        $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => 'rdservice.in',
        ])->postJson('/api/central-wallet/v1/wallet-reservations', [
            'idempotency_key' => 'site-mismatch',
            'central_wallet_id' => $cwid,
            'amount' => '10.00',
            'business_reference' => 'order:SM',
            'spend_authorization' => [
                'site_code' => self::SITE,
                'local_user_id' => '1',
                'email' => 'a@b.test',
                'email_verified' => true,
            ],
        ])->assertForbidden()
            ->assertJsonPath('error', 'site_mismatch');
    }

    public function test_legacy_reservation_without_attestation_still_works(): void
    {
        $cwid = $this->fundWalletLegacy('80.00');

        $response = $this->authenticatedAs(self::SITE)->postJson('/api/central-wallet/v1/wallet-reservations', [
            'idempotency_key' => 'legacy-reserve',
            'central_wallet_id' => $cwid,
            'amount' => '15.00',
            'business_reference' => 'order:LEGACY',
        ])->assertCreated();

        $reservationId = (string) $response->json('reservation_id');

        $this->authenticatedAs(self::SITE)->postJson("/api/central-wallet/v1/wallet-reservations/{$reservationId}/commit", [
            'idempotency_key' => 'legacy-commit',
        ])->assertOk();
    }

    public function test_flag_disabled_visibility_returns_non_spendable_for_canonical_identity(): void
    {
        config(['central_wallet.spend_authorization.p01_60_verified_email_spend_enabled' => false]);

        $email = 'flag-off@example.test';
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail($email);
        $this->postCredit($cwid, '50.00');

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::SITE,
            'local_user_id' => '90099',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'canonical_account_identity',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->authenticatedAs(self::SITE)
            ->getJson('/api/central-wallet/v1/wallet-visibility?'.http_build_query([
                'site_code' => self::SITE,
                'local_user_id' => '90099',
                'email' => $email,
                'email_verified' => '1',
            ]))
            ->assertOk()
            ->assertJsonPath('spendable', false)
            ->assertJsonPath('balance_status', 'unverified');
    }

    private function fundWalletLegacy(string $amount): string
    {
        $cwid = $this->createWallet();
        $this->postCredit($cwid, $amount);

        return $cwid;
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

    private function createWallet(): string
    {
        $cwid = (string) Str::uuid();
        \DB::table('central_wallets')->insert([
            'id' => $cwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return $cwid;
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function seedCustomerWithVerifiedEmail(string $email): array
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

        return [$customerId, $cwid];
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
