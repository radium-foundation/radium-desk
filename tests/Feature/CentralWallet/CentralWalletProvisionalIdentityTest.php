<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CentralWalletProvisionalIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-provisional-token';

    private const SITE = 'radiumbox.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.customer_identity.enabled' => true,
            'central_wallet.customer_identity.verified_email_enabled' => true,
            'central_wallet.provisional_identity.enabled' => true,
            'central_wallet.provisional_identity.financial_gate_enabled' => true,
            'central_wallet.reservations.enabled' => true,
            'central_wallet.identity_required_cohort.provisional_display_enabled' => false,
        ]);
    }

    public function test_unverified_email_with_single_verified_customer_returns_provisional_balance(): void
    {
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail('user@example.com', '100.00');

        $this->provisionalResolve('7', 'user@example.com', false, 'idem-prov-1')
            ->assertOk()
            ->assertJsonPath('identity_state', 'provisional')
            ->assertJsonPath('available_balance', '100.00')
            ->assertJsonPath('verification_required', true)
            ->assertJsonMissing(['central_wallet_id', 'desk_customer_id']);
    }

    public function test_unverified_email_without_customer_is_unresolved(): void
    {
        $this->provisionalResolve('7', 'unknown@example.com', false, 'idem-prov-2')
            ->assertNotFound()
            ->assertJsonPath('identity_state', 'unresolved');
    }

    public function test_verified_email_request_is_rejected_for_provisional_path(): void
    {
        $this->provisionalResolve('7', 'user@example.com', true, 'idem-prov-3')
            ->assertStatus(422)
            ->assertJsonPath('error', 'use_trusted_identity_path');
    }

    public function test_conflicting_active_link_fails_closed(): void
    {
        $this->seedCustomerWithVerifiedEmail('user@example.com', '50.00');
        $otherCwid = (string) Str::uuid();
        $this->seedWallet($otherCwid);

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $otherCwid,
            'site_code' => self::SITE,
            'local_user_id' => '7',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'm2_dual_otp',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->provisionalResolve('7', 'user@example.com', false, 'idem-prov-4')
            ->assertStatus(409)
            ->assertJsonPath('identity_state', 'unresolved');
    }

    public function test_reservation_requires_trusted_account_link_when_financial_gate_enabled(): void
    {
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail('user@example.com', '200.00');

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/central-wallet/v1/wallet-reservations', [
                'idempotency_key' => 'reserve-no-link',
                'central_wallet_id' => $cwid,
                'amount' => '10.00',
                'business_reference' => 'order-test-1',
                'local_user_id' => '7',
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'trusted_account_link_required');
    }

    public function test_reservation_succeeds_with_trusted_active_link(): void
    {
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail('user@example.com', '200.00');

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::SITE,
            'local_user_id' => '7',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'verified_email',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->withHeaders($this->authHeaders())
            ->postJson('/api/central-wallet/v1/wallet-reservations', [
                'idempotency_key' => 'reserve-trusted',
                'central_wallet_id' => $cwid,
                'amount' => '10.00',
                'business_reference' => 'order-test-2',
                'local_user_id' => '7',
            ])
            ->assertCreated();
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function seedCustomerWithVerifiedEmail(string $email, string $balance): array
    {
        $cwid = (string) Str::uuid();
        $customerId = (string) Str::uuid();
        $this->seedWallet($cwid);

        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);

        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customerId,
            'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
            'provider' => 'desk_email',
            'subject_hash' => hash('sha256', 'verified_email:'.strtolower(trim($email))),
            'verified_at' => now(),
        ]);

        \DB::table('central_wallet_ledger_entries')->insert([
            'central_wallet_id' => $cwid,
            'entry_type' => 'credit',
            'amount' => $balance,
            'currency' => 'INR',
            'status' => 'posted',
            'source_system' => 'test',
            'correlation_id' => (string) Str::uuid(),
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        return [$customerId, $cwid];
    }

    private function provisionalResolve(string $localUserId, string $email, bool $emailVerified, string $idempotencyKey): TestResponse
    {
        return $this->withHeaders($this->authHeaders())
            ->postJson('/api/central-wallet/v1/customer-identity/provisional-resolve', [
                'idempotency_key' => $idempotencyKey,
                'site_code' => self::SITE,
                'local_user_id' => $localUserId,
                'email' => $email,
                'email_verified' => $emailVerified,
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(): array
    {
        return [
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => self::SITE,
        ];
    }

    private function seedWallet(string $cwid): void
    {
        \DB::table('central_wallets')->insert([
            'id' => $cwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }
}
