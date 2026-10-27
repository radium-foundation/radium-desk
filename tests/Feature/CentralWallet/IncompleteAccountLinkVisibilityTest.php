<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Testing\TestResponse;
use Tests\TestCase;

class IncompleteAccountLinkVisibilityTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-incomplete-link-token';

    private const SITE = 'rdservice.net';

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

    public function test_rn158_shaped_null_desk_customer_id_link_resolves_visibility_when_credential_metadata_matches(): void
    {
        [$customerId, $cwid] = $this->seedCustomerWithSpokeCredentialMetadata(
            localUserId: '546395',
            siteCode: self::SITE,
            email: 'monalisacomputers@gmail.com',
        );

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => null,
            'site_code' => self::SITE,
            'local_user_id' => '546395',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'canonical_account_identity',
            'created_by' => 'customer_identity_ensure:'.self::SITE,
            'linked_at' => now(),
            'metadata' => ['source' => 'customer_identity_ensure'],
        ]);

        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $cwid,
            'entry_type' => LedgerEntryType::Credit,
            'amount' => '599.00',
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted,
            'source_system' => self::SITE,
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => 'REF-67366',
            'posted_at' => now(),
        ]);

        $this->walletVisibility(self::SITE, '546395', 'monalisacomputers@gmail.com', '8779663800')
            ->assertOk()
            ->assertJsonPath('wallet_balance', '599.00')
            ->assertJsonPath('balance_status', 'unverified')
            ->assertJsonPath('central_wallet_id', $cwid);

        $this->assertSame($customerId, (string) CentralCustomer::query()->where('central_wallet_id', $cwid)->value('id'));
    }

    public function test_null_desk_customer_id_link_still_denies_cross_customer_email(): void
    {
        [$customerId, $cwid] = $this->seedCustomerWithSpokeCredentialMetadata(
            localUserId: '546395',
            siteCode: self::SITE,
            email: 'owner@example.com',
        );

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => null,
            'site_code' => self::SITE,
            'local_user_id' => '546395',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'canonical_account_identity',
            'created_by' => 'customer_identity_ensure:'.self::SITE,
            'linked_at' => now(),
        ]);

        $this->walletVisibility(self::SITE, '546395', 'other@example.com')
            ->assertNotFound()
            ->assertJsonPath('error', 'not_found');
    }

    public function test_refund_destination_and_visibility_align_for_incomplete_link(): void
    {
        [, $cwid] = $this->seedCustomerWithSpokeCredentialMetadata('9001', self::SITE, 'aligned@example.com');

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'desk_customer_id' => null,
            'site_code' => self::SITE,
            'local_user_id' => '9001',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'canonical_account_identity',
            'created_by' => 'customer_identity_ensure:'.self::SITE,
            'linked_at' => now(),
        ]);

        $this->withHeaders($this->authHeaders(self::SITE))
            ->getJson('/api/central-wallet/v1/wallet-refund-destination?'.http_build_query([
                'site_code' => self::SITE,
                'local_user_id' => '9001',
                'email' => 'aligned@example.com',
            ]))
            ->assertOk()
            ->assertJsonPath('central_wallet_id', $cwid);

        $this->walletVisibility(self::SITE, '9001', 'aligned@example.com')
            ->assertOk()
            ->assertJsonPath('central_wallet_id', $cwid);
    }

    public function test_create_confirmed_link_sets_desk_customer_id(): void
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

        $link = app(\App\CentralWallet\Application\AccountLinkService::class)->createConfirmedLink(
            centralWalletId: $cwid,
            siteCode: self::SITE,
            localUserId: '42',
            createdBy: 'test',
            verificationMethod: 'verified_email',
            actorId: 'customer:42',
        );

        $this->assertSame($customerId, (string) $link->desk_customer_id);
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function seedCustomerWithSpokeCredentialMetadata(
        string $localUserId,
        string $siteCode,
        string $email,
    ): array {
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

        $hasher = app(\App\CentralWallet\Application\CustomerIdentitySubjectHasher::class);

        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $customerId,
            'credential_type' => CustomerIdentityCredentialType::VerifiedEmail,
            'provider' => 'desk_email',
            'subject_hash' => $hasher->hashVerifiedEmail($email),
            'verified_at' => now(),
            'metadata' => [
                'source' => 'spoke_account_identity',
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
            ],
        ]);

        return [$customerId, $cwid];
    }

    private function walletVisibility(
        string $site,
        string $localUserId,
        string $email,
        ?string $mobile = null,
    ): TestResponse {
        $query = [
            'site_code' => $site,
            'local_user_id' => $localUserId,
            'email' => $email,
            'email_verified' => '0',
        ];

        if ($mobile !== null) {
            $query['mobile'] = $mobile;
        }

        return $this->withHeaders($this->authHeaders($site))
            ->getJson('/api/central-wallet/v1/wallet-visibility?'.http_build_query($query));
    }

    /**
     * @return array<string, string>
     */
    private function authHeaders(string $siteCode): array
    {
        return [
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $siteCode,
        ];
    }
}
