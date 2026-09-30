<?php

namespace App\CentralWallet\Application;

use App\CentralWallet\Domain\Enums\AccountLinkStatus;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletCeremonyIdentity;
use Illuminate\Support\Collection;

final class CrossSiteCeremonyResolver
{
    public function __construct(
        private readonly CrossSiteCeremonyCohortEligibility $cohortEligibility,
    ) {}

    public function resolve(
        string $siteCode,
        string $localUserId,
        string $verifiedPhoneE164Hash,
        ?string $crossSiteLinkAuthorizationRef,
    ): CrossSiteResolution {
        if (! $this->enabled()) {
            return CrossSiteResolution::noMatch();
        }

        if ($verifiedPhoneE164Hash === '') {
            return CrossSiteResolution::noMatch();
        }

        $cwids = $this->activeCrossSiteCwids($siteCode, $verifiedPhoneE164Hash);

        if ($cwids->isEmpty()) {
            return CrossSiteResolution::noMatch();
        }

        $cohortRejection = $this->cohortEligibility->rejectionReason($localUserId);
        if ($cohortRejection !== null) {
            return CrossSiteResolution::cohortDenied($cohortRejection);
        }

        if ($cwids->count() > 1) {
            return CrossSiteResolution::ambiguous();
        }

        if (trim((string) $crossSiteLinkAuthorizationRef) === '') {
            return CrossSiteResolution::authorizationRequired();
        }

        return CrossSiteResolution::resolved((string) $cwids->first());
    }

    private function enabled(): bool
    {
        return (bool) config('central_wallet.ceremony.cross_site_enabled', false);
    }

    /**
     * @return Collection<int, string>
     */
    private function activeCrossSiteCwids(string $siteCode, string $verifiedPhoneE164Hash): Collection
    {
        return CentralWalletCeremonyIdentity::query()
            ->from('central_wallet_ceremony_identities as ci')
            ->join('central_wallet_account_links as al', function ($join): void {
                $join->on('al.site_code', '=', 'ci.site_code')
                    ->on('al.local_user_id', '=', 'ci.local_user_id')
                    ->on('al.central_wallet_id', '=', 'ci.central_wallet_id')
                    ->where('al.status', AccountLinkStatus::Active->value);
            })
            ->where('ci.verified_phone_e164_hash', $verifiedPhoneE164Hash)
            ->where('ci.site_code', '!=', $siteCode)
            ->distinct()
            ->pluck('ci.central_wallet_id');
    }
}

final class CrossSiteResolution
{
    public const STATUS_NO_MATCH = 'no_match';

    public const STATUS_RESOLVED = 'resolved';

    public const STATUS_AMBIGUOUS = 'ambiguous';

    public const STATUS_AUTHORIZATION_REQUIRED = 'authorization_required';

    public const STATUS_COHORT_DENIED = 'cohort_denied';

    private function __construct(
        public readonly string $status,
        public readonly ?string $centralWalletId = null,
        public readonly ?string $cohortRejectionReason = null,
    ) {}

    public static function noMatch(): self
    {
        return new self(self::STATUS_NO_MATCH);
    }

    public static function resolved(string $centralWalletId): self
    {
        return new self(self::STATUS_RESOLVED, $centralWalletId);
    }

    public static function ambiguous(): self
    {
        return new self(self::STATUS_AMBIGUOUS);
    }

    public static function authorizationRequired(): self
    {
        return new self(self::STATUS_AUTHORIZATION_REQUIRED);
    }

    public static function cohortDenied(string $reason): self
    {
        return new self(self::STATUS_COHORT_DENIED, null, $reason);
    }
}
