<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\LedgerService;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class WalletVisibilityApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-wallet-visibility-token';

    private const SITE = 'rdservice.in';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.historical_wallet_visibility.enabled' => true,
        ]);
    }

    public function test_unauthenticated_request_is_rejected(): void
    {
        $this->getJson('/api/central-wallet/v1/wallet-visibility?'.http_build_query([
            'site_code' => self::SITE,
            'local_user_id' => '558781',
            'email' => 'blg9950578359@gmail.com',
        ]))->assertUnauthorized();
    }

    public function test_migration_cohort_customer_sees_unverified_balance_for_rd10575_shape(): void
    {
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail('blg9950578359@gmail.com');

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::SITE,
            'local_user_id' => '558781',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'owner_migration_cohort',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $cwid,
            'entry_type' => LedgerEntryType::Credit,
            'amount' => '499.00',
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted,
            'source_system' => self::SITE,
            'source_reference' => 'users_wallet:2594',
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => 'REF-67352',
            'posted_at' => now(),
        ]);

        $this->walletVisibility(self::SITE, '558781', 'blg9950578359@gmail.com', '9950578359')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '499.00')
            ->assertJsonPath('available_balance', '499.00')
            ->assertJsonPath('balance_status', 'unverified')
            ->assertJsonPath('spendable', false)
            ->assertJsonPath('verification_required', true);

        $this->assertSame('499.00', app(LedgerService::class)->availableBalance($cwid));
    }

    public function test_trusted_verified_link_returns_verified_spendable_balance(): void
    {
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail('trusted@example.com');

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::SITE,
            'local_user_id' => '9001',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'verified_email',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $cwid,
            'entry_type' => LedgerEntryType::Credit,
            'amount' => '100.00',
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted,
            'source_system' => self::SITE,
            'correlation_id' => (string) Str::uuid(),
            'posted_at' => now(),
        ]);

        $this->walletVisibility(self::SITE, '9001', 'trusted@example.com', null, emailVerified: true)
            ->assertOk()
            ->assertJsonPath('wallet_balance', '100.00')
            ->assertJsonPath('balance_status', 'verified')
            ->assertJsonPath('spendable', true)
            ->assertJsonPath('verification_required', false);
    }

    public function test_cross_customer_email_does_not_reveal_wallet(): void
    {
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail('owner@example.com');

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::SITE,
            'local_user_id' => '9002',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'owner_migration_cohort',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $cwid,
            'entry_type' => LedgerEntryType::Credit,
            'amount' => '499.00',
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted,
            'source_system' => self::SITE,
            'correlation_id' => (string) Str::uuid(),
            'posted_at' => now(),
        ]);

        $this->walletVisibility(self::SITE, '9002', 'other@example.com')
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
    }

    public function test_missing_wallet_returns_not_found(): void
    {
        $this->walletVisibility(self::SITE, '999999', 'missing@example.com')
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
    }

    public function test_endpoint_disabled_when_historical_visibility_flag_off(): void
    {
        config(['central_wallet.historical_wallet_visibility.enabled' => false]);

        $this->walletVisibility(self::SITE, '558781', 'blg9950578359@gmail.com')
            ->assertStatus(503)
            ->assertJsonPath('error', 'historical_wallet_visibility_disabled');
    }

    public function test_site_mismatch_is_rejected(): void
    {
        $this->withHeaders($this->authHeaders('radiumbox.com'))
            ->getJson('/api/central-wallet/v1/wallet-visibility?'.http_build_query([
                'site_code' => self::SITE,
                'local_user_id' => '558781',
                'email' => 'blg9950578359@gmail.com',
            ]))
            ->assertForbidden()
            ->assertJsonPath('error', 'site_mismatch');
    }

    private function walletVisibility(
        string $site,
        string $localUserId,
        string $email,
        ?string $mobile = null,
        bool $emailVerified = false,
    ): TestResponse {
        $query = [
            'site_code' => $site,
            'local_user_id' => $localUserId,
            'email' => $email,
            'email_verified' => $emailVerified ? '1' : '0',
        ];

        if ($mobile !== null && $mobile !== '') {
            $query['mobile'] = $mobile;
        }

        return $this->withHeaders($this->authHeaders($site))
            ->getJson('/api/central-wallet/v1/wallet-visibility?'.http_build_query($query));
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
            'subject_hash' => hash('sha256', 'verified_email:'.strtolower(trim($email))),
            'verified_at' => now(),
        ]);

        return [$customerId, $cwid];
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $site): array
    {
        return [
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $site,
            'Accept' => 'application/json',
        ];
    }
}
