<?php

namespace App\Services\HardwareFulfilment;

use App\Events\Dashboard\HardwareStuckOrdersUpdated;
use App\Jobs\HardwareFulfilment\RefreshHardwareStuckOrdersSnapshotJob;
use App\Models\User;
use App\Services\Operations\OperationsRoleService;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\View;
use Throwable;

final class HardwareStuckOrdersSnapshotRefresher
{
    public function __construct(
        private readonly HardwareDashboardWorkspace $workspace,
        private readonly HardwareStuckOrdersSnapshotStore $snapshotStore,
        private readonly OperationsRoleService $operationsRoles,
    ) {}

    public function queueBackgroundRefreshIfNeeded(): void
    {
        if (! $this->snapshotStore->shouldQueueBackgroundRefresh()) {
            return;
        }

        RefreshHardwareStuckOrdersSnapshotJob::dispatch()->afterResponse();
    }

    /**
     * @return array{
     *     refreshed: bool,
     *     version: int|null,
     *     summary: array|null
     * }
     */
    public function refreshAndBroadcast(bool $blockForLock = false): array
    {
        if (! $this->snapshotStore->acquireRefreshLock($blockForLock ? 30 : 0)) {
            return [
                'refreshed' => false,
                'version' => null,
                'summary' => null,
            ];
        }

        try {
            $summary = $this->workspace->stuckOrderSummary();
            $version = $this->snapshotStore->storeSuccessfulRefresh($summary);
            $this->broadcastToAdmins($summary, $version);

            return [
                'refreshed' => true,
                'version' => $version,
                'summary' => $summary,
            ];
        } catch (Throwable) {
            return [
                'refreshed' => false,
                'version' => null,
                'summary' => null,
            ];
        } finally {
            $this->snapshotStore->releaseRefreshLock();
        }
    }

    /**
     * @param  array{needs_action_total: int, breakdown: list<array{filter: string, label: string, count: int, tone: string}>}  $summary
     */
    private function broadcastToAdmins(array $summary, int $version): void
    {
        $html = View::make('dashboard.partials.admin-hardware-stuck-card', [
            'summary' => $summary,
            'stuckMeta' => [
                'captured_at' => now()->getTimestamp(),
                'version' => $version,
                'stale' => false,
            ],
        ])->render();

        foreach ($this->adminRecipients() as $recipient) {
            broadcast(new HardwareStuckOrdersUpdated(
                recipient: $recipient,
                summary: $summary,
                html: $html,
                capturedAt: now()->getTimestamp(),
                version: $version,
            ));
        }
    }

    /**
     * @return Collection<int, User>
     */
    private function adminRecipients()
    {
        return User::query()
            ->where('is_active', true)
            ->get()
            ->filter(fn (User $user): bool => $this->operationsRoles->usesAdminQueues($user)
                && $user->can('dashboard.hardware.view'));
    }
}
