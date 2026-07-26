<?php

namespace App\Services\Dashboard;

use App\Data\RecentActivityItem;
use App\Data\TeamActivityAgentRow;
use App\Data\TeamActivityPanel;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\Operations\TeamAvailabilityOverviewService;
use App\Support\Dashboard\RecentActivityPresenter;
use App\Support\Dashboard\TeamActivityEntryPresenter;
use App\Support\Dashboard\TeamActivityKpiAuditQuery;
use App\Support\Dashboard\TeamActivityRowSorter;
use App\Support\Dashboard\TeamActivityStatusResolver;
use Illuminate\Support\Collection;

class TeamActivityPanelService
{
    public function __construct(
        private readonly TeamAvailabilityOverviewService $overviewService,
        private readonly RecentActivityPresenter $activityPresenter,
        private readonly TeamActivityStatusResolver $statusResolver,
        private readonly TeamActivityEntryPresenter $entryPresenter,
        private readonly TeamActivityKpiAuditQuery $kpiAuditQuery,
        private readonly TeamActivityIraMemberBuilder $iraMemberBuilder,
        private readonly TeamActivityRowSorter $rowSorter,
        private readonly TeamActivityPresenceMetricsService $presenceMetricsService,
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
        $iraAgentId = $this->iraAgentId();
        $expandedIds = $this->normalizeExpandedIds($expandedAgentIds, $userIds, $iraAgentId);
        $allowlist = $this->eventAllowlist();

        $todayCounts = $this->kpiAuditQuery->todayCountsForUsers($userIds);
        $latestByUser = $this->latestAuditsFor($userIds, $allowlist);
        $countedAuditsByUser = $expandedIds === []
            ? []
            : $this->kpiAuditQuery->todayCountedAuditsForUsers(
                array_values(array_filter(
                    $expandedIds,
                    static fn (int $id): bool => $id !== $iraAgentId,
                )),
            );

        $allAudits = collect($latestByUser)
            ->merge(collect($countedAuditsByUser)->flatten(1))
            ->filter()
            ->unique(fn (AuditLog $log): int => (int) $log->id)
            ->values();

        if (in_array($iraAgentId, $expandedIds, true)) {
            $allAudits = $allAudits
                ->merge($this->iraMemberBuilder->todayCountedAuditsForPresentation())
                ->unique(fn (AuditLog $log): int => (int) $log->id)
                ->values();
        }

        $itemsByAuditId = $this->presentItemsById($allAudits)->all();
        $presenceMetricsByUser = $this->presenceMetricsService->forUsers($userIds);

        $agents = [];

        foreach ($members as $member) {
            $userId = (int) $member['id'];
            $latestAudit = $latestByUser[$userId] ?? null;
            $presenceMetrics = $presenceMetricsByUser[$userId] ?? null;
            $status = $this->statusResolver->resolve($member, $latestAudit, $presenceMetrics);
            $expanded = in_array($userId, $expandedIds, true);
            $latestEntry = $latestAudit !== null
                ? $this->entryPresenter->fromAudit($latestAudit, $itemsByAuditId)
                : null;

            $history = [];

            if ($expanded) {
                foreach ($countedAuditsByUser[$userId] ?? [] as $audit) {
                    $entry = $this->entryPresenter->fromAudit($audit, $itemsByAuditId);

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
                calendarBadge: $this->statusResolver->calendarBadge($member),
                todayDurationLabel: $presenceMetrics?->todayDurationLabel,
                currentDurationLabel: $presenceMetrics?->currentDurationLabel,
                sessionsToday: ($presenceMetrics?->sessionsToday ?? 0) > 0
                    ? $presenceMetrics->sessionsToday
                    : null,
            );
        }

        $iraExpanded = in_array($iraAgentId, $expandedIds, true);
        $agents = $this->rowSorter->sort(
            $agents,
            $this->iraMemberBuilder->build(expanded: $iraExpanded, itemsByAuditId: $itemsByAuditId),
        );

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
    private function normalizeExpandedIds(array $expandedAgentIds, array $rosterIds, int $iraAgentId): array
    {
        $max = max(0, (int) config('dashboard-team-activity.max_expanded_agents', 20));
        $rosterLookup = array_fill_keys($rosterIds, true);

        $normalized = [];

        foreach ($expandedAgentIds as $id) {
            $id = (int) $id;

            if ($id !== $iraAgentId && ($id <= 0 || ! isset($rosterLookup[$id]))) {
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

    private function iraAgentId(): int
    {
        return (int) config('dashboard-team-activity.ira_agent_id', 0);
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
     * @param  Collection<int, AuditLog>  $auditLogs
     * @return Collection<int, RecentActivityItem>
     */
    private function presentItemsById(Collection $auditLogs): Collection
    {
        if ($auditLogs->isEmpty()) {
            return collect();
        }

        $missingRelations = $auditLogs->filter(
            static fn (AuditLog $log): bool => ! $log->relationLoaded('auditable') || ! $log->relationLoaded('user'),
        );

        if ($missingRelations->isNotEmpty()) {
            $auditLogs = AuditLog::query()
                ->with(RecentActivityPresenter::eagerLoadRelations())
                ->whereIn('id', $auditLogs->pluck('id'))
                ->get();
        }

        return $this->activityPresenter->presentItemsById($auditLogs);
    }
}
