<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomerIdentityCredential;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CentralWalletStage1PopulationExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_export_includes_post_cutoff_wallets_with_hashed_credentials_only(): void
    {
        $cutoffUtc = CarbonImmutable::parse('2026-07-15 00:00:00', 'Asia/Kolkata')->utc();

        $beforeWalletId = (string) Str::uuid();
        CentralWallet::query()->forceCreate([
            'id' => $beforeWalletId,
            'status' => 'active',
            'created_at' => $cutoffUtc->subDays(30),
            'updated_at' => $cutoffUtc->subDays(30),
        ]);

        $walletId = (string) Str::uuid();
        $deskCustomerId = (string) Str::uuid();
        $createdAt = $cutoffUtc->addHour();

        CentralWallet::query()->forceCreate([
            'id' => $walletId,
            'status' => 'active',
            'created_at' => $createdAt,
            'updated_at' => $createdAt,
        ]);

        CentralCustomer::query()->create([
            'id' => $deskCustomerId,
            'central_wallet_id' => $walletId,
            'status' => 'active',
        ]);

        CentralCustomerIdentityCredential::query()->create([
            'desk_customer_id' => $deskCustomerId,
            'credential_type' => 'google',
            'provider' => 'google',
            'subject_hash' => hash('sha256', 'google:subject-abc'),
            'verified_at' => now(),
        ]);

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $walletId,
            'desk_customer_id' => $deskCustomerId,
            'site_code' => 'radiumbox.com',
            'local_user_id' => '3',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'trusted_google',
            'linked_at' => now(),
            'created_by' => 'test',
        ]);

        CentralWalletLedgerEntry::query()->create([
            'central_wallet_id' => $walletId,
            'entry_type' => LedgerEntryType::Credit,
            'amount' => '150.00',
            'currency' => 'INR',
            'status' => 'posted',
            'source_system' => 'radium-desk',
            'correlation_id' => (string) Str::uuid(),
            'posted_at' => now(),
        ]);

        $this->artisan('central-wallet:stage1-population-export', [
            '--output' => storage_path('app/stage1-export-test.json'),
        ])->assertSuccessful();

        $export = json_decode(
            file_get_contents(storage_path('app/stage1-export-test.json')),
            true,
            512,
            JSON_THROW_ON_ERROR,
        );

        $exportedIds = array_column($export['wallets'], 'central_wallet_id');
        $this->assertContains($walletId, $exportedIds);
        $this->assertNotContains($beforeWalletId, $exportedIds);

        $wallet = collect($export['wallets'])->firstWhere('central_wallet_id', $walletId);
        $this->assertNotNull($wallet);
        $this->assertSame('150.00', $wallet['spendable_balance']);
        $this->assertSame($deskCustomerId, $wallet['desk_customer_id']);
        $this->assertSame(hash('sha256', 'google:subject-abc'), $wallet['credentials'][0]['subject_hash']);
        $this->assertArrayNotHasKey('google_subject', $wallet['credentials'][0]);
        $this->assertSame('3', $wallet['account_links'][0]['local_user_id']);
    }
}
