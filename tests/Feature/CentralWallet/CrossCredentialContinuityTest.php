<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Support\AccountLinkIdentityMetadata;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CrossCredentialContinuityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-cross-credential-token';

    private const BOX = 'radiumbox.com';

    private const RDIN = 'rdservice.in';

    private const OWNER_EMAIL = 'ravithelavi@gmail.com';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.customer_identity.enabled' => true,
            'central_wallet.customer_identity.google_enabled' => true,
            'central_wallet.customer_identity.verified_email_enabled' => true,
            'central_wallet.customer_identity.verified_mobile_enabled' => true,
        ]);
    }

    public function test_verified_email_reuses_customer_with_mobile_credential_via_link_metadata(): void
    {
        $hasher = app(CustomerIdentitySubjectHasher::class);
        $emailHash = $hasher->hashVerifiedEmail(self::OWNER_EMAIL);

        [$customerId, $cwid] = $this->seedTrustedBoxLinkWithEmailMetadata('3', $emailHash);

        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customerId,
            'credential_type' => 'verified_mobile',
            'provider' => 'desk_mobile',
            'subject_hash' => $hasher->hashVerifiedMobileE164('+919852525656'),
            'verified_at' => now(),
        ]);

        $email = $this->resolveVerifiedEmail(self::RDIN, '3', self::OWNER_EMAIL, 'idem-email-rdin-3')
            ->assertCreated()
            ->assertJsonPath('provision_action', 'attached_credential_via_continuity');

        $this->assertSame($customerId, (string) $email->json('desk_customer_id'));
        $this->assertSame($cwid, (string) $email->json('central_wallet_id'));

        $this->assertDatabaseHas('central_customer_identity_credentials', [
            'desk_customer_id' => $customerId,
            'credential_type' => 'verified_mobile',
        ]);
        $this->assertDatabaseHas('central_customer_identity_credentials', [
            'desk_customer_id' => $customerId,
            'credential_type' => 'verified_email',
        ]);
        $this->assertDatabaseCount('central_customers', 1);
    }

    public function test_customer_can_have_verified_mobile_and_verified_email_credentials(): void
    {
        $google = $this->resolveGoogle(self::BOX, '10', 'google-shared-subject', 'idem-google-10')
            ->assertCreated();

        $customerId = (string) $google->json('desk_customer_id');

        $this->resolve(self::BOX, '10', [
            'type' => 'verified_mobile',
            'mobile_e164' => '+919876543210',
        ], 'idem-mobile-attach')
            ->assertOk()
            ->assertJsonPath('desk_customer_id', $customerId);

        $this->resolveVerifiedEmail(self::BOX, '10', self::OWNER_EMAIL, 'idem-email-attach')
            ->assertOk()
            ->assertJsonPath('desk_customer_id', $customerId);

        $this->assertDatabaseHas('central_customer_identity_credentials', [
            'desk_customer_id' => $customerId,
            'credential_type' => 'verified_mobile',
        ]);
        $this->assertDatabaseHas('central_customer_identity_credentials', [
            'desk_customer_id' => $customerId,
            'credential_type' => 'verified_email',
        ]);
        $this->assertDatabaseHas('central_customer_identity_credentials', [
            'desk_customer_id' => $customerId,
            'credential_type' => 'google',
        ]);
    }

    public function test_ambiguous_continuity_fails_closed_without_provisioning(): void
    {
        $hasher = app(CustomerIdentitySubjectHasher::class);
        $emailHash = $hasher->hashVerifiedEmail(self::OWNER_EMAIL);

        foreach (['1', '2'] as $localUserId) {
            $cwid = (string) Str::uuid();
            $customerId = (string) Str::uuid();
            $this->seedWallet($cwid);
            CentralCustomer::query()->create([
                'id' => $customerId,
                'central_wallet_id' => $cwid,
                'status' => 'active',
            ]);
            CentralWalletAccountLink::query()->create([
                'central_wallet_id' => $cwid,
                'desk_customer_id' => $customerId,
                'site_code' => self::BOX,
                'local_user_id' => $localUserId,
                'status' => AccountLinkStatus::Active,
                'verification_method' => 'm2_dual_otp',
                'created_by' => 'test',
                'linked_at' => now(),
                'metadata' => AccountLinkIdentityMetadata::withVerifiedEmailSubjectHash([], $emailHash),
            ]);
        }

        $this->resolveVerifiedEmail(self::RDIN, '99', self::OWNER_EMAIL, 'idem-ambiguous')
            ->assertStatus(409)
            ->assertJsonPath('error', 'identity_ambiguous');

        $this->assertDatabaseMissing('central_customer_identity_credentials', [
            'credential_type' => 'verified_email',
            'subject_hash' => $emailHash,
        ]);
    }

    public function test_different_unverified_phone_does_not_merge_customers(): void
    {
        $first = $this->resolve(self::RDIN, '20', [
            'type' => 'verified_mobile',
            'mobile_e164' => '+918252525656',
        ], 'idem-rdin-mobile')->assertCreated();

        $second = $this->resolveGoogle(self::BOX, '30', 'google-other-user', 'idem-box-google')
            ->assertCreated();

        $this->assertNotSame(
            (string) $first->json('desk_customer_id'),
            (string) $second->json('desk_customer_id'),
        );
    }

    public function test_repeated_verified_email_resolution_is_idempotent(): void
    {
        $hasher = app(CustomerIdentitySubjectHasher::class);
        $emailHash = $hasher->hashVerifiedEmail(self::OWNER_EMAIL);

        $this->seedTrustedBoxLinkWithEmailMetadata('3', $emailHash);

        $first = $this->resolveVerifiedEmail(self::RDIN, '3', self::OWNER_EMAIL, 'idem-email-1')
            ->assertCreated();

        $this->resolveVerifiedEmail(self::RDIN, '3', self::OWNER_EMAIL, 'idem-email-2')
            ->assertOk()
            ->assertJsonPath('desk_customer_id', $first->json('desk_customer_id'));

        $this->assertDatabaseCount('central_customers', 1);
    }

    public function test_verified_email_link_stamps_metadata_for_future_continuity(): void
    {
        $response = $this->resolveVerifiedEmail(self::RDIN, '7', self::OWNER_EMAIL, 'idem-stamp')
            ->assertCreated();

        $link = CentralWalletAccountLink::query()->find((int) $response->json('link_id'));
        $this->assertNotNull($link);
        $this->assertSame(
            app(CustomerIdentitySubjectHasher::class)->hashVerifiedEmail(self::OWNER_EMAIL),
            AccountLinkIdentityMetadata::verifiedEmailSubjectHash($link->metadata),
        );
    }

    public function test_existing_box_link_remains_valid_after_rdin_email_resolution(): void
    {
        $hasher = app(CustomerIdentitySubjectHasher::class);
        $emailHash = $hasher->hashVerifiedEmail(self::OWNER_EMAIL);

        [, , $boxLinkId] = $this->seedTrustedBoxLinkWithEmailMetadata('3', $emailHash);

        $this->resolveVerifiedEmail(self::RDIN, '3', self::OWNER_EMAIL, 'idem-rdin-preserve')
            ->assertCreated();

        $boxLink = CentralWalletAccountLink::query()->find($boxLinkId);
        $this->assertNotNull($boxLink);
        $this->assertSame(AccountLinkStatus::Active, $boxLink->status);
        $this->assertSame(self::BOX, $boxLink->site_code);
        $this->assertSame('3', $boxLink->local_user_id);
    }

    /**
     * @return array{0: string, 1: string, 2: int}
     */
    private function seedTrustedBoxLinkWithEmailMetadata(string $localUserId, string $emailHash): array
    {
        $cwid = (string) Str::uuid();
        $customerId = (string) Str::uuid();
        $this->seedWallet($cwid);
        CentralCustomer::query()->create([
            'id' => $customerId,
            'central_wallet_id' => $cwid,
            'status' => 'active',
        ]);
        $link = CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::BOX,
            'local_user_id' => $localUserId,
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'm2_dual_otp',
            'created_by' => 'test',
            'linked_at' => now(),
            'metadata' => AccountLinkIdentityMetadata::withVerifiedEmailSubjectHash([], $emailHash),
        ]);

        return [$customerId, $cwid, $link->id];
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
        return $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $site,
        ])->postJson('/api/central-wallet/v1/customer-identity/resolve', [
            'idempotency_key' => $idempotencyKey,
            'site_code' => $site,
            'local_user_id' => $localUserId,
            'identity' => $identity,
        ]);
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
