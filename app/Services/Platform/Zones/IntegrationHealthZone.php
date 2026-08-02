<?php

namespace App\Services\Platform\Zones;

use App\Data\Platform\PlatformZoneExpandResult;
use App\Data\Platform\PlatformZoneSnapshot;
use App\Enums\IntegrationHealthStatus;
use App\Enums\PlatformHealthStatus;
use App\Enums\PlatformZoneId;
use App\Models\User;
use App\Services\Platform\PlatformIntegrationHealthOverviewService;
use Illuminate\Support\Carbon;
use Throwable;

class IntegrationHealthZone extends AbstractPlatformZone
{
    public function __construct(
        PlatformZoneSnapshotStore $snapshotStore,
        private readonly PlatformIntegrationHealthOverviewService $integrations,
    ) {
        parent::__construct($snapshotStore);
    }

    protected function zoneId(): PlatformZoneId
    {
        return PlatformZoneId::IntegrationHealth;
    }

    protected function expandable(): bool
    {
        // Expand is per-integration card via zone expand API (item key).
        return false;
    }

    protected function description(): ?string
    {
        return 'RadiumBox, Cashfree, Gmail, Interakt, and channel health.';
    }

    protected function placeholderMessage(): string
    {
        return 'Integration Health summaries load after first refresh.';
    }

    public function snapshot(User $viewer): PlatformZoneSnapshot
    {
        $cachedZone = $this->snapshotStore->get($this->definition()->key());

        if ($cachedZone !== null) {
            return $cachedZone;
        }

        $overview = $this->integrations->cachedOverview();

        return $this->snapshotFromOverview($overview, fromCache: true);
    }

    public function status(User $viewer): PlatformHealthStatus
    {
        return $this->snapshot($viewer)->status;
    }

    protected function buildFreshSnapshot(User $viewer): PlatformZoneSnapshot
    {
        $overview = $this->integrations->overview(useCache: false);

        return $this->snapshotFromOverview($overview, fromCache: false);
    }

    public function expand(User $viewer, string $item): ?PlatformZoneExpandResult
    {
        if ($item === '' || $item === 'default') {
            return null;
        }

        if (! $this->integrations->isKnownKey($item)) {
            return null;
        }

        try {
            $diagnostics = $this->integrations->diagnostics($item);
        } catch (Throwable $exception) {
            report($exception);

            $previous = $this->integrations->cachedItem($item);
            $html = view('admin.platform.zones.integration-health.unavailable', [
                'label' => PlatformIntegrationHealthOverviewService::INTEGRATION_LABELS[$item] ?? $item,
                'message' => 'Unable to load diagnostics for this integration.',
                'lastSuccessfulUpdate' => $previous['updated_at'] ?? null,
                'retryUrl' => route('admin.platform.zones.expand', [
                    'zone' => $this->definition()->key(),
                    'item' => $item,
                ]),
            ])->render();

            return new PlatformZoneExpandResult(
                zone: $this->definition()->key(),
                item: $item,
                html: $html,
                meta: [
                    'status' => IntegrationHealthStatus::Unavailable->value,
                    'retryable' => true,
                ],
            );
        }

        $html = view('admin.platform.zones.integration-health.expand', [
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
     * @param  array{
     *     items: list<array<string, mixed>>,
     *     overall_status: string,
     *     overall_status_label: string,
     *     generated_at: ?string,
     *     available: bool
     * }  $overview
     */
    private function snapshotFromOverview(array $overview, bool $fromCache): PlatformZoneSnapshot
    {
        $integrationStatus = IntegrationHealthStatus::tryFrom((string) $overview['overall_status'])
            ?? IntegrationHealthStatus::Loading;
        $platformStatus = $integrationStatus->toPlatform();

        $updatedAt = null;
        if (! empty($overview['generated_at']) && is_string($overview['generated_at'])) {
            try {
                $updatedAt = Carbon::parse($overview['generated_at']);
            } catch (Throwable) {
                $updatedAt = null;
            }
        }

        $html = view('admin.platform.zones.integration-health.overview', [
            'items' => $overview['items'],
            'zoneKey' => $this->definition()->key(),
            'available' => $overview['available'],
        ])->render();

        return new PlatformZoneSnapshot(
            key: $this->definition()->key(),
            status: $platformStatus,
            statusLabel: $integrationStatus->label(),
            updatedAt: $updatedAt,
            summary: [
                'state' => $overview['available'] ? 'ready' : 'loading',
                'overall_status' => $integrationStatus->value,
                'item_count' => count($overview['items']),
                'items' => array_map(
                    static fn (array $item): array => [
                        'key' => $item['key'] ?? null,
                        'status' => $item['status'] ?? null,
                        'summary' => $item['summary'] ?? ($item['detail'] ?? null),
                        'updated_at' => $item['updated_at'] ?? null,
                    ],
                    $overview['items'],
                ),
            ],
            html: $html,
            fromCache: $fromCache,
            available: (bool) $overview['available'],
        );
    }
}
