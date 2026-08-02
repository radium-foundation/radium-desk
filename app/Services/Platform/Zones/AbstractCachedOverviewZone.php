<?php

namespace App\Services\Platform\Zones;

use App\Data\Platform\PlatformZoneExpandResult;
use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\PlatformHealthStatus;
use App\Models\User;
use Illuminate\Support\Carbon;
use Throwable;

/**
 * Cache-first overview zone: snapshot() never probes; refresh() rebuilds; expand() optional.
 *
 * @phpstan-type OverviewPayload array{
 *     items: list<array<string, mixed>>,
 *     overall_status: string,
 *     generated_at: ?string,
 *     available: bool,
 *     links?: list<array{label: string, url: string}>
 * }
 */
abstract class AbstractCachedOverviewZone extends AbstractPlatformZone
{
    /**
     * @return OverviewPayload
     */
    abstract protected function readCachedOverview(): array;

    /**
     * @return OverviewPayload
     */
    abstract protected function buildOverview(): array;

    abstract protected function overviewPartial(): string;

    /**
     * @return array<string, mixed>|null
     */
    protected function diagnosticsFor(string $item): ?array
    {
        return null;
    }

    protected function expandPartial(): string
    {
        return 'admin.platform.zones.partials.summary-expand';
    }

    public function snapshot(User $viewer): PlatformZoneSnapshot
    {
        $cachedZone = $this->snapshotStore->get($this->definition()->key());

        if ($cachedZone !== null) {
            return $cachedZone;
        }

        return $this->snapshotFromOverview($this->readCachedOverview(), fromCache: true);
    }

    protected function buildFreshSnapshot(User $viewer): PlatformZoneSnapshot
    {
        return $this->snapshotFromOverview($this->buildOverview(), fromCache: false);
    }

    public function expand(User $viewer, string $item): ?PlatformZoneExpandResult
    {
        if ($item === '' || $item === 'default') {
            return null;
        }

        try {
            $diagnostics = $this->diagnosticsFor($item);
        } catch (Throwable $exception) {
            report($exception);

            $html = view('admin.platform.zones.partials.summary-unavailable', [
                'label' => $item,
                'message' => 'Unable to load details for this item.',
            ])->render();

            return new PlatformZoneExpandResult(
                zone: $this->definition()->key(),
                item: $item,
                html: $html,
                meta: [
                    'status' => PlatformHealthStatus::Critical->value,
                    'retryable' => true,
                ],
            );
        }

        if ($diagnostics === null) {
            return null;
        }

        $html = view($this->expandPartial(), [
            'diagnostics' => $diagnostics,
        ])->render();

        return new PlatformZoneExpandResult(
            zone: $this->definition()->key(),
            item: $item,
            html: $html,
            meta: [
                'key' => $item,
                'label' => $diagnostics['label'] ?? $item,
            ],
        );
    }

    /**
     * @param  OverviewPayload  $overview
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
            'items' => $overview['items'] ?? [],
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
                'item_count' => count($overview['items'] ?? []),
            ],
            html: $html,
            fromCache: $fromCache,
            available: (bool) ($overview['available'] ?? false),
        );
    }
}
