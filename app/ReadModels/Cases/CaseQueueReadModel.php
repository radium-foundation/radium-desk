<?php

namespace App\ReadModels\Cases;

use App\Data\Cases\CaseQueueMetricsV1;
use App\Enums\OperationQueue;
use App\Models\Incident;
use App\Models\User;
use App\Services\Dashboard\DashboardSnapshot;
use App\Services\Dashboard\DashboardSnapshotStore;
use App\Services\Operations\OperationsQueueClassifier;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Read-only projection over DashboardSnapshot case-queue KPIs.
 *
 * - No SQL, classification, SLA calculation, or business rules.
 * - No cache layer — DashboardSnapshotStore remains the request-scoped owner.
 * - H4-6C: Ops summary counts (SupportIntelligence + IRA memory/owner).
 * - H4-6D: Workforce scoped summary counts via global()/forUser()/forTeamMembers().
 *
 * Membership owner: OperationsQueueClassifier.
 * Count owner: DashboardSnapshot.
 *
 * There is no Team Eloquent model; team scope is per-member openCount delegates.
 */
class CaseQueueReadModel
{
    public function __construct(
        private readonly DashboardSnapshotStore $snapshotStore,
        private readonly OperationsQueueClassifier $queueClassifier,
    ) {}

    /**
     * Unscoped (global) summary counts — identical to DashboardSnapshot with null user.
     */
    public function global(?DashboardSnapshot $snapshot = null): CaseQueueScope
    {
        return new CaseQueueScope($this, null, $snapshot);
    }

    /**
     * User-scoped summary counts — identical to DashboardSnapshot::openCount($user) / queueCounts($user).
     */
    public function forUser(User $user, ?DashboardSnapshot $snapshot = null): CaseQueueScope
    {
        return new CaseQueueScope($this, $user, $snapshot);
    }

    /**
     * Per-member open counts for a Workforce/team member set.
     * Delegates openCount per user only — no aggregation formula.
     *
     * @param  iterable<int, User>  $users
     * @return array<int, int> user id → open work count
     */
    public function forTeamMembers(iterable $users, ?DashboardSnapshot $snapshot = null): array
    {
        $counts = [];

        foreach ($users as $user) {
            $counts[$user->id] = $this->forUser($user, $snapshot)->openCount();
        }

        return $counts;
    }

    /**
     * Scalar projection of existing open/waiting, queue, and SLA counts.
     */
    public function metrics(?User $scopeUser = null, ?DashboardSnapshot $snapshot = null): CaseQueueMetricsV1
    {
        return CaseQueueMetricsV1::fromSnapshot($this->resolve($snapshot), $scopeUser);
    }

    public function openCount(?User $scopeUser = null, ?DashboardSnapshot $snapshot = null): int
    {
        return $this->resolve($snapshot)->openCount($scopeUser);
    }

    public function waitingCount(?DashboardSnapshot $snapshot = null): int
    {
        return $this->resolve($snapshot)->waitingCount();
    }

    public function myWorkCount(User $scopeUser, ?DashboardSnapshot $snapshot = null): int
    {
        return $this->resolve($snapshot)->myWorkCount($scopeUser);
    }

    /**
     * @return array<string, int>
     */
    public function queueCounts(?User $scopeUser = null, ?DashboardSnapshot $snapshot = null): array
    {
        return $this->resolve($snapshot)->queueCounts($scopeUser);
    }

    public function queueCount(
        OperationQueue|string $queue,
        ?User $scopeUser = null,
        ?DashboardSnapshot $snapshot = null,
    ): int {
        $key = $queue instanceof OperationQueue ? $queue->value : $queue;

        return (int) ($this->queueCounts($scopeUser, $snapshot)[$key] ?? 0);
    }

    /**
     * @return array{
     *     overdue_cases: int,
     *     warning_cases: int,
     *     service_overdue_cases: int,
     *     service_warning_cases: int,
     *     hardware_overdue_cases: int,
     *     hardware_warning_cases: int
     * }
     */
    public function slaCounts(?Carbon $now = null, ?DashboardSnapshot $snapshot = null): array
    {
        return $this->resolve($snapshot)->slaCounts($now);
    }

    /**
     * @return array{overdue_cases: int, warning_cases: int}
     */
    public function serviceSlaCounts(?Carbon $now = null, ?DashboardSnapshot $snapshot = null): array
    {
        return $this->resolve($snapshot)->serviceSlaCounts($now);
    }

    /**
     * @return array{overdue_cases: int, warning_cases: int}
     */
    public function hardwareSlaCounts(?Carbon $now = null, ?DashboardSnapshot $snapshot = null): array
    {
        return $this->resolve($snapshot)->hardwareSlaCounts($now);
    }

    /**
     * @return array{open_cases: int, waiting_cases: int}
     */
    public function operationalKpiCounts(?User $scopeUser = null, ?DashboardSnapshot $snapshot = null): array
    {
        return $this->resolve($snapshot)->operationalKpiCounts($scopeUser);
    }

    /**
     * @return array<string, int>
     */
    public function filterCounts(
        ?User $assignedTo = null,
        ?User $user = null,
        ?DashboardSnapshot $snapshot = null,
    ): array {
        return $this->resolve($snapshot)->filterCounts($assignedTo, $user);
    }

    /**
     * Pass-through membership for shadow parity tests — delegates to OperationsQueueClassifier.
     */
    public function classify(Incident $incident): OperationQueue
    {
        return $this->queueClassifier->classify($incident);
    }

    /**
     * @return Collection<int, Incident>
     */
    public function incidentsForQueue(
        OperationQueue|string $queue,
        ?User $scopeUser = null,
        ?DashboardSnapshot $snapshot = null,
    ): Collection {
        $key = $queue instanceof OperationQueue ? $queue->value : $queue;

        return $this->resolve($snapshot)->incidentsForQueue($key, $scopeUser);
    }

    private function resolve(?DashboardSnapshot $snapshot): DashboardSnapshot
    {
        return $snapshot ?? $this->snapshotStore->get();
    }
}
