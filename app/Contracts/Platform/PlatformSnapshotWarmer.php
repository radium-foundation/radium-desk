<?php

namespace App\Contracts\Platform;

use App\Models\User;

/**
 * Background warmers update zone/overview caches for Priority-1 surfaces.
 */
interface PlatformSnapshotWarmer
{
    public function key(): string;

    public function label(): string;

    /**
     * Lower runs first (Priority 1 = 10, 20, …).
     */
    public function priority(): int;

    public function warm(?User $actor = null): void;
}
