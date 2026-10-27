<?php

namespace App\Services\Release;

use InvalidArgumentException;

class RipCheckpointStatusPolicy
{
    public const DEPLOYED = 'deployed';

    public const SMOKE_VERIFIED = 'smoke-verified';

    public const KNOWN_GOOD = 'known-good';

    /**
     * @return list<string>
     */
    public function allowedStatuses(): array
    {
        return [
            self::DEPLOYED,
            self::SMOKE_VERIFIED,
            self::KNOWN_GOOD,
        ];
    }

    public function assertStatus(string $status): void
    {
        if (! in_array($status, $this->allowedStatuses(), true)) {
            throw new InvalidArgumentException("Invalid RIP status: {$status}");
        }
    }

    /**
     * @param  array<string, mixed>  $manifest
     */
    public function assertStatusTransitionRequirements(string $status, array $manifest): void
    {
        $this->assertStatus($status);

        if ($status === self::SMOKE_VERIFIED) {
            $smoke = $manifest['smoke_evidence'] ?? null;

            if (! is_array($smoke) || ($smoke['verified'] ?? false) !== true) {
                throw new InvalidArgumentException('smoke-verified requires smoke_evidence.verified = true.');
            }
        }

        if ($status === self::KNOWN_GOOD) {
            $this->assertStatusTransitionRequirements(self::SMOKE_VERIFIED, $manifest);

            $signoff = $manifest['known_good_signoff'] ?? null;

            if (! is_array($signoff)
                || ($signoff['owner_approved'] ?? false) !== true
                || ! is_string($signoff['approved_at'] ?? null)
                || trim((string) $signoff['approved_at']) === '') {
                throw new InvalidArgumentException('known-good requires owner sign-off with approved_at.');
            }

            $journeys = $signoff['protected_customer_journeys'] ?? null;

            if (! is_array($journeys) || $journeys === []) {
                throw new InvalidArgumentException('known-good requires protected_customer_journeys evidence.');
            }
        }
    }
}
