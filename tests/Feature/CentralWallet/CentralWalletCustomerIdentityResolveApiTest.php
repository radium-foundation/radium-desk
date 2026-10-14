<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralWalletCustomerIdentityResolveApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-identity-resolve-token';

    private const SITE = 'rdservice.net';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.customer_identity_resolve.enabled' => true,
        ]);
    }

    public function test_verified_email_identity_creates_trusted_link(): void
    {
        $response = $this->resolveVerifiedEmail('fixture-user@example.com', '42');

        $response->assertCreated()
            ->assertJsonPath('verification_method', 'verified_email')
            ->assertJsonPath('link_status', AccountLinkStatus::Active->value)
            ->assertJsonPath('provision_action', 'created_customer');

        $cwid = (string) $response->json('central_wallet_id');
        $this->assertNotSame('', $cwid);

        $this->assertDatabaseHas('central_wallet_account_links', [
            'site_code' => self::SITE,
            'local_user_id' => '42',
            'central_wallet_id' => $cwid,
            'verification_method' => 'verified_email',
            'status' => AccountLinkStatus::Active->value,
        ]);
    }

    public function test_repeated_resolution_is_idempotent_without_duplicate_links(): void
    {
        $first = $this->resolveVerifiedEmail('repeat@example.com', '77', 'idem-repeat-1');
        $first->assertCreated();

        $linkId = (int) $first->json('link_id');
        $cwid = (string) $first->json('central_wallet_id');

        $second = $this->resolveVerifiedEmail('repeat@example.com', '77', 'idem-repeat-2');
        $second->assertOk()
            ->assertJsonPath('link_id', $linkId)
            ->assertJsonPath('central_wallet_id', $cwid);

        $this->assertSame(1, CentralWalletAccountLink::query()
            ->where('site_code', self::SITE)
            ->where('local_user_id', '77')
            ->where('status', AccountLinkStatus::Active)
            ->count());
    }

    public function test_unsupported_identity_type_is_rejected(): void
    {
        $this->authenticatedAs(self::SITE)->postJson('/api/central-wallet/v1/customer-identity/resolve', [
            'idempotency_key' => 'bad-type-1',
            'site_code' => self::SITE,
            'local_user_id' => '1',
            'identity' => [
                'type' => 'verified_mobile',
                'mobile_e164' => '+919876543210',
            ],
        ])->assertStatus(422)
            ->assertJsonPath('error', 'unsupported_identity_type');
    }

    public function test_site_mismatch_is_rejected(): void
    {
        $this->authenticatedAs('rdservice.in')->postJson('/api/central-wallet/v1/customer-identity/resolve', [
            'idempotency_key' => 'site-mismatch-1',
            'site_code' => self::SITE,
            'local_user_id' => '1',
            'identity' => [
                'type' => 'verified_email',
                'email' => 'mismatch@example.com',
            ],
        ])->assertForbidden()
            ->assertJsonPath('error', 'site_mismatch');
    }

    public function test_unauthorized_request_is_rejected(): void
    {
        $this->postJson('/api/central-wallet/v1/customer-identity/resolve', [
            'idempotency_key' => 'unauth-1',
            'site_code' => self::SITE,
            'local_user_id' => '1',
            'identity' => [
                'type' => 'verified_email',
                'email' => 'unauth@example.com',
            ],
        ])->assertUnauthorized();
    }

    public function test_existing_link_with_different_cwid_is_rejected(): void
    {
        $walletA = app(\App\CentralWallet\Application\CentralWalletService::class)->create();
        $walletB = app(\App\CentralWallet\Application\CentralWalletService::class)->create();

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $walletA->id,
            'site_code' => self::SITE,
            'local_user_id' => '99',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'verified_email',
            'created_by' => 'service:test',
            'linked_at' => now(),
        ]);

        $customerId = (string) Str::uuid();
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $walletB->id,
            'status' => 'active',
        ]);
        $hasher = app(\App\CentralWallet\Application\CustomerIdentitySubjectHasher::class);
        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customerId,
            'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
            'provider' => 'desk_email',
            'subject_hash' => $hasher->hashVerifiedEmail('conflict@example.com'),
            'verified_at' => now(),
        ]);

        $this->resolveVerifiedEmail('conflict@example.com', '99')
            ->assertStatus(409)
            ->assertJsonPath('error', 'account_link_cwid_conflict');
    }

    public function test_upgrades_canonical_link_to_trusted_verification(): void
    {
        $wallet = app(\App\CentralWallet\Application\CentralWalletService::class)->create();
        $customerId = (string) Str::uuid();
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $wallet->id,
            'status' => 'active',
        ]);

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $wallet->id,
            'desk_customer_id' => $customerId,
            'site_code' => self::SITE,
            'local_user_id' => '5',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'canonical_account_identity',
            'created_by' => 'customer_identity_ensure:rdservice.net',
            'linked_at' => now(),
        ]);

        $hasher = app(\App\CentralWallet\Application\CustomerIdentitySubjectHasher::class);
        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customerId,
            'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
            'provider' => 'desk_email',
            'subject_hash' => $hasher->hashVerifiedEmail('upgrade@example.com'),
            'verified_at' => now(),
        ]);

        $this->resolveVerifiedEmail('upgrade@example.com', '5')
            ->assertOk()
            ->assertJsonPath('verification_method', 'verified_email')
            ->assertJsonPath('central_wallet_id', $wallet->id);

        $this->assertDatabaseHas('central_wallet_account_links', [
            'local_user_id' => '5',
            'verification_method' => 'verified_email',
        ]);
    }

    public function test_resolve_disabled_when_feature_flag_off(): void
    {
        config(['central_wallet.customer_identity_resolve.enabled' => false]);

        $this->resolveVerifiedEmail('off@example.com', '1')
            ->assertStatus(503)
            ->assertJsonPath('error', 'customer_identity_resolve_disabled');
    }

    public function test_google_identity_creates_trusted_link(): void
    {
        $response = $this->resolveGoogle('google-subject-42', '88');

        $response->assertCreated()
            ->assertJsonPath('verification_method', 'trusted_google')
            ->assertJsonPath('link_status', AccountLinkStatus::Active->value)
            ->assertJsonPath('provision_action', 'created_customer');
    }

    public function test_google_resolution_is_idempotent_without_duplicate_links(): void
    {
        $first = $this->resolveGoogle('google-subject-idem', '89', 'google-idem-1');
        $first->assertCreated();
        $linkId = (int) $first->json('link_id');
        $cwid = (string) $first->json('central_wallet_id');

        $second = $this->resolveGoogle('google-subject-idem', '89', 'google-idem-2');
        $second->assertOk()
            ->assertJsonPath('link_id', $linkId)
            ->assertJsonPath('central_wallet_id', $cwid);

        $this->assertSame(1, CentralWalletAccountLink::query()
            ->where('site_code', self::SITE)
            ->where('local_user_id', '89')
            ->where('status', AccountLinkStatus::Active)
            ->count());
    }

    public function test_google_upgrades_canonical_link_when_credential_maps_to_same_cwid(): void
    {
        $wallet = app(\App\CentralWallet\Application\CentralWalletService::class)->create();
        $customerId = (string) Str::uuid();
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $wallet->id,
            'status' => 'active',
        ]);

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $wallet->id,
            'desk_customer_id' => $customerId,
            'site_code' => self::SITE,
            'local_user_id' => '6',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'canonical_account_identity',
            'created_by' => 'customer_identity_ensure:rdservice.net',
            'linked_at' => now(),
        ]);

        $hasher = app(\App\CentralWallet\Application\CustomerIdentitySubjectHasher::class);
        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customerId,
            'credential_type' => CustomerIdentityCredentialType::Google,
            'provider' => 'google',
            'subject_hash' => $hasher->hashGoogleSubject('google-same-cwid'),
            'verified_at' => now(),
        ]);

        $this->resolveGoogle('google-same-cwid', '6')
            ->assertOk()
            ->assertJsonPath('verification_method', 'trusted_google')
            ->assertJsonPath('central_wallet_id', $wallet->id);
    }

    public function test_google_identity_conflicts_when_existing_link_points_to_different_cwid(): void
    {
        $walletA = app(\App\CentralWallet\Application\CentralWalletService::class)->create();
        $walletB = app(\App\CentralWallet\Application\CentralWalletService::class)->create();

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $walletA->id,
            'site_code' => self::SITE,
            'local_user_id' => '100',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'canonical_account_identity',
            'created_by' => 'customer_identity_ensure:rdservice.net',
            'linked_at' => now(),
        ]);

        $customerId = (string) Str::uuid();
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $walletB->id,
            'status' => 'active',
        ]);
        $hasher = app(\App\CentralWallet\Application\CustomerIdentitySubjectHasher::class);
        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customerId,
            'credential_type' => CustomerIdentityCredentialType::Google,
            'provider' => 'google',
            'subject_hash' => $hasher->hashGoogleSubject('google-other-cwid'),
            'verified_at' => now(),
        ]);

        $this->resolveGoogle('google-other-cwid', '100')
            ->assertStatus(409)
            ->assertJsonPath('error', 'account_link_cwid_conflict');
    }

    public function test_trusted_link_enables_customer_ledger_history_read(): void
    {
        config([
            'central_wallet.ledger_read.customer_history.enabled' => true,
            'central_wallet.ledger_read.customer_history.authorized_callers' => [self::SITE],
            'central_wallet.ledger_read.customer_history.authorized_source_systems' => [self::SITE],
        ]);

        $resolve = $this->resolveVerifiedEmail('statement@example.com', '3');
        $resolve->assertCreated();
        $cwid = (string) $resolve->json('central_wallet_id');

        $this->authenticatedAs(self::SITE)
            ->getJson("/api/central-wallet/v1/wallets/{$cwid}/ledger-history?local_user_id=3")
            ->assertOk()
            ->assertJsonCount(0, 'data');
    }

    private function resolveVerifiedEmail(string $email, string $localUserId, string $idempotencyKey = 'resolve-1'): \Illuminate\Testing\TestResponse
    {
        return $this->authenticatedAs(self::SITE)->postJson('/api/central-wallet/v1/customer-identity/resolve', [
            'idempotency_key' => $idempotencyKey,
            'site_code' => self::SITE,
            'local_user_id' => $localUserId,
            'identity' => [
                'type' => 'verified_email',
                'email' => $email,
            ],
        ]);
    }

    private function resolveGoogle(string $googleSubject, string $localUserId, string $idempotencyKey = 'resolve-google-1'): \Illuminate\Testing\TestResponse
    {
        return $this->authenticatedAs(self::SITE)->postJson('/api/central-wallet/v1/customer-identity/resolve', [
            'idempotency_key' => $idempotencyKey,
            'site_code' => self::SITE,
            'local_user_id' => $localUserId,
            'identity' => [
                'type' => 'google',
                'google_subject' => $googleSubject,
            ],
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
