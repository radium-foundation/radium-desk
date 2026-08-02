<?php

namespace App\Services\Platform\Warmers;

use App\Models\User;
use App\Services\Platform\Warmers\PlatformSnapshotWarmerRegistry;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Support\Facades\Log;

class PlatformSnapshotWarmingService
{
    public function __construct(
        private readonly PlatformSnapshotWarmerRegistry $registry,
    ) {}

    /**
     * @return array{warmed: list<string>, failed: list<string>, actor_id: ?int}
     */
    public function warmAll(?User $actor = null): array
    {
        $actor ??= $this->resolveActor();
        $warmed = [];
        $failed = [];

        foreach ($this->registry->all() as $warmer) {
            try {
                $warmer->warm($actor);
                $warmed[] = $warmer->key();
            } catch (\Throwable $exception) {
                report($exception);
                $failed[] = $warmer->key();
                Log::warning('platform.snapshot_warmer_failed', [
                    'warmer' => $warmer->key(),
                    'message' => $exception->getMessage(),
                ]);
            }
        }

        return [
            'warmed' => $warmed,
            'failed' => $failed,
            'actor_id' => $actor?->id,
        ];
    }

    private function resolveActor(): ?User
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', static fn ($query) => $query->where('name', RolePermissionSeeder::ROLE_SUPERADMIN))
            ->orderBy('id')
            ->first();
    }
}
