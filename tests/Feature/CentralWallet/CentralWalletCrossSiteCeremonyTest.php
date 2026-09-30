<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\CeremonyVerificationProofValidator;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletCeremonyIdentity;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CentralWalletCrossSiteCeremonyTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-ceremony-token';

    private const BOX_SITE = 'radiumbox.com';

    private const RDIN_SITE = 'rdservice.in';

    private const SIGNING_SECRET_BOX = 'test-ceremony-signing-secret-box-32ch';

    private const SIGNING_SECRET_RDIN = 'test-ceremony-signing-secret-rdin-32c';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.ceremony.signing_secrets' => [
                self::BOX_SITE => self::SIGNING_SECRET_BOX,
                self::RDIN_SITE => self::SIGNING_SECRET_RDIN,
            ],
            'central_wallet.ceremony.cross_site_enabled' => true,
            'central_wallet.ceremony.cross_site_cohort.enabled' => true,
            'central_wallet.ceremony.cross_site_cohort.allowed_local_user_ids' => [3],
        ]);
    }

    public function test_resolves_existing_cwid_from_other_site_with_authorization(): void
    {
        $phoneHash = hash('sha256', '+919876543210');
        $existingCwid = $this->seedLinkedSiteUser(self::BOX_SITE, '3', $phoneHash);

        $proof = $this->issueProof(self::RDIN_SITE, '3', (string) Str::uuid(), $phoneHash, self::SIGNING_SECRET_RDIN);

        $this->complete(self::RDIN_SITE, 'idem-cross-1', '3', $proof, 'OWNER-CROSS-LINK-TEST-001')
            ->assertCreated()
            ->assertJsonPath('central_wallet_id', $existingCwid)
            ->assertJsonPath('provision_action', 'resolved_cross_site_existing');

        $this->assertSame(1, DB::table('central_wallets')->count());
        $this->assertDatabaseHas('central_wallet_account_links', [
            'site_code' => self::RDIN_SITE,
            'local_user_id' => '3',
            'central_wallet_id' => $existingCwid,
            'status' => AccountLinkStatus::Active->value,
        ]);
        $this->assertDatabaseHas('central_wallet_audit_events', [
            'event_type' => 'ceremony.linked',
            'central_wallet_id' => $existingCwid,
        ]);
    }

    public function test_cohort_denied_when_user_not_allowlisted(): void
    {
        config(['central_wallet.ceremony.cross_site_cohort.allowed_local_user_ids' => [99]]);

        $phoneHash = hash('sha256', '+919888888888');
        $this->seedLinkedSiteUser(self::BOX_SITE, '8', $phoneHash);

        $proof = $this->issueProof(self::RDIN_SITE, '8', (string) Str::uuid(), $phoneHash, self::SIGNING_SECRET_RDIN);

        $this->complete(self::RDIN_SITE, 'idem-cohort-deny', '8', $proof, 'OWNER-CROSS-LINK-TEST-COHORT')
            ->assertForbidden()
            ->assertJsonPath('error', 'cross_site_cohort_denied');

        $this->assertDatabaseMissing('central_wallet_account_links', [
            'site_code' => self::RDIN_SITE,
            'local_user_id' => '8',
        ]);
    }

    public function test_cohort_disabled_blocks_cross_site_resolution(): void
    {
        config(['central_wallet.ceremony.cross_site_cohort.enabled' => false]);

        $phoneHash = hash('sha256', '+919111111111');
        $this->seedLinkedSiteUser(self::BOX_SITE, '3', $phoneHash);

        $proof = $this->issueProof(self::RDIN_SITE, '3', (string) Str::uuid(), $phoneHash, self::SIGNING_SECRET_RDIN);

        $this->complete(self::RDIN_SITE, 'idem-cohort-off', '3', $proof, 'OWNER-CROSS-LINK-TEST-COHORT-OFF')
            ->assertForbidden()
            ->assertJsonPath('error', 'cross_site_cohort_denied')
            ->assertJsonPath('reason', 'cross_site_cohort_disabled');
    }

    public function test_empty_cohort_blocks_cross_site_resolution(): void
    {
        config(['central_wallet.ceremony.cross_site_cohort.allowed_local_user_ids' => []]);

        $phoneHash = hash('sha256', '+919111111112');
        $this->seedLinkedSiteUser(self::BOX_SITE, '3', $phoneHash);

        $proof = $this->issueProof(self::RDIN_SITE, '3', (string) Str::uuid(), $phoneHash, self::SIGNING_SECRET_RDIN);

        $this->complete(self::RDIN_SITE, 'idem-cohort-empty', '3', $proof, 'OWNER-CROSS-LINK-TEST-COHORT-EMPTY')
            ->assertForbidden()
            ->assertJsonPath('error', 'cross_site_cohort_denied')
            ->assertJsonPath('reason', 'cross_site_cohort_empty');
    }

    public function test_cross_site_match_without_authorization_fails_closed(): void
    {
        $phoneHash = hash('sha256', '+919111111111');
        $this->seedLinkedSiteUser(self::BOX_SITE, '3', $phoneHash);

        $proof = $this->issueProof(self::RDIN_SITE, '3', (string) Str::uuid(), $phoneHash, self::SIGNING_SECRET_RDIN);

        $this->complete(self::RDIN_SITE, 'idem-cross-auth', '3', $proof)
            ->assertForbidden()
            ->assertJsonPath('error', 'cross_site_authorization_required');

        $this->assertDatabaseMissing('central_wallet_account_links', [
            'site_code' => self::RDIN_SITE,
            'local_user_id' => '3',
        ]);
    }

    public function test_ambiguous_cross_site_identity_returns_conflict(): void
    {
        $phoneHash = hash('sha256', '+919222222222');
        $this->seedLinkedSiteUser(self::BOX_SITE, '3', $phoneHash);
        $this->seedLinkedSiteUser('rdservice.net', '3', $phoneHash);

        $proof = $this->issueProof(self::RDIN_SITE, '3', (string) Str::uuid(), $phoneHash, self::SIGNING_SECRET_RDIN);

        $this->complete(self::RDIN_SITE, 'idem-ambiguous', '3', $proof, 'OWNER-CROSS-LINK-TEST-002')
            ->assertStatus(409)
            ->assertJsonPath('error', 'cross_site_identity_ambiguous');

        $this->assertDatabaseMissing('central_wallet_account_links', [
            'site_code' => self::RDIN_SITE,
            'local_user_id' => '3',
        ]);
    }

    public function test_cross_site_disabled_creates_new_cwid_even_with_matching_phone_hash(): void
    {
        config(['central_wallet.ceremony.cross_site_enabled' => false]);

        $phoneHash = hash('sha256', '+919333333333');
        $existingCwid = $this->seedLinkedSiteUser(self::BOX_SITE, '12', $phoneHash);

        $proof = $this->issueProof(self::RDIN_SITE, '12', (string) Str::uuid(), $phoneHash, self::SIGNING_SECRET_RDIN);

        $response = $this->complete(self::RDIN_SITE, 'idem-disabled', '12', $proof, 'OWNER-CROSS-LINK-TEST-003')
            ->assertCreated();

        $this->assertNotSame($existingCwid, (string) $response->json('central_wallet_id'));
        $this->assertSame(2, DB::table('central_wallets')->count());
    }

    public function test_already_linked_user_returns_resolved_local_link(): void
    {
        $phoneHash = hash('sha256', '+919444444444');
        $cwid = $this->seedLinkedSiteUser(self::RDIN_SITE, '15', $phoneHash);

        $proof = $this->issueProof(self::RDIN_SITE, '15', (string) Str::uuid(), $phoneHash, self::SIGNING_SECRET_RDIN);

        $this->complete(self::RDIN_SITE, 'idem-existing-link', '15', $proof)
            ->assertOk()
            ->assertJsonPath('central_wallet_id', $cwid)
            ->assertJsonPath('provision_action', 'resolved_local_link');
    }

    public function test_cross_site_idempotent_replay_returns_same_link(): void
    {
        $phoneHash = hash('sha256', '+919555555555');
        $existingCwid = $this->seedLinkedSiteUser(self::BOX_SITE, '3', $phoneHash);
        $proof = $this->issueProof(self::RDIN_SITE, '3', (string) Str::uuid(), $phoneHash, self::SIGNING_SECRET_RDIN);

        $this->complete(self::RDIN_SITE, 'idem-replay-cross', '3', $proof, 'OWNER-CROSS-LINK-TEST-004')
            ->assertCreated();

        $this->complete(self::RDIN_SITE, 'idem-replay-cross', '3', $proof, 'OWNER-CROSS-LINK-TEST-004')
            ->assertStatus(201)
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('central_wallet_id', $existingCwid);

        $this->assertSame(1, DB::table('central_wallets')->count());
    }

    public function test_cwid_already_linked_elsewhere_on_same_site_still_conflicts(): void
    {
        $phoneHash = hash('sha256', '+919666666666');
        $cwid = $this->seedLinkedSiteUser(self::RDIN_SITE, '20', $phoneHash);

        CentralWalletCeremonyIdentity::query()->create([
            'id' => (string) Str::uuid(),
            'site_code' => self::RDIN_SITE,
            'local_user_id' => '21',
            'verified_phone_e164_hash' => hash('sha256', '+919060606060'),
            'central_wallet_id' => $cwid,
            'first_verified_at' => now(),
        ]);

        $proof = $this->issueProof(self::RDIN_SITE, '21', (string) Str::uuid(), hash('sha256', '+919060606060'), self::SIGNING_SECRET_RDIN);

        $this->complete(self::RDIN_SITE, 'idem-cwid-conflict', '21', $proof)
            ->assertStatus(409)
            ->assertJsonPath('error', 'cwid_linked_elsewhere');
    }

    private function seedLinkedSiteUser(string $siteCode, string $localUserId, string $phoneHash): string
    {
        $cwid = (string) Str::uuid();
        DB::table('central_wallets')->insert([
            'id' => $cwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('central_wallet_account_links')->insert([
            'central_wallet_id' => $cwid,
            'site_code' => $siteCode,
            'local_user_id' => $localUserId,
            'status' => AccountLinkStatus::Active->value,
            'verification_method' => 'm2_whatsapp_otp',
            'created_by' => 'test',
            'linked_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CentralWalletCeremonyIdentity::query()->create([
            'id' => (string) Str::uuid(),
            'site_code' => $siteCode,
            'local_user_id' => $localUserId,
            'verified_phone_e164_hash' => $phoneHash,
            'central_wallet_id' => $cwid,
            'first_verified_at' => now(),
        ]);

        return $cwid;
    }

    private function issueProof(
        string $siteCode,
        string $localUserId,
        string $attemptId,
        string $phoneHash,
        string $secret,
    ): string {
        return CeremonyVerificationProofValidator::issueForTesting(
            siteCode: $siteCode,
            localUserId: $localUserId,
            ceremonyAttemptId: $attemptId,
            verifiedPhoneE164Hash: $phoneHash,
            secret: $secret,
        );
    }

    private function complete(
        string $siteCode,
        string $idempotencyKey,
        string $localUserId,
        string $proof,
        ?string $crossSiteAuthRef = null,
    ): TestResponse {
        $payload = [
            'idempotency_key' => $idempotencyKey,
            'site_code' => $siteCode,
            'local_user_id' => $localUserId,
            'ceremony_verification_ref' => $proof,
            'verification_method' => 'm2_whatsapp_otp',
        ];

        if ($crossSiteAuthRef !== null) {
            $payload['cross_site_link_authorization_ref'] = $crossSiteAuthRef;
        }

        return $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $siteCode,
        ])->postJson('/api/central-wallet/v1/ceremony/complete', $payload);
    }
}
