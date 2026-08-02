<?php

namespace App\Contracts\Platform;

use App\Data\Platform\PlatformZoneDefinition;
use App\Data\Platform\PlatformZoneExpandResult;
use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformHealthStatus;
use App\Models\User;

/**
 * Platform dashboard zone contract.
 *
 * Controllers must not know concrete zones — only this interface via the registry.
 */
interface PlatformZone
{
    public function definition(): PlatformZoneDefinition;

    public function authorize(User $viewer): bool;

    /**
     * Cache/last-known only. Never run expensive probes on first paint.
     */
    public function snapshot(User $viewer): PlatformZoneSnapshot;

    /**
     * Fresh summary for async zone refresh.
     */
    public function refresh(User $viewer): PlatformZoneSnapshot;

    /**
     * Lightweight status from snapshot/cache. Never runs expensive refresh.
     */
    public function status(User $viewer): PlatformHealthStatus;

    /**
     * In-page expand content. Return null when expand is not applicable.
     */
    public function expand(User $viewer, string $item): ?PlatformZoneExpandResult;
}
