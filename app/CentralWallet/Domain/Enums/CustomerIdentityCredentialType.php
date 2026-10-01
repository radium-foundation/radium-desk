<?php

namespace App\CentralWallet\Domain\Enums;

enum CustomerIdentityCredentialType: string
{
    case Google = 'google';
    case VerifiedEmail = 'verified_email';
    case VerifiedMobile = 'verified_mobile';
    case MigrationCohortAnchor = 'migration_cohort_anchor';
}
