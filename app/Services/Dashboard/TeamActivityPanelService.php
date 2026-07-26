<?php

namespace App\Services\Dashboard;

use App\Data\RecentActivityItem;
use App\Data\TeamActivityAgentRow;
use App\Data\TeamActivityEntry;
use App\Data\TeamActivityPanel;
use App\Enums\RemarkOrigin;
use App\Models\AuditLog;
use App\Models\Remark;
use App\Models\User;
use App\Services\Operations\TeamAvailabilityOverviewService;
use App\Support\Dashboard\RecentActivityPresenter;
use App\Support\Dashboard\TeamActivityLabelFormatter;
use App\Support\Dashboard\TeamActivityRowSorter;
use App\Support\Dashboard\TeamActivityStatusResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TeamActivityPanelService
{
    public function __construct(
        private readonly TeamAvailabilityOverviewService $overviewService,
        private readonly RecentActivityPresenter $activityPresenter,
        private readonly TeamActivityStatusResolver $statusResolver,
        private readonly TeamActivityLabelFormatter $labelFormatter,
        private readonly TeamActivityIraMemberBuilder $iraMemberBuilder,
        private readonly TeamActivityRowSorter $rowSorter,
    ) {}

    /**
     * @param  list<int>  $expandedAgentIds
     */
    public function build(array $expandedAgentIds = []): TeamActivityPanel
    {
        if (! config('dashboard-team-activity.enabled', true)) {
            return TeamActivityPanel::empty();
        }

        $overview = $this->overviewService->operationalRoster();
        $members = $this->humanRosterMembers(array_values($overview));

        if ($members === []) {
            return TeamActivityPanel::empty();
        }

        $userIds = array_map(static fn (array $member): int => (int) $member['id'], $members);
        $expandedIds = $this->normalizeExpandedIds($expandedAgentIds, $userIds);
        $historyLimit = max(1, (int) config('dashboard-team-activity.history_limit', 12));
        $allowlist = $this->eventAllowlist();

        $todayCounts = $this->todayCountsFor($userIds);
        $latestByUser = $this->latestAuditsFor($userIds, $allowlist);
        $historyByUser = $expandedIds === []
            ? []
            : $this->historyAuditsFor($expandedIds, $allowlist, $historyLimit);

        $allAudits = collect($latestByUser)
            ->merge(collect($historyByUser)->flatten(1))
            ->filter()
            ->unique(fn (AuditLog $log): int => (int) $log->id)
            ->values();

        $itemsByAuditId = $this->activityPresenter
            ->presentItemsById($allAudits)
            ->all();

        $agents = [];

        foreach ($members as $member) {
            $userId = (int) $member['id'];
            $latestAudit = $latestByUser[$userId] ?? null;
            $status = $this->statusResolver->resolve($member, $latestAudit);
            $expanded = in_array($userId, $expandedIds, true);
            $latestEntry = $latestAudit !== null
                ? $this->entryFromAudit($latestAudit, $itemsByAuditId)
                : null;

            $history = [];

            if ($expanded) {
                foreach ($historyByUser[$userId] ?? [] as $audit) {
                    $entry = $this->entryFromAudit($audit, $itemsByAuditId);

                    if ($entry !== null) {
                        $history[] = $entry;
                    }
                }
            }

            $agents[] = new TeamActivityAgentRow(
                id: $userId,
                name: (string) ($member['name'] ?? 'Agent'),
                status: $status,
                statusLabel: $status->label(),
                statusTone: $status->tone(),
                workingLabel: $this->statusResolver->workingLabel($member, $status),
                overtimeLabel: null,
                todayCount: (int) ($todayCounts[$userId] ?? 0),
                latest: $latestEntry,
                history: $history,
                expanded: $expanded,
                latestActivityAt: $latestAudit?->created_at,
            );
        }

        $agents = $this->rowSorter->sort($agents, $this->iraMemberBuilder->build());

        return new TeamActivityPanel($agents, false);
    }

    public function render(TeamActivityPanel $panel): string
    {
        return view('dashboard.partials.team-activity-panel', [
            'panel' => $panel,
        ])->render();
    }

    /**
     * @param  list<int>  $expandedAgentIds
     * @param  list<int>  $rosterIds
     * @return list<int>
     */
    private function normalizeExpandedIds(array $expandedAgentIds, array $rosterIds): array
    {
        $max = max(0, (int) config('dashboard-team-activity.max_expanded_agents', 20));
        $rosterLookup = array_fill_keys($rosterIds, true);

        $normalized = [];

        foreach ($expandedAgentIds as $id) {
            $id = (int) $id;

            if ($id <= 0 || ! isset($rosterLookup[$id])) {
                continue;
            }

            $normalized[$id] = $id;

            if (count($normalized) >= $max) {
                break;
            }
        }

        return array_values($normalized);
    }

    /**
     * @param  list<array<string, mixed>>  $members
     * @return list<array<string, mixed>>
     */
    private function humanRosterMembers(array $members): array
    {
        if ($members === []) {
            return [];
        }

        $automationActorId = $this->automationActorUserId();

        return array_values(array_filter(
            $members,
            static fn (array $member): bool => $automationActorId === null
                || (int) ($member['id'] ?? 0) !== $automationActorId,
        ));
    }

    private function automationActorUserId(): ?int
    {
        $systemEmail = (string) config('cashfree.system_user_email');

        if ($systemEmail === '') {
            return null;
        }

        $userId = User::query()->where('email', $systemEmail)->value('id');

        return $userId !== null ? (int) $userId : null;
    }

    /**
     * @return list<string>
     */
    private function eventAllowlist(): array
    {
        $events = config('dashboard-team-activity.event_allowlist', []);

        return array_values(array_filter(
            is_array($events) ? $events : [],
            static fn (mixed $event): bool => is_string($event) && $event !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function eventCountAllowlist(): array
    {
        $events = config('dashboard-team-activity.event_count_allowlist', []);

        return array_values(array_filter(
            is_array($events) ? $events : [],
            static fn (mixed $event): bool => is_string($event) && $event !== '',
        ));
    }

    /**
     * Meaningful human operational actions for the Today · N KPI.
     *
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function todayCountsFor(array $userIds): array
    {
        if ($userIds === []) {
            return [];
        }

        $allowlist = $this->eventCountAllowlist();

        if ($allowlist === []) {
            return [];
        }

        $dayStart = Carbon::now()->startOfDay();
        $directEvents = array_values(array_filter(
            $allowlist,
            static fn (string $event): bool => ! in_array($event, ['created', 'deleted'], true),
        ));

        $counts = array_fill_keys($userIds, 0);

        if ($directEvents !== []) {
            foreach (
                AuditLog::query()
                    ->selectRaw('user_id, COUNT(*) as aggregate_count')
                    ->whereIn('user_id', $userIds)
                    ->whereIn('event', $directEvents)
                    ->where('created_at', '>=', $dayStart)
                    ->groupBy('user_id')
                    ->pluck('aggregate_count', 'user_id') as $userId => $aggregate
            ) {
                $counts[(int) $userId] += (int) $aggregate;
            }
        }

        if (in_array('created', $allowlist, true)) {
            $this->mergeOperationalCounts($counts, $this->createdOperationCounts($userIds, $dayStart));
        }

        if (in_array('deleted', $allowlist, true)) {
            $this->mergeOperationalCounts($counts, $this->deletedOperationCounts($userIds, $dayStart));
        }

        return $counts;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function createdOperationCounts(array $userIds, Carbon $dayStart): array
    {
        $remarkMorph = (new Remark)->getMorphClass();
        $counts = array_fill_keys($userIds, 0);

        foreach (
            AuditLog::query()
                ->selectRaw('user_id, COUNT(*) as aggregate_count')
                ->whereIn('user_id', $userIds)
                ->where('event', 'created')
                ->where('auditable_type', $remarkMorph)
                ->where('created_at', '>=', $dayStart)
                ->where(function ($query): void {
                    $query->whereNull('new_values->origin')
                        ->orWhere('new_values->origin', RemarkOrigin::Manual->value);
                })
                ->groupBy('user_id')
                ->pluck('aggregate_count', 'user_id') as $userId => $aggregate
        ) {
            $counts[(int) $userId] += (int) $aggregate;
        }

        return $counts;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function deletedOperationCounts(array $userIds, Carbon $dayStart): array
    {
        $remarkMorph = (new Remark)->getMorphClass();
        $counts = array_fill_keys($userIds, 0);

        foreach (
            AuditLog::query()
                ->selectRaw('user_id, COUNT(*) as aggregate_count')
                ->whereIn('user_id', $userIds)
                ->where('event', 'deleted')
                ->where('auditable_type', $remarkMorph)
                ->where('created_at', '>=', $dayStart)
                ->where(function ($query): void {
                    $query->whereNull('old_values->origin')
                        ->orWhere('old_values->origin', RemarkOrigin::Manual->value);
                })
                ->groupBy('user_id')
                ->pluck('aggregate_count', 'user_id') as $userId => $aggregate
        ) {
            $counts[(int) $userId] += (int) $aggregate;
        }

        return $counts;
    }

    /**
     * @param  array<int, int>  $target
     * @param  array<int, int>  $additions
     */
    private function mergeOperationalCounts(array &$target, array $additions): void
    {
        foreach ($additions as $userId => $count) {
            $target[$userId] = ($target[$userId] ?? 0) + $count;
        }
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $allowlist
     * @return array<int, AuditLog>
     */
    private function latestAuditsFor(array $userIds, array $allowlist): array
    {
        if ($userIds === [] || $allowlist === []) {
            return [];
        }

        $latestIds = AuditLog::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('user_id', $userIds)
            ->whereIn('event', $allowlist)
            ->groupBy('user_id')
            ->pluck('id')
            ->filter()
            ->all();

        if ($latestIds === []) {
            return [];
        }

        return AuditLog::query()
            ->with(RecentActivityPresenter::eagerLoadRelations())
            ->whereIn('id', $latestIds)
            ->get()
            ->keyBy(fn (AuditLog $log): int => (int) $log->user_id)
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $allowlist
     * @return array<int, list<AuditLog>>
     */
    private function historyAuditsFor(array $userIds, array $allowlist, int $historyLimit): array
    {
        if ($userIds === [] || $allowlist === []) {
            return [];
        }

        $fetchLimit = min(count($userIds) * $historyLimit * 2, 5000);

        $logs = AuditLog::query()
            ->with(RecentActivityPresenter::eagerLoadRelations())
            ->whereIn('user_id', $userIds)
            ->whereIn('event', $allowlist)
            ->latest('created_at')
            ->latest('id')
            ->limit($fetchLimit)
            ->get();

        $buckets = [];

        foreach ($logs as $log) {
            $userId = (int) $log->user_id;

            if (! in_array($userId, $userIds, true)) {
                continue;
            }

            $buckets[$userId] ??= [];

            if (count($buckets[$userId]) >= $historyLimit) {
                continue;
            }

            $buckets[$userId][] = $log;
        }

        return $buckets;
    }

    /**
     * @param  array<int, RecentActivityItem>  $itemsByAuditId
     */
    private function entryFromAudit(AuditLog $audit, array $itemsByAuditId): ?TeamActivityEntry
    {
        $item = $itemsByAuditId[(int) $audit->id] ?? null;

        if (! $item instanceof RecentActivityItem || $audit->created_at === null) {
            return null;
        }

        $reference = $item->incidentLabel();
        if ($reference === '') {
            $reference = null;
        }

        $label = $this->labelFormatter->labelFor($audit, $item);

        // Reference is already embedded for assign/reassign labels.
        $showReference = ! str_starts_with($label, 'Assigned ')
            && ! str_starts_with($label, 'Reassigned ')
            && ! str_starts_with($label, 'Escalated ');

        return new TeamActivityEntry(
            at: $audit->created_at,
            time: $audit->created_at->format('H:i'),
            label: $label,
            reference: $showReference ? $reference : null,
            incidentId: $item->entityIncidentId,
        );
    }
}
