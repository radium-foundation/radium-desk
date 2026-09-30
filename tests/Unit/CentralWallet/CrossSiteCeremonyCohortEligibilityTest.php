<?php

namespace Tests\Unit\CentralWallet;

use App\CentralWallet\Application\CrossSiteCeremonyCohortEligibility;
use App\CentralWallet\Domain\Enums\CrossSiteCeremonyCohortRejectionReason;
use Tests\TestCase;

class CrossSiteCeremonyCohortEligibilityTest extends TestCase
{
    public function test_cohort_disabled_is_fail_closed(): void
    {
        config([
            'central_wallet.ceremony.cross_site_cohort.enabled' => false,
            'central_wallet.ceremony.cross_site_cohort.allowed_local_user_ids' => [3],
        ]);

        $eligibility = new CrossSiteCeremonyCohortEligibility;

        $this->assertSame(
            CrossSiteCeremonyCohortRejectionReason::COHORT_DISABLED,
            $eligibility->rejectionReason('3'),
        );
    }

    public function test_empty_allowlist_is_fail_closed(): void
    {
        config([
            'central_wallet.ceremony.cross_site_cohort.enabled' => true,
            'central_wallet.ceremony.cross_site_cohort.allowed_local_user_ids' => [],
        ]);

        $eligibility = new CrossSiteCeremonyCohortEligibility;

        $this->assertSame(
            CrossSiteCeremonyCohortRejectionReason::COHORT_EMPTY,
            $eligibility->rejectionReason('3'),
        );
    }

    public function test_wrong_user_is_rejected(): void
    {
        config([
            'central_wallet.ceremony.cross_site_cohort.enabled' => true,
            'central_wallet.ceremony.cross_site_cohort.allowed_local_user_ids' => [3],
        ]);

        $eligibility = new CrossSiteCeremonyCohortEligibility;

        $this->assertSame(
            CrossSiteCeremonyCohortRejectionReason::USER_NOT_IN_COHORT,
            $eligibility->rejectionReason('99'),
        );
    }

    public function test_user_three_is_allowed_when_cohort_active(): void
    {
        config([
            'central_wallet.ceremony.cross_site_cohort.enabled' => true,
            'central_wallet.ceremony.cross_site_cohort.allowed_local_user_ids' => [3],
        ]);

        $eligibility = new CrossSiteCeremonyCohortEligibility;

        $this->assertNull($eligibility->rejectionReason('3'));
    }
}
