<?php

namespace App\Support\PreShipmentCancellation;

use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use App\Services\PreShipmentCancellation\PreShipmentCancellationFeature;

final class PreShipmentCancellationAccess
{
    private const PERM_SUBMIT = 'preship_cancellations.submit';

    private const PERM_CENTRAL = 'preship_cancellations.central';

    private const PERM_REVIEW = 'preship_cancellations.review';

    public static function featureEnabled(): bool
    {
        return app(PreShipmentCancellationFeature::class)->enabled();
    }

    public static function canSubmit(?User $user): bool
    {
        return $user !== null
            && self::featureEnabled()
            && $user->can(self::PERM_SUBMIT);
    }

    public static function canViewCentral(?User $user): bool
    {
        return $user !== null
            && self::featureEnabled()
            && (
                $user->can(self::PERM_CENTRAL)
                || $user->can(self::PERM_REVIEW)
            );
    }

    public static function canViewCase(?User $user, PreShipmentCancellationRequest $case): bool
    {
        if ($user === null || ! self::featureEnabled()) {
            return false;
        }

        return $user->can('view', $case);
    }
}
