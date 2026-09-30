<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\CrossSiteCeremonyCohortRejectionReason;
use App\CentralWallet\Support\CrossSiteCeremonyCohortConfiguration;

final class CrossSiteCeremonyCohortEligibility
{
    public function rejectionReason(string $localUserId): ?string
    {
        $configuration = CrossSiteCeremonyCohortConfiguration::fromConfig();

        if (! $configuration->isEnabled()) {
            return CrossSiteCeremonyCohortRejectionReason::COHORT_DISABLED;
        }

        if ($configuration->allowedLocalUserIds() === []) {
            return CrossSiteCeremonyCohortRejectionReason::COHORT_EMPTY;
        }

        $userId = (int) $localUserId;
        if ($userId <= 0 || ! $configuration->includesUser($userId)) {
            return CrossSiteCeremonyCohortRejectionReason::USER_NOT_IN_COHORT;
        }

        return null;
    }
}
