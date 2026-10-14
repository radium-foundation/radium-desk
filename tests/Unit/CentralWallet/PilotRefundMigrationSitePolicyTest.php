<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Application\PilotRefundMigrationSitePolicy;
use InvalidArgumentException;
use Tests\TestCase;

class PilotRefundMigrationSitePolicyTest extends TestCase
{
    public function test_allows_rdservice_in_and_radiumbox_com_only(): void
    {
        $this->assertSame(
            ['rdservice.in', 'radiumbox.com'],
            PilotRefundMigrationSitePolicy::allowedSites(),
        );

        PilotRefundMigrationSitePolicy::assertPreparedRowSite('rdservice.in');
        PilotRefundMigrationSitePolicy::assertPreparedRowSite('radiumbox.com');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pilot_refund_migration_site_not_allowed');
        PilotRefundMigrationSitePolicy::assertPreparedRowSite('rdservice.net');
    }

    public function test_site_must_match_order_owner_prefix(): void
    {
        PilotRefundMigrationSitePolicy::assertPreparedRowSiteMatchesOrder('rdservice.in', 'RD17641');
        PilotRefundMigrationSitePolicy::assertPreparedRowSiteMatchesOrder('radiumbox.com', 'RBP858');

        $this->expectException(InvalidArgumentException::class);
        $this->expectExceptionMessage('pilot_refund_migration_site_order_mismatch');
        PilotRefundMigrationSitePolicy::assertPreparedRowSiteMatchesOrder('rdservice.in', 'RBP858');
    }
}
