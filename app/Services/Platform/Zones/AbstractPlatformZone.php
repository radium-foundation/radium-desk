<?php

namespace App\Services\Platform\Zones;

use App\Contracts\Platform\PlatformZone;
use App\Data\Platform\PlatformZoneDefinition;
use App\Data\Platform\PlatformZoneExpandResult;
use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformZoneId;
use App\Models\User;

abstract class AbstractPlatformZone implements PlatformZone
{
    public function __construct(
        protected readonly PlatformZoneSnapshotStore $snapshotStore,
    ) {}

    abstract protected function zoneId(): PlatformZoneId;

    abstract protected function buildFreshSnapshot(User $viewer): PlatformZoneSnapshot;

    protected function placeholderMessage(): string
    {
        return $this->definition()->title.' will load after first refresh.';
    }

    protected function permission(): ?string
    {
        return null;
    }

    protected function expandable(): bool
    {
        return false;
    }

    protected function description(): ?string
    {
        return null;
    }

    public function definition(): PlatformZoneDefinition
    {
        $id = $this->zoneId();

        return new PlatformZoneDefinition(
            id: $id,
            title: $id->label(),
            refreshPriority: $id->refreshPriority(),
            sortOrder: $id->sortOrder(),
            icon: $id->icon(),
            expandable: $this->expandable(),
            permission: $this->permission(),
            description: $this->description(),
        );
    }

    public function authorize(User $viewer): bool
    {
        $permission = $this->definition()->permission;

        if ($permission === null || $permission === '') {
            return true;
        }

        return $viewer->can($permission);
    }

    public function snapshot(User $viewer): PlatformZoneSnapshot
    {
        $cached = $this->snapshotStore->get($this->definition()->key());

        if ($cached !== null) {
            return $cached;
        }

        return $this->buildPlaceholderSnapshot($viewer);
    }

    public function refresh(User $viewer): PlatformZoneSnapshot
    {
        $snapshot = $this->buildFreshSnapshot($viewer);
        $this->snapshotStore->put($snapshot);

        return $snapshot;
    }

    public function status(User $viewer): PlatformHealthStatus
    {
        return $this->snapshot($viewer)->status;
    }

    public function expand(User $viewer, string $item): ?PlatformZoneExpandResult
    {
        return null;
    }

    protected function buildPlaceholderSnapshot(User $viewer): PlatformZoneSnapshot
    {
        $definition = $this->definition();

        $html = view('admin.platform.zones.partials.placeholder', [
            'title' => $definition->title,
            'message' => $this->placeholderMessage(),
            'zoneKey' => $definition->key(),
        ])->render();

        return new PlatformZoneSnapshot(
            key: $definition->key(),
            status: PlatformHealthStatus::Disabled,
            statusLabel: 'Pending',
            updatedAt: null,
            summary: [
                'state' => 'placeholder',
                'message' => $this->placeholderMessage(),
            ],
            html: $html,
            fromCache: false,
            available: false,
        );
    }

    /**
     * @param  array<string, mixed>  $summary
     */
    protected function makeSnapshot(
        PlatformHealthStatus $status,
        string $html,
        array $summary = [],
        ?\Illuminate\Support\Carbon $updatedAt = null,
        bool $available = true,
    ): PlatformZoneSnapshot {
        return new PlatformZoneSnapshot(
            key: $this->definition()->key(),
            status: $status,
            statusLabel: $status->label(),
            updatedAt: $updatedAt ?? now(),
            summary: $summary,
            html: $html,
            fromCache: false,
            available: $available,
        );
    }
}
