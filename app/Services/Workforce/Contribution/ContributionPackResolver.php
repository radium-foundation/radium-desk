<?php

namespace App\Services\Workforce\Contribution;

use App\Data\Workforce\Contribution\ContributionPack;
use App\Models\User;
use InvalidArgumentException;

class ContributionPackResolver
{
    public function resolveForUser(User $user): ContributionPack
    {
        $user->loadMissing('roles');

        $roleMap = config('workforce_contribution.role_pack_map', []);
        $packId = (string) config('workforce_contribution.default_pack', 'support_agent');

        foreach ($roleMap as $roleSlug => $mappedPackId) {
            if ($user->hasRole((string) $roleSlug)) {
                $packId = (string) $mappedPackId;
                break;
            }
        }

        return $this->packById($packId);
    }

    public function packById(string $packId): ContributionPack
    {
        $packs = config('workforce_contribution.packs', []);

        if (! isset($packs[$packId]) || ! is_array($packs[$packId])) {
            throw new InvalidArgumentException("Unknown contribution pack [{$packId}].");
        }

        $definition = $packs[$packId];

        return new ContributionPack(
            id: $packId,
            label: (string) ($definition['label'] ?? $packId),
            strategy: (string) ($definition['strategy'] ?? 'any_of'),
            signalThresholds: is_array($definition['signals'] ?? null) ? $definition['signals'] : [],
        );
    }
}
