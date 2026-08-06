<?php

namespace App\Services\Platform\Zones;

use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformZoneId;
use App\Services\Platform\PlatformEmailOperationsService;
use Illuminate\Support\Carbon;
use Throwable;

class EmailOperationsZone extends AbstractCachedOverviewZone
{
    public function __construct(
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformEmailOperationsService $emailOperations,
    ) {
        parent::__construct($snapshotStore);
    }

    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::EmailOperations;
    }

    protected function description(): ?string
    {
        return 'Inbound email intake health, pipeline, and exceptions.';
    }

    protected function placeholderMessage(): string
    {
        return 'Email Operations summaries load after first refresh.';
    }

    protected function overviewPartial(): string
    {
        return 'admin.platform.zones.partials.email-operations';
    }

    protected function readCachedOverview(): array
    {
        return $this->emailOperations->cachedOverview();
    }

    protected function buildOverview(): array
    {
        return $this->emailOperations->overview(useCache: false);
    }

    /**
     * @param  array<string, mixed>  $overview
     */
    protected function snapshotFromOverview(array $overview, bool $fromCache): PlatformZoneSnapshot
    {
        $status = PlatformHealthStatus::tryFrom((string) ($overview['overall_status'] ?? ''))
            ?? PlatformHealthStatus::Disabled;

        $updatedAt = null;
        if (! empty($overview['generated_at']) && is_string($overview['generated_at'])) {
            try {
                $updatedAt = Carbon::parse($overview['generated_at']);
            } catch (Throwable) {
                $updatedAt = null;
            }
        }

        $html = view($this->overviewPartial(), [
            'overview' => $overview,
            'zoneKey' => $this->definition()->key(),
            'available' => (bool) ($overview['available'] ?? false),
            'links' => $overview['links'] ?? [],
        ])->render();

        return new PlatformZoneSnapshot(
            key: $this->definition()->key(),
            status: $status,
            statusLabel: $status->label(),
            updatedAt: $updatedAt,
            summary: [
                'state' => ($overview['available'] ?? false) ? 'ready' : 'loading',
                'overall_status' => $status->value,
                'enabled' => (bool) ($overview['enabled'] ?? false),
                'exception_count' => count($overview['exceptions'] ?? []),
            ],
            html: $html,
            fromCache: $fromCache,
            available: (bool) ($overview['available'] ?? false),
        );
    }
}
