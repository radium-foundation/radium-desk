<?php

namespace App\Services;

use App\Jobs\SendServiceReferenceDriverGuideBatchJob;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Notifications\TransactionCompletedNotification;
use App\Services\Automation\AutomationOperationsSnapshotInvalidator;
use App\Services\Dashboard\DashboardSnapshotStore;
use Illuminate\Support\Facades\Log;

/**
 * Request-scoped coalescer for batch Assign Reference side effects.
 *
 * Performance-only: preserves audits, commercial gates, case closure, and
 * per-order notification / driver-guide outcomes while collapsing repeated
 * snapshot invalidations, automation dirty marks, and per-order job dispatches.
 */
class AssignReferenceBatchCoalescer
{
    private bool $active = false;

    private bool $dashboardSnapshotPending = false;

    private bool $automationDirtyPending = false;

    /** @var list<array{order_id: int, transaction_id: string, actor_id: int}> */
    private array $pendingNotifications = [];

    /** @var list<array{order_id: int, service_reference: string}> */
    private array $pendingDriverGuides = [];

    private ?int $driverGuideActorId = null;

    private int $deferredSnapshotMarks = 0;

    private int $deferredAutomationMarks = 0;

    public function begin(): void
    {
        $this->reset();
        $this->active = true;
    }

    public function isActive(): bool
    {
        return $this->active;
    }

    public function noteDashboardSnapshotInvalidation(): void
    {
        if (! $this->active) {
            return;
        }

        $this->dashboardSnapshotPending = true;
        $this->deferredSnapshotMarks++;
    }

    public function noteAutomationDirty(): void
    {
        if (! $this->active) {
            return;
        }

        $this->automationDirtyPending = true;
        $this->deferredAutomationMarks++;
    }

    public function deferDriverGuide(int $orderId, string $serviceReference, int $actorId): void
    {
        if (! $this->active) {
            return;
        }

        $this->pendingDriverGuides[] = [
            'order_id' => $orderId,
            'service_reference' => $serviceReference,
        ];
        $this->driverGuideActorId = $actorId;
    }

    public function deferNotification(int $orderId, string $transactionId, int $actorId): void
    {
        if (! $this->active) {
            return;
        }

        $this->pendingNotifications[] = [
            'order_id' => $orderId,
            'transaction_id' => $transactionId,
            'actor_id' => $actorId,
        ];
    }

    /**
     * Flush cache invalidations once before dashboard row/KPI rebuild.
     */
    public function flushInvalidations(
        DashboardSnapshotStore $dashboardSnapshotStore,
        AutomationOperationsSnapshotInvalidator $snapshotInvalidator,
    ): void {
        if (! $this->active) {
            return;
        }

        if ($this->dashboardSnapshotPending) {
            $dashboardSnapshotStore->forget();
            $this->dashboardSnapshotPending = false;
        }

        if ($this->automationDirtyPending) {
            $snapshotInvalidator->markCaseOrOrderChanged();
            $this->automationDirtyPending = false;
        }
    }

    /**
     * Flush deferred notifications + one batch Driver Guide job (assignment order preserved).
     */
    public function flushCommunications(SettingService $settingService): void
    {
        if (! $this->active) {
            return;
        }

        $this->flushNotifications($settingService);
        $this->flushDriverGuides();
    }

    public function cancel(): void
    {
        $this->reset();
    }

    public function end(): void
    {
        $this->reset();
    }

    /**
     * @return array{
     *     deferred_snapshot_marks: int,
     *     deferred_automation_marks: int,
     *     pending_notifications: int,
     *     pending_driver_guides: int
     * }
     */
    public function stats(): array
    {
        return [
            'deferred_snapshot_marks' => $this->deferredSnapshotMarks,
            'deferred_automation_marks' => $this->deferredAutomationMarks,
            'pending_notifications' => count($this->pendingNotifications),
            'pending_driver_guides' => count($this->pendingDriverGuides),
        ];
    }

    private function flushNotifications(SettingService $settingService): void
    {
        if ($this->pendingNotifications === []) {
            return;
        }

        if (! $settingService->getBool('notifications.transaction_enabled', true)) {
            $this->pendingNotifications = [];

            return;
        }

        foreach ($this->pendingNotifications as $pending) {
            $order = Order::query()
                ->with(['transactionAssigner'])
                ->find($pending['order_id']);
            $actor = User::query()->find($pending['actor_id']);

            if ($order === null || $actor === null) {
                continue;
            }

            $recipients = Incident::query()
                ->with(['creator', 'assignee'])
                ->where('order_id', $order->id)
                ->get()
                ->flatMap(fn (Incident $incident) => collect([$incident->creator, $incident->assignee]))
                ->filter(fn (?User $user): bool => $user !== null && $user->is_active && ! $user->trashed())
                ->unique('id');

            foreach ($recipients as $recipient) {
                $recipient->notify(new TransactionCompletedNotification(
                    $order,
                    $pending['transaction_id'],
                    $actor,
                ));
            }
        }

        $this->pendingNotifications = [];
    }

    private function flushDriverGuides(): void
    {
        if ($this->pendingDriverGuides === [] || $this->driverGuideActorId === null) {
            $this->pendingDriverGuides = [];

            return;
        }

        $items = $this->pendingDriverGuides;
        $actorId = $this->driverGuideActorId;
        $this->pendingDriverGuides = [];
        $this->driverGuideActorId = null;

        SendServiceReferenceDriverGuideBatchJob::dispatch($items, $actorId);

        Log::info('bulk_assign.driver_guide.batch_dispatched', [
            'order_count' => count($items),
            'actor_id' => $actorId,
        ]);
    }

    private function reset(): void
    {
        $this->active = false;
        $this->dashboardSnapshotPending = false;
        $this->automationDirtyPending = false;
        $this->pendingNotifications = [];
        $this->pendingDriverGuides = [];
        $this->driverGuideActorId = null;
        $this->deferredSnapshotMarks = 0;
        $this->deferredAutomationMarks = 0;
    }
}
