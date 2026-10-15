<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Domain\Enums\LedgerEntryStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerLedgerHistoryDisplayEmailMatchApiTest extends TestCase
{
    use RefreshDatabase;

    private const TOKEN = 'cw-display-history-token';

    private const SITE = 'rdservice.in';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'central_wallet.enabled' => true,
            'central_wallet.api_enabled' => true,
            'central_wallet.integration_token' => self::TOKEN,
            'central_wallet.historical_wallet_visibility.enabled' => true,
            'central_wallet.ledger_read.customer_history.enabled' => true,
            'central_wallet.ledger_read.customer_history.display_email_match.enabled' => true,
            'central_wallet.ledger_read.customer_history.authorized_callers' => [
                'rdservice.in',
            ],
            'central_wallet.ledger_read.customer_history.authorized_source_systems' => [
                'rdservice.in',
            ],
        ]);
    }

    public function test_unique_email_match_allows_read_only_history_without_trusted_link(): void
    {
        $email = 'display-history-'.uniqid('', true).'@example.test';
        [$customerId, $cwid] = $this->seedCustomerWithVerifiedEmail($email);

        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $cwid,
            'entry_type' => 'credit',
            'amount' => '499.00',
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted,
            'source_system' => self::SITE,
            'source_reference' => 'users_wallet:1',
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => 'REF-DISPLAY-TEST',
            'posted_at' => CarbonImmutable::now(),
        ]);

        $this->authenticatedAs(self::SITE)
            ->getJson('/api/central-wallet/v1/wallets/'.$cwid.'/ledger-history?'.http_build_query([
                'local_user_id' => '556775',
                'email' => $email,
            ]))
            ->assertOk()
            ->assertJsonPath('data.0.business_reference', 'REF-DISPLAY-TEST');

        $this->assertDatabaseCount('central_wallet_account_links', 0);
    }

    public function test_wrong_cwid_with_valid_email_is_rejected(): void
    {
        $email = 'mismatch-'.uniqid('', true).'@example.test';
        [, $cwid] = $this->seedCustomerWithVerifiedEmail($email);
        $otherCwid = (string) Str::uuid();
        \DB::table('central_wallets')->insert([
            'id' => $otherCwid,
            'status' => 'active',
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->authenticatedAs(self::SITE)
            ->getJson('/api/central-wallet/v1/wallets/'.$otherCwid.'/ledger-history?'.http_build_query([
                'local_user_id' => '556775',
                'email' => $email,
            ]))
            ->assertForbidden()
            ->assertJsonPath('error', 'cwid_identity_mismatch');
    }

    public function test_history_without_email_fails_closed_when_no_trusted_link(): void
    {
        $email = 'no-email-'.uniqid('', true).'@example.test';
        [, $cwid] = $this->seedCustomerWithVerifiedEmail($email);

        $this->authenticatedAs(self::SITE)
            ->getJson('/api/central-wallet/v1/wallets/'.$cwid.'/ledger-history?local_user_id=556775')
            ->assertForbidden()
            ->assertJsonPath('error', 'display_email_required');
    }

    public function test_mobile_only_query_does_not_authorize_display_history(): void
    {
        $email = 'mobile-only-'.uniqid('', true).'@example.test';
        [, $cwid] = $this->seedCustomerWithVerifiedEmail($email);

        $this->authenticatedAs(self::SITE)
            ->getJson('/api/central-wallet/v1/wallets/'.$cwid.'/ledger-history?'.http_build_query([
                'local_user_id' => '556775',
                'mobile' => '9876543210',
            ]))
            ->assertForbidden()
            ->assertJsonPath('error', 'display_email_required');
    }

    public function test_wallet_visibility_includes_desk_asserted_central_wallet_id(): void
    {
        $email = 'visibility-cwid-'.uniqid('', true).'@example.test';
        [, $cwid] = $this->seedCustomerWithVerifiedEmail($email);

        \DB::table('central_wallet_ledger_entries')->insert([
            'central_wallet_id' => $cwid,
            'entry_type' => 'credit',
            'amount' => '10.00',
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted->value,
            'source_system' => self::SITE,
            'source_reference' => 'seed',
            'correlation_id' => (string) Str::uuid(),
            'posted_at' => now(),
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->authenticatedAs(self::SITE)
            ->getJson('/api/central-wallet/v1/wallet-visibility?'.http_build_query([
                'site_code' => self::SITE,
                'local_user_id' => '556775',
                'email' => $email,
                'email_verified' => '0',
            ]))
            ->assertOk()
            ->assertJsonPath('central_wallet_id', $cwid)
            ->assertJsonPath('spendable', false);
    }

    public function test_trusted_link_path_still_works_without_email_query(): void
    {
        $cwid = $this->createWallet();
        $this->createTrustedLink($cwid, '3');

        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $cwid,
            'entry_type' => 'credit',
            'amount' => '1.00',
            'currency' => 'INR',
            'status' => LedgerEntryStatus::Posted,
            'source_system' => self::SITE,
            'source_reference' => 'trusted',
            'correlation_id' => (string) Str::uuid(),
            'business_reference' => 'REF-TRUSTED',
            'posted_at' => CarbonImmutable::now(),
        ]);

        $this->authenticatedAs(self::SITE)
            ->getJson('/api/central-wallet/v1/wallets/'.$cwid.'/ledger-history?local_user_id=3')
            ->assertOk()
            ->assertJsonPath('data.0.business_reference', 'REF-TRUSTED');
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

    private function createWallet(): string
    {
        $response = $this->authenticatedAs(self::SITE)->postJson('/api/central-wallet/v1/wallets', [
            'idempotency_key' => 'wallet-'.uniqid('', true),
        ]);

        $response->assertCreated();

        return (string) $response->json('central_wallet_id');
    }

    private function createTrustedLink(string $cwid, string $localUserId): void
    {
        $response = $this->authenticatedAs(self::SITE)->postJson('/api/central-wallet/v1/account-links', [
            'idempotency_key' => 'link-'.uniqid('', true),
            'central_wallet_id' => $cwid,
            'site_code' => self::SITE,
            'local_user_id' => $localUserId,
            'created_by' => 'service:test',
        ])->assertCreated();

        $linkId = (int) $response->json('link_id');

        \DB::table('central_wallet_account_links')->where('id', $linkId)->update([
            'status' => 'active',
            'linked_at' => now(),
            'verification_method' => 'verified_email',
        ]);
    }

    private function authenticatedAs(string $siteCode): self
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.self::TOKEN,
            'X-Site-Code' => $siteCode,
            'Accept' => 'application/json',
        ]);
    }
}
