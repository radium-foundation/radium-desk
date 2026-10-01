<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\CustomerFoundationFromCeremonyService;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletCeremonyIdentity;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class CustomerFoundationFromCeremonyTest extends TestCase
{
    use RefreshDatabase;

    public function test_establishes_customer_from_ceremony_without_creating_wallet_or_ledger_entries(): void
    {
        $cwid = (string) Str::uuid();
        CentralWallet::query()->forceCreate(['id' => $cwid, 'status' => 'active']);

        CentralWalletCeremonyIdentity::query()->create([
            'id' => (string) Str::uuid(),
            'site_code' => 'radiumbox.com',
            'local_user_id' => '3',
            'central_wallet_id' => $cwid,
            'verified_phone_e164_hash' => hash('sha256', 'verified_mobile:+919999999999'),
            'first_verified_at' => now(),
        ]);

        CentralWalletAccountLink::query()->create([
            'central_wallet_id' => $cwid,
            'site_code' => 'radiumbox.com',
            'local_user_id' => '3',
            'status' => AccountLinkStatus::Active,
            'verification_method' => 'm2_dual_otp',
            'created_by' => 'ceremony:test',
            'linked_at' => now(),
        ]);

        $service = app(CustomerFoundationFromCeremonyService::class);
        $first = $service->establishFromCeremony('radiumbox.com', '3', 'test');
        $second = $service->establishFromCeremony('radiumbox.com', '3', 'test');

        $this->assertSame('created_customer', $first['status']);
        $this->assertTrue($second['idempotent_replay']);
        $this->assertSame(1, CentralCustomer::query()->count());
        $this->assertSame(1, CentralWallet::query()->count());
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
        $this->assertDatabaseHas('central_wallet_account_links', [
            'local_user_id' => '3',
            'desk_customer_id' => $first['desk_customer_id'],
        ]);
    }

    public function test_rejects_when_ceremony_missing(): void
    {
        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('ceremony_identity_not_found');
        app(CustomerFoundationFromCeremonyService::class)->establishFromCeremony('radiumbox.com', '99', 'test');
    }
}
