<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Application\AccountLinkService;
use App\CentralWallet\Application\CentralWalletService;
use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use Illuminate\Foundation\Testing\RefreshDatabase;
use RuntimeException;
use Tests\TestCase;

class AccountLinkServiceTest extends TestCase
{
    use RefreshDatabase;

    public function test_prevents_duplicate_active_links_for_same_site_user(): void
    {
        $wallet = app(CentralWalletService::class)->create();
        $links = app(AccountLinkService::class);

        $first = $links->createPendingLink(
            centralWalletId: $wallet->id,
            siteCode: 'rdservice.in',
            localUserId: '101',
            createdBy: 'test',
        );

        $links->confirmLink($first, 'm2_dual_otp', 'customer:101');

        $this->expectException(RuntimeException::class);

        $links->createPendingLink(
            centralWalletId: $wallet->id,
            siteCode: 'rdservice.in',
            localUserId: '101',
            createdBy: 'test',
        );
    }

    public function test_revoked_link_allows_new_pending_link(): void
    {
        $wallet = app(CentralWalletService::class)->create();
        $links = app(AccountLinkService::class);

        $pending = $links->createPendingLink(
            centralWalletId: $wallet->id,
            siteCode: 'rdservice.in',
            localUserId: '202',
            createdBy: 'test',
        );

        $active = $links->confirmLink($pending, 'm4_expedited', 'customer:202');
        $links->revokeLink($active, 'operator:1');

        $replacement = $links->createPendingLink(
            centralWalletId: $wallet->id,
            siteCode: 'rdservice.in',
            localUserId: '202',
            createdBy: 'test',
        );

        $this->assertSame(AccountLinkStatus::PendingVerification, $replacement->status);
    }
}
