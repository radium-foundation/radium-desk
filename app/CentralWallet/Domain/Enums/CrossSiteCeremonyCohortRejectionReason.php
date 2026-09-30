<?php

namespace App\CentralWallet\Domain\Enums;

final class CrossSiteCeremonyCohortRejectionReason
{
    public const COHORT_DISABLED = 'cross_site_cohort_disabled';

    public const COHORT_EMPTY = 'cross_site_cohort_empty';

    public const USER_NOT_IN_COHORT = 'cross_site_cohort_user_not_allowed';
}
