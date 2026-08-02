<?php

namespace App\Services\Platform\Warmers;

use App\Contracts\Platform\PlatformSnapshotWarmer;

class PlatformSnapshotWarmerRegistry
{
    /** @var array<string, PlatformSnapshotWarmer> */
    private array $warmers = [];

    public function register(PlatformSnapshotWarmer $warmer): void
    {
        $this->warmers[$warmer->key()] = $warmer;
    }

    /**
     * @return list<PlatformSnapshotWarmer>
     */
    public function all(): array
    {
        $warmers = array_values($this->warmers);

        usort(
            $warmers,
            static fn (PlatformSnapshotWarmer $a, PlatformSnapshotWarmer $b): int => $a->priority() <=> $b->priority(),
        );

        return $warmers;
    }
}
