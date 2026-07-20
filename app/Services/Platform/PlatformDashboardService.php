<?php

namespace App\Services\Platform;

use App\Data\Platform\PlatformCardPayload;
use App\Data\Platform\PlatformDashboardData;
use App\Models\User;

class PlatformDashboardService
{
    public function __construct(
        private readonly DashboardManifest $manifest,
    ) {}

    public function build(User $viewer): PlatformDashboardData
    {
        return $this->manifest->resolve($viewer);
    }

    public function cardPayload(User $viewer, string $cardKey): PlatformCardPayload
    {
        return $this->manifest->cardPayload($viewer, $cardKey);
    }
}
