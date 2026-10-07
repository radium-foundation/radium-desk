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

class WalletRefundDestinationIdentityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-refund-destination-token';

    private const RDNET = 'rdservice.net';

    private const CONTACT_FIXTURE = __DIR__.'/../../fixtures/cw-historical-contact-index-test-fixture.json';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.wallet_refund_destination.enabled' => true,
            'central_wallet.customer_identity_ensure.enabled' => true,
            'central_wallet.historical_wallet_visibility.enabled' => true,
            'central_wallet.historical_wallet_visibility.contact_match_enabled' => true,
            'central_wallet.historical_wallet_visibility.contact_index_manifest_path' => self::CONTACT_FIXTURE,
            'central_wallet.e1_identity_migration.verification_enabled' => false,
            'central_wallet.e2_historical_settlement.verification_enabled' => false,
            'central_wallet.identity_required_cohort.provisional_display_enabled' => false,
        ]);
    }

    public function test_credential_email_match_returns_cwid_without_verification_requirement(): void
    {
        [, $cwid] = $this->seedCustomerWithVerifiedEmail('credmatch@example.com');

        $this->refundDestination(self::RDNET, '701', 'credmatch@example.com')
            ->assertOk()
            ->assertJsonPath('central_wallet_id', $cwid)
            ->assertJsonPath('refund_destination_authorized', true)
            ->assertJsonPath('verification_required', false)
            ->assertJsonPath('match_basis', 'credential');
    }

    public function test_contact_email_match_provisions_cwid_for_refund_destination(): void
    {
        $response = $this->refundDestination(self::RDNET, '702', 'unique@example.com')
            ->assertOk()
            ->assertJsonPath('refund_destination_authorized', true)
            ->assertJsonPath('verification_required', false)
            ->assertJsonPath('match_basis', 'contact_match');

        $cwid = (string) $response->json('central_wallet_id');
        $this->assertNotSame('', $cwid);

        $this->assertSame(1, CentralCustomer::query()->where('central_wallet_id', $cwid)->count());
    }

    public function test_contact_mobile_match_provisions_cwid_for_refund_destination(): void
    {
        $response = $this->refundDestination(self::RDNET, '703', '', '9123456789')
            ->assertOk()
            ->assertJsonPath('refund_destination_authorized', true)
            ->assertJsonPath('match_basis', 'contact_match');

        $cwid = (string) $response->json('central_wallet_id');
        $this->assertNotSame('', $cwid);
    }

    public function test_spoke_attested_account_identity_establishes_cwid_and_link(): void
    {
        $response = $this->refundDestination(self::RDNET, '704', 'newcustomer@example.com', '9000000001')
            ->assertOk()
            ->assertJsonPath('refund_destination_authorized', true)
            ->assertJsonPath('match_basis', 'spoke_account_identity')
            ->assertJsonPath('identity_state', 'established');

        $cwid = (string) $response->json('central_wallet_id');
        $this->assertNotSame('', $cwid);

        $link = CentralWalletAccountLink::query()
            ->where('site_code', self::RDNET)
            ->where('local_user_id', '704')
            ->where('status', AccountLinkStatus::Active)
            ->first();

        $this->assertNotNull($link);
        $this->assertSame($cwid, (string) $link->central_wallet_id);
        $this->assertSame('canonical_account_identity', $link->verification_method);
    }

    public function test_existing_account_link_backfills_verified_email_for_cohort_linked_customer(): void
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

        \DB::table('central_customer_identity_credentials')->insert([
            'desk_customer_id' => $customerId,
            'credential_type' => 'migration_cohort_anchor',
            'provider' => 'owner_migration_cohort',
            'subject_hash' => hash('sha256', 'migration-anchor-not-an-email'),
            'verified_at' => now(),
            'metadata' => json_encode(['source' => 'type1_migration_cohort_identity']),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::RDNET,
            'local_user_id' => '7091',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'owner_migration_cohort',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->assertFalse(CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', $customerId)
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
            ->exists());

        $this->refundDestination(self::RDNET, '7091', 'cohort-linked@example.com')
            ->assertOk()
            ->assertJsonPath('central_wallet_id', $cwid)
            ->assertJsonPath('match_basis', 'account_link');

        $this->assertTrue(CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', $customerId)
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
            ->where('provider', 'desk_email')
            ->whereNotNull('verified_at')
            ->exists());
    }

    public function test_existing_account_link_does_not_backfill_email_owned_by_another_customer(): void
    {
        [, $otherCwid] = $this->seedCustomerWithVerifiedEmail('taken@example.com');

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

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => $customerId,
            'site_code' => self::RDNET,
            'local_user_id' => '7092',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'owner_migration_cohort',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $this->refundDestination(self::RDNET, '7092', 'taken@example.com')
            ->assertOk()
            ->assertJsonPath('central_wallet_id', $cwid);

        $this->assertFalse(CentralCustomerIdentityCredential::query()
            ->where('desk_customer_id', $customerId)
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
            ->exists());

        $otherCustomerId = CentralCustomerIdentityCredential::query()
            ->where('credential_type', CustomerIdentityCredentialType::VerifiedEmail)
            ->where('provider', 'desk_email')
            ->where('subject_hash', hash('sha256', 'verified_email:'.strtolower(trim('taken@example.com'))))
            ->value('desk_customer_id');

        $this->assertSame($otherCwid, (string) CentralCustomer::query()->whereKey($otherCustomerId)->value('central_wallet_id'));
    }

    public function test_existing_account_link_returns_cwid_without_duplicate(): void
    {
        [, $cwid] = $this->seedCustomerWithVerifiedEmail('linked@example.com');

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => CentralCustomer::query()->where('central_wallet_id', $cwid)->value('id'),
            'site_code' => self::RDNET,
            'local_user_id' => '708',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'verified_email',
            'created_by' => 'test',
            'linked_at' => now(),
        ]);

        $beforeCustomers = CentralCustomer::query()->count();

        $this->refundDestination(self::RDNET, '708', 'linked@example.com')
            ->assertOk()
            ->assertJsonPath('central_wallet_id', $cwid)
            ->assertJsonPath('match_basis', 'account_link')
            ->assertJsonPath('identity_state', 'resolved');

        $this->assertSame($beforeCustomers, CentralCustomer::query()->count());
    }

    public function test_repeat_ensure_does_not_duplicate_cwid(): void
    {
        $this->refundDestination(self::RDNET, '709', 'repeat@example.com')
            ->assertOk()
            ->assertJsonPath('match_basis', 'spoke_account_identity');

        $firstCwid = (string) CentralWalletAccountLink::query()
            ->where('site_code', self::RDNET)
            ->where('local_user_id', '709')
            ->value('central_wallet_id');

        $this->refundDestination(self::RDNET, '709', 'repeat@example.com')
            ->assertOk()
            ->assertJsonPath('central_wallet_id', $firstCwid)
            ->assertJsonPath('match_basis', 'account_link');

        $this->assertSame(1, CentralCustomer::query()->where('central_wallet_id', $firstCwid)->count());
        $this->assertSame(1, CentralWalletAccountLink::query()->where('local_user_id', '709')->count());
    }

    public function test_insufficient_contact_data_returns_validation_error(): void
    {
        $this->withHeaders($this->authHeaders(self::RDNET))
            ->getJson('/api/central-wallet/v1/wallet-refund-destination?'.http_build_query([
                'site_code' => self::RDNET,
                'local_user_id' => '710',
            ]))
            ->assertStatus(422)
            ->assertJsonPath('error', 'contact_data_required');
    }

    public function test_ambiguous_contact_match_returns_conflict(): void
    {
        $this->refundDestination(self::RDNET, '705', 'ambig@example.com')
            ->assertStatus(409)
            ->assertJsonPath('error', 'identity_ambiguous');
    }

    public function test_email_mobile_conflict_returns_conflict(): void
    {
        $this->refundDestination(self::RDNET, '706', 'unique@example.com', '9123456789')
            ->assertStatus(409)
            ->assertJsonPath('error', 'identity_ambiguous');
    }

    public function test_endpoint_disabled_returns_service_unavailable(): void
    {
        config(['central_wallet.customer_identity_ensure.enabled' => false]);

        $this->refundDestination(self::RDNET, '707', 'unique@example.com')
            ->assertStatus(503)
            ->assertJsonPath('error', 'customer_identity_ensure_disabled');
    }

    private function refundDestination(
        string $site,
        string $localUserId,
        string $email,
        ?string $mobile = null,
    ): TestResponse {
        $query = [
            'site_code' => $site,
            'local_user_id' => $localUserId,
        ];

        if ($email !== '') {
            $query['email'] = $email;
        }

        if ($mobile !== null && $mobile !== '') {
            $query['mobile'] = $mobile;
        }

        return $this->withHeaders($this->authHeaders($site))
            ->getJson('/api/central-wallet/v1/wallet-refund-destination?'.http_build_query($query));
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
