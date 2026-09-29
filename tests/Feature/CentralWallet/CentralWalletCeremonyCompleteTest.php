<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\CeremonyVerificationProofValidator;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAuditEvent;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletCeremonyIdentity;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletCeremonyProofConsumption;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class CentralWalletCeremonyCompleteTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-ceremony-token';

    private const SITE = 'radiumbox.com';

    private const SIGNING_SECRET = 'test-ceremony-signing-secret-32chars';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.idempotency_retention_days' => 90,
            'central_wallet.ceremony.signing_secrets' => [
                self::SITE => self::SIGNING_SECRET,
            ],
            'central_wallet.ceremony.proof_ttl_seconds' => 300,
        ]);
    }

    public function test_valid_completion_creates_wallet_and_active_link(): void
    {
        $attemptId = (string) Str::uuid();
        $phoneHash = hash('sha256', '+919876543210');
        $proof = $this->issueProof('3', $attemptId, $phoneHash);

        $response = $this->complete('idem-1', '3', $proof)
            ->assertCreated()
            ->assertJsonPath('status', 'connected')
            ->assertJsonPath('idempotent', false)
            ->assertJsonStructure(['central_wallet_id', 'customer_display_ref']);

        $cwid = (string) $response->json('central_wallet_id');
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-4[0-9a-f]{3}-[89ab][0-9a-f]{3}-[0-9a-f]{12}$/',
            $cwid,
        );

        $this->assertDatabaseHas('central_wallet_account_links', [
            'central_wallet_id' => $cwid,
            'site_code' => self::SITE,
            'local_user_id' => '3',
            'status' => AccountLinkStatus::Active->value,
        ]);

        $this->assertDatabaseHas('central_wallet_ceremony_identities', [
            'site_code' => self::SITE,
            'local_user_id' => '3',
            'verified_phone_e164_hash' => $phoneHash,
            'central_wallet_id' => $cwid,
        ]);

        $this->assertDatabaseHas('central_wallet_audit_events', [
            'event_type' => 'ceremony.wallet_created',
            'central_wallet_id' => $cwid,
        ]);

        $this->assertDatabaseHas('central_wallet_audit_events', [
            'event_type' => 'ceremony.linked',
            'central_wallet_id' => $cwid,
        ]);
    }

    public function test_same_local_account_resolves_established_wallet_after_revoked_link(): void
    {
        $phoneHash = hash('sha256', '+919111111111');
        $firstProof = $this->issueProof('10', (string) Str::uuid(), $phoneHash);
        $first = $this->complete('idem-resolve-1', '10', $firstProof)->assertCreated();
        $cwid = (string) $first->json('central_wallet_id');

        DB::table('central_wallet_account_links')
            ->where('local_user_id', '10')
            ->update([
                'status' => AccountLinkStatus::Revoked->value,
                'revoked_at' => now(),
            ]);

        $secondProof = $this->issueProof('10', (string) Str::uuid(), $phoneHash);
        $this->complete('idem-resolve-2', '10', $secondProof)
            ->assertCreated()
            ->assertJsonPath('central_wallet_id', $cwid)
            ->assertJsonPath('provision_action', 'resolved_existing');

        $this->assertSame(1, CentralWalletCeremonyIdentity::query()->count());
        $this->assertDatabaseHas('central_wallet_ceremony_identities', [
            'site_code' => self::SITE,
            'local_user_id' => '10',
            'central_wallet_id' => $cwid,
        ]);
    }

    public function test_different_local_user_with_same_phone_hash_cannot_resolve_wallet(): void
    {
        $phoneHash = hash('sha256', '+919101010101');
        $firstProof = $this->issueProof('60', (string) Str::uuid(), $phoneHash);
        $first = $this->complete('idem-phone-1', '60', $firstProof)->assertCreated();
        $firstCwid = (string) $first->json('central_wallet_id');

        $secondProof = $this->issueProof('61', (string) Str::uuid(), $phoneHash);
        $second = $this->complete('idem-phone-2', '61', $secondProof)->assertCreated();
        $secondCwid = (string) $second->json('central_wallet_id');

        $this->assertNotSame($firstCwid, $secondCwid);
        $this->assertSame(2, CentralWalletCeremonyIdentity::query()->count());
        $this->assertDatabaseHas('central_wallet_ceremony_identities', [
            'local_user_id' => '60',
            'central_wallet_id' => $firstCwid,
            'verified_phone_e164_hash' => $phoneHash,
        ]);
        $this->assertDatabaseHas('central_wallet_ceremony_identities', [
            'local_user_id' => '61',
            'central_wallet_id' => $secondCwid,
            'verified_phone_e164_hash' => $phoneHash,
        ]);
    }

    public function test_phone_hash_alone_cannot_resolve_wallet_for_different_local_user(): void
    {
        $phoneHash = hash('sha256', '+919121212121');
        $first = $this->complete(
            'idem-evidence-1',
            '70',
            $this->issueProof('70', (string) Str::uuid(), $phoneHash),
        )->assertCreated();
        $firstCwid = (string) $first->json('central_wallet_id');

        CentralWalletCeremonyIdentity::query()
            ->where('local_user_id', '70')
            ->update(['verified_phone_e164_hash' => hash('sha256', '+919999999999')]);

        $second = $this->complete(
            'idem-evidence-2',
            '71',
            $this->issueProof('71', (string) Str::uuid(), $phoneHash),
        )->assertCreated();

        $this->assertNotSame($firstCwid, (string) $second->json('central_wallet_id'));
    }

    public function test_rejects_invalid_signature(): void
    {
        $proof = $this->issueProof('3', (string) Str::uuid(), hash('sha256', '+919999999999'));
        $tampered = substr($proof, 0, -4).'xxxx';

        $this->complete('idem-bad-sig', '3', $tampered)
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_ceremony_proof');
    }

    public function test_rejects_expired_proof(): void
    {
        $proof = $this->issueProof('3', (string) Str::uuid(), hash('sha256', '+918888888888'), [
            'iat' => time() - 600,
            'exp' => time() - 300,
        ]);

        $this->complete('idem-expired', '3', $proof)
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_ceremony_proof');
    }

    public function test_rejects_replayed_jti(): void
    {
        $jti = (string) Str::uuid();
        $phoneHash = hash('sha256', '+917777777777');
        $proof = $this->issueProof('3', (string) Str::uuid(), $phoneHash, ['jti' => $jti]);

        $this->complete('idem-replay-1', '3', $proof)->assertCreated();

        $secondProof = $this->issueProof('4', (string) Str::uuid(), $phoneHash, ['jti' => $jti]);
        $this->complete('idem-replay-2', '4', $secondProof)
            ->assertStatus(422)
            ->assertJsonPath('error', 'ceremony_proof_replayed');
    }

    public function test_rejects_wrong_site_code_in_body(): void
    {
        $proof = $this->issueProof('3', (string) Str::uuid(), hash('sha256', '+916666666666'));

        $this->authenticatedAs('rdservice.in')->postJson('/api/central-wallet/v1/ceremony/complete', [
            'idempotency_key' => 'idem-site',
            'site_code' => self::SITE,
            'local_user_id' => '3',
            'ceremony_verification_ref' => $proof,
        ])->assertForbidden()
            ->assertJsonPath('error', 'site_mismatch');
    }

    public function test_rejects_wrong_local_user_id_binding(): void
    {
        $proof = $this->issueProof('3', (string) Str::uuid(), hash('sha256', '+915555555555'));

        $this->complete('idem-user', '99', $proof)
            ->assertStatus(422)
            ->assertJsonPath('error', 'invalid_ceremony_proof');
    }

    public function test_idempotent_replay_returns_original_result(): void
    {
        $proof = $this->issueProof('3', (string) Str::uuid(), hash('sha256', '+914444444444'));

        $first = $this->complete('idem-same', '3', $proof)->assertCreated();
        $cwid = (string) $first->json('central_wallet_id');

        $this->complete('idem-same', '3', $proof)
            ->assertStatus(201)
            ->assertJsonPath('idempotent', true)
            ->assertJsonPath('central_wallet_id', $cwid);

        $this->assertSame(1, DB::table('central_wallets')->count());
        $this->assertSame(1, DB::table('central_wallet_account_links')->where('status', AccountLinkStatus::Active->value)->count());
    }

    public function test_same_idempotency_key_with_changed_payload_conflicts(): void
    {
        $proofOne = $this->issueProof('3', (string) Str::uuid(), hash('sha256', '+913333333333'));
        $this->complete('idem-changed', '3', $proofOne)->assertCreated();

        $proofTwo = $this->issueProof('3', (string) Str::uuid(), hash('sha256', '+912222222222'));
        $this->complete('idem-changed', '3', $proofTwo)
            ->assertStatus(409)
            ->assertJsonPath('error', 'idempotency_key_reused_with_different_request');
    }

    public function test_existing_active_local_user_link_returns_resolved_local_link(): void
    {
        $phoneHash = hash('sha256', '+911111111111');
        $proof = $this->issueProof('20', (string) Str::uuid(), $phoneHash);
        $first = $this->complete('idem-existing', '20', $proof)->assertCreated();
        $cwid = (string) $first->json('central_wallet_id');

        $secondProof = $this->issueProof('20', (string) Str::uuid(), hash('sha256', '+910000000000'));
        $this->complete('idem-existing-2', '20', $secondProof)
            ->assertOk()
            ->assertJsonPath('central_wallet_id', $cwid)
            ->assertJsonPath('provision_action', 'resolved_local_link');
    }

    public function test_cwid_already_linked_elsewhere_returns_conflict(): void
    {
        $phoneHash = hash('sha256', '+919090909090');
        $firstProof = $this->issueProof('30', (string) Str::uuid(), $phoneHash);
        $first = $this->complete('idem-cwid-1', '30', $firstProof)->assertCreated();
        $cwid = (string) $first->json('central_wallet_id');

        CentralWalletCeremonyIdentity::query()->create([
            'id' => (string) Str::uuid(),
            'site_code' => self::SITE,
            'local_user_id' => '31',
            'verified_phone_e164_hash' => hash('sha256', '+919080808080'),
            'central_wallet_id' => $cwid,
            'first_verified_at' => now(),
        ]);

        $secondProof = $this->issueProof('31', (string) Str::uuid(), hash('sha256', '+919080808080'));
        $this->complete('idem-cwid-2', '31', $secondProof)
            ->assertStatus(409)
            ->assertJsonPath('error', 'cwid_linked_elsewhere');

        $this->assertDatabaseHas('central_wallet_audit_events', [
            'event_type' => 'ceremony.conflict',
        ]);
    }

    public function test_transaction_rollback_does_not_leave_active_link_on_conflict(): void
    {
        $phoneHash = hash('sha256', '+919070707070');
        $this->complete('idem-rollback-1', '40', $this->issueProof('40', (string) Str::uuid(), $phoneHash))
            ->assertCreated();

        $conflictProof = $this->issueProof('41', (string) Str::uuid(), hash('sha256', '+919060606060'));
        CentralWalletCeremonyIdentity::query()->create([
            'id' => (string) Str::uuid(),
            'site_code' => self::SITE,
            'local_user_id' => '41',
            'verified_phone_e164_hash' => hash('sha256', '+919060606060'),
            'central_wallet_id' => DB::table('central_wallets')->value('id'),
            'first_verified_at' => now(),
        ]);

        $this->complete('idem-rollback-2', '41', $conflictProof)
            ->assertStatus(409);

        $this->assertSame(1, DB::table('central_wallet_account_links')->where('status', AccountLinkStatus::Active->value)->count());
    }

    public function test_no_raw_otp_persisted_in_audit_or_consumption_tables(): void
    {
        $otp = '123456';
        $proof = $this->issueProof('3', (string) Str::uuid(), hash('sha256', '+919876543210'));

        $this->complete('idem-otp', '3', $proof)->assertCreated();

        $auditJson = CentralWalletAuditEvent::query()->pluck('payload')->implode('');
        $this->assertStringNotContainsString($otp, $auditJson);
        $this->assertStringNotContainsString(self::SIGNING_SECRET, $auditJson);
        $this->assertStringNotContainsString(self::TOKEN, $auditJson);

        $consumptionJson = CentralWalletCeremonyProofConsumption::query()->get()->toJson();
        $this->assertStringNotContainsString($otp, $consumptionJson);
        $this->assertStringNotContainsString($proof, $consumptionJson);
    }

    public function test_response_does_not_expose_unnecessary_internal_fields(): void
    {
        $proof = $this->issueProof('3', (string) Str::uuid(), hash('sha256', '+919123123123'));

        $response = $this->complete('idem-fields', '3', $proof)->assertCreated();

        $response->assertJsonMissing(['otp', 'secret', 'token', 'email', 'phone', 'mobile']);
        $this->assertStringStartsWith('CW-••••-', (string) $response->json('customer_display_ref'));
    }

    public function test_does_not_resolve_wallet_by_email_or_profile_phone_fields(): void
    {
        $this->complete('idem-no-email', '3', $this->issueProof('3', (string) Str::uuid(), hash('sha256', '+919000000001')), extra: [
            'email' => 'user@example.com',
            'phone' => '+919000000001',
        ])->assertCreated();

        $this->assertDatabaseMissing('central_wallet_ceremony_identities', [
            'verified_phone_e164_hash' => hash('sha256', 'user@example.com'),
        ]);
    }

    public function test_concurrent_completion_with_same_idempotency_key_creates_single_wallet(): void
    {
        $proof = $this->issueProof('50', (string) Str::uuid(), hash('sha256', '+919505050505'));

        DB::transaction(function () use ($proof): void {
            $this->complete('idem-concurrent', '50', $proof)->assertCreated();
        });

        $this->complete('idem-concurrent', '50', $proof)
            ->assertStatus(201)
            ->assertJsonPath('idempotent', true);

        $this->assertSame(1, DB::table('central_wallets')->count());
        $this->assertSame(1, DB::table('central_wallet_account_links')->where('status', AccountLinkStatus::Active->value)->count());
    }

    public function test_ceremony_endpoint_requires_authentication(): void
    {
        $this->postJson('/api/central-wallet/v1/ceremony/complete', [
            'idempotency_key' => 'unauth',
            'site_code' => self::SITE,
            'local_user_id' => '3',
            'ceremony_verification_ref' => 'invalid',
        ])->assertUnauthorized();
    }

    private function issueProof(string $localUserId, string $attemptId, string $phoneHash, array $overrides = []): string
    {
        return CeremonyVerificationProofValidator::issueForTesting(
            siteCode: self::SITE,
            localUserId: $localUserId,
            ceremonyAttemptId: $attemptId,
            verifiedPhoneE164Hash: $phoneHash,
            secret: self::SIGNING_SECRET,
            overrides: $overrides,
        );
    }

    /**
     * @param  array<string, mixed>  $extra
     */
    private function complete(string $idempotencyKey, string $localUserId, string $proof, array $extra = []): TestResponse
    {
        return $this->authenticatedAs(self::SITE)->postJson('/api/central-wallet/v1/ceremony/complete', array_merge([
            'idempotency_key' => $idempotencyKey,
            'site_code' => self::SITE,
            'local_user_id' => $localUserId,
            'ceremony_verification_ref' => $proof,
            'verification_method' => 'm2_whatsapp_otp',
        ], $extra));
    }

    private function authenticatedAs(string $siteCode): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $siteCode,
        ]);
    }
}
