<?php

namespace Tests\Feature\CentralWallet;

use App\CentralWallet\Application\MigrationControlledCwidProvisionService;
use App\CentralWallet\Domain\Enums\CustomerIdentityCredentialType;
use App\CentralWallet\Infrastructure\Persistence\CentralCustomer;
use App\CentralWallet\Infrastructure\Persistence\CentralWallet;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletAccountLink;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MigrationControlledCwidProvisionTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisions_customer_from_verified_email_without_ledger_writes(): void
    {
        $service = app(MigrationControlledCwidProvisionService::class);

        $result = $service->provision(
            siteCode: 'rdservice.in',
            localUserId: '900001',
            identity: [
                'type' => CustomerIdentityCredentialType::VerifiedEmail->value,
                'email' => 'migration-provision@example.com',
            ],
            evidence: ['refund_ids' => ['42']],
            actorId: 'test',
        );

        $this->assertSame('created_customer', $result['status']);
        $this->assertFalse($result['idempotent_replay']);
        $this->assertSame(1, CentralCustomer::query()->count());
        $this->assertSame(1, CentralWallet::query()->count());
        $this->assertSame(0, CentralWalletLedgerEntry::query()->count());
        $this->assertDatabaseHas('central_wallet_account_links', [
            'site_code' => 'rdservice.in',
            'local_user_id' => '900001',
            'desk_customer_id' => $result['desk_customer_id'],
            'central_wallet_id' => $result['central_wallet_id'],
        ]);
    }

    public function test_idempotent_replay_when_active_link_exists(): void
    {
        $service = app(MigrationControlledCwidProvisionService::class);

        $first = $service->provision(
            siteCode: 'radiumbox.com',
            localUserId: '900002',
            identity: [
                'type' => CustomerIdentityCredentialType::Google->value,
                'google_subject' => 'google-subject-900002',
            ],
            evidence: ['refund_ids' => ['99']],
            actorId: 'test',
        );

        $second = $service->provision(
            siteCode: 'radiumbox.com',
            localUserId: '900002',
            identity: [
                'type' => CustomerIdentityCredentialType::Google->value,
                'google_subject' => 'google-subject-900002',
            ],
            evidence: ['refund_ids' => ['99']],
            actorId: 'test',
        );

        $this->assertSame('created_customer', $first['status']);
        $this->assertSame('existing_link', $second['status']);
        $this->assertTrue($second['idempotent_replay']);
        $this->assertSame(1, CentralWalletAccountLink::query()->where('status', 'active')->count());
    }
}
