<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CentralWalletCustomerIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-customer-identity-token';

    private const BOX_SITE = 'radiumbox.com';

    private const RDIN_SITE = 'rdservice.in';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.idempotency_retention_days' => 90,
            'central_wallet.customer_identity.enabled' => true,
            'central_wallet.customer_identity.google_enabled' => true,
            'central_wallet.customer_identity.verified_email_enabled' => true,
            'central_wallet.customer_identity.verified_mobile_enabled' => true,
        ]);
    }

    public function test_verified_google_identity_creates_customer_wallet_and_active_link(): void
    {
        $response = $this->resolveGoogle(self::BOX_SITE, '3', 'google-subject-abc', 'idem-google-1')
            ->assertCreated()
            ->assertJsonPath('provision_action', 'created_customer')
            ->assertJsonStructure(['desk_customer_id', 'central_wallet_id', 'link_id', 'link_status']);

        $deskCustomerId = (string) $response->json('desk_customer_id');
        $cwid = (string) $response->json('central_wallet_id');

        $this->assertDatabaseHas('central_customers', [
            'id' => $deskCustomerId,
            'central_wallet_id' => $cwid,
        ]);

        $this->assertDatabaseHas('central_wallet_account_links', [
            'desk_customer_id' => $deskCustomerId,
            'central_wallet_id' => $cwid,
            'site_code' => self::BOX_SITE,
            'local_user_id' => '3',
            'status' => AccountLinkStatus::Active->value,
            'verification_method' => 'trusted_google',
        ]);
    }

    public function test_same_google_identity_on_two_sites_resolves_same_customer(): void
    {
        $first = $this->resolveGoogle(self::BOX_SITE, '3', 'google-subject-shared', 'idem-box')
            ->assertCreated();

        $second = $this->resolveGoogle(self::RDIN_SITE, '99', 'google-subject-shared', 'idem-rdin')
            ->assertCreated()
            ->assertJsonPath('provision_action', 'resolved_existing_customer');

        $this->assertSame((string) $first->json('desk_customer_id'), (string) $second->json('desk_customer_id'));
        $this->assertSame((string) $first->json('central_wallet_id'), (string) $second->json('central_wallet_id'));
    }

    public function test_verified_email_resolves_existing_google_customer(): void
    {
        $google = $this->resolveGoogle(self::BOX_SITE, '10', 'google-subject-email-bridge', 'idem-g')
            ->assertCreated();

        $customerId = (string) $google->json('desk_customer_id');

        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customerId,
            'credential_type' => 'verified_email',
            'provider' => 'desk_email',
            'subject_hash' => hash('sha256', 'verified_email:user@example.com'),
            'verified_at' => now(),
        ]);

        $this->resolveVerifiedEmail(self::RDIN_SITE, '20', 'user@example.com', 'idem-email')
            ->assertCreated()
            ->assertJsonPath('desk_customer_id', $customerId);
    }

    public function test_unverified_email_type_is_rejected(): void
    {
        $this->resolve(self::BOX_SITE, '3', [
            'type' => 'email',
            'email' => 'user@example.com',
        ], 'idem-bad-email')
            ->assertStatus(422)
            ->assertJsonPath('error', 'unsupported_identity_type');
    }

    public function test_identity_resolution_is_idempotent(): void
    {
        $payload = [
            'type' => 'google',
            'google_subject' => 'google-subject-idempotent',
        ];

        $first = $this->resolve(self::BOX_SITE, '3', $payload, 'idem-same')
            ->assertCreated();

        $this->resolve(self::BOX_SITE, '3', $payload, 'idem-same')
            ->assertCreated()
            ->assertJsonPath('desk_customer_id', $first->json('desk_customer_id'));
    }

    public function test_site_mismatch_is_rejected(): void
    {
        $this->withHeaders($this->authHeaders(self::BOX_SITE))
            ->postJson('/api/central-wallet/v1/customer-identity/resolve', [
                'idempotency_key' => 'idem-site-mismatch',
                'site_code' => self::RDIN_SITE,
                'local_user_id' => '3',
                'identity' => [
                    'type' => 'google',
                    'google_subject' => 'google-subject-site-mismatch',
                ],
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'site_mismatch');
    }

    public function test_existing_local_link_conflict_fails_closed(): void
    {
        $walletA = (string) Str::uuid();
        $walletB = (string) Str::uuid();

        $this->seedWallet($walletA);
        $this->seedWallet($walletB);

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $walletA,
            'site_code' => self::BOX_SITE,
            'local_user_id' => '3',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'm2_dual_otp',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->resolveGoogle(self::BOX_SITE, '3', 'google-subject-conflict', 'idem-conflict')
            ->assertStatus(409)
            ->assertJsonPath('error', 'identity_link_conflict');
    }

    public function test_customer_identity_disabled_returns_service_unavailable(): void
    {
        config(['central_wallet.customer_identity.enabled' => false]);

        $this->resolveGoogle(self::BOX_SITE, '3', 'google-subject-disabled', 'idem-disabled')
            ->assertStatus(503)
            ->assertJsonPath('error', 'customer_identity_disabled');
    }

    public function test_google_identity_disabled_returns_credential_disabled(): void
    {
        config(['central_wallet.customer_identity.google_enabled' => false]);

        $this->resolveGoogle(self::BOX_SITE, '3', 'google-subject-cred-disabled', 'idem-cred-disabled')
            ->assertStatus(503)
            ->assertJsonPath('error', 'customer_identity_credential_disabled');
    }

    public function test_verified_mobile_does_not_block_google_resolution(): void
    {
        $response = $this->resolveGoogle(self::BOX_SITE, '3', 'google-subject-mobile-optional', 'idem-mobile-opt')
            ->assertCreated();

        $customerId = (string) $response->json('desk_customer_id');

        $this->resolve(self::BOX_SITE, '3', [
            'type' => 'verified_mobile',
            'mobile_e164' => '+919876543210',
        ], 'idem-mobile-add')
            ->assertOk()
            ->assertJsonPath('desk_customer_id', $customerId);

        $this->assertDatabaseHas('central_customer_identity_credentials', [
            'desk_customer_id' => $customerId,
            'credential_type' => 'verified_mobile',
        ]);
    }

    public function test_audit_events_are_recorded_for_customer_creation_and_linking(): void
    {
        $this->resolveGoogle(self::BOX_SITE, '3', 'google-subject-audit', 'idem-audit')
            ->assertCreated();

        $this->assertDatabaseHas('central_wallet_audit_events', [
            'event_type' => 'customer_identity.created',
        ]);

        $this->assertDatabaseHas('central_wallet_audit_events', [
            'event_type' => 'customer_identity.linked',
        ]);
    }

    private function resolveGoogle(string $site, string $localUserId, string $googleSubject, string $idempotencyKey): TestResponse
    {
        return $this->resolve($site, $localUserId, [
            'type' => 'google',
            'google_subject' => $googleSubject,
        ], $idempotencyKey);
    }

    private function resolveVerifiedEmail(string $site, string $localUserId, string $email, string $idempotencyKey): TestResponse
    {
        return $this->resolve($site, $localUserId, [
            'type' => 'verified_email',
            'email' => $email,
        ], $idempotencyKey);
    }

    /**
     * @param  array<string, mixed>  $identity
     */
    private function resolve(string $site, string $localUserId, array $identity, string $idempotencyKey): TestResponse
    {
        return $this->withHeaders($this->authHeaders($site))
            ->postJson('/api/central-wallet/v1/customer-identity/resolve', [
                'idempotency_key' => $idempotencyKey,
                'site_code' => $site,
                'local_user_id' => $localUserId,
                'identity' => $identity,
            ]);
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $site): array
    {
        return [
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $site,
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
