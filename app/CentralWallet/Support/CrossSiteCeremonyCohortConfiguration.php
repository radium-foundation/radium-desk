<?php

namespace App\CentralWallet\Support;

final class CrossSiteCeremonyCohortConfiguration
{
    /**
     * @param  list<int>  $allowedLocalUserIds
     */
    public function __construct(
        private readonly bool $enabled,
        private readonly array $allowedLocalUserIds,
    ) {}

    public static function fromConfig(): self
    {
        return new self(
            enabled: (bool) config('central_wallet.ceremony.cross_site_cohort.enabled', false),
            allowedLocalUserIds: CohortUserIdList::normalize(
                config('central_wallet.ceremony.cross_site_cohort.allowed_local_user_ids', []),
            ),
        );
    }

    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * @return list<int>
     */
    public function allowedLocalUserIds(): array
    {
        return $this->allowedLocalUserIds;
    }

    public function includesUser(int $localUserId): bool
    {
        return in_array($localUserId, $this->allowedLocalUserIds, true);
    }
}
