<?php

namespace App\Services\Dashboard;

use App\Data\RecentActivityItem;
use App\Data\TeamActivityAgentRow;
use App\Data\TeamActivityEntry;
use App\Enums\TeamActivityStatus;
use App\Models\AuditLog;
use App\Services\ServiceCaseAutomationHealthService;
use App\Support\Dashboard\RecentActivityPresenter;
use App\Support\Dashboard\TeamActivityEntryPresenter;
use App\Support\Dashboard\TeamActivityKpiAuditQuery;
use Illuminate\Support\Collection;

class TeamActivityIraMemberBuilder
{
    public function __construct(
        private readonly ServiceCaseAutomationHealthService $healthService,
        private readonly RecentActivityPresenter $activityPresenter,
        private readonly TeamActivityEntryPresenter $entryPresenter,
        private readonly TeamActivityKpiAuditQuery $kpiAuditQuery,
    ) {}

    /**
     * @param  array<int, RecentActivityItem>  $itemsByAuditId
     */
    public function build(bool $expanded = false, array $itemsByAuditId = []): TeamActivityAgentRow
    {
        $activityEvents = $this->activityEventAllowlist();
        $iraCounts = $this->kpiAuditQuery->todayIraPanelCounts();
        $todayCount = $iraCounts['kpi'];

        $latestAudit = $activityEvents === []
            ? null
            : AuditLog::query()
                ->with(RecentActivityPresenter::eagerLoadRelations())
                ->whereIn('event', $activityEvents)
                ->latest('created_at')
                ->latest('id')
                ->first();

        $presentationItems = $itemsByAuditId;

        if ($latestAudit instanceof AuditLog && ! isset($presentationItems[(int) $latestAudit->id])) {
            $presentationItems += $this->activityPresenter
                ->presentItemsById(collect([$latestAudit]))
                ->all();
        }

        $history = [];

        if ($expanded) {
            foreach ($this->todayCountedAuditsForPresentation() as $audit) {
                if (! isset($presentationItems[(int) $audit->id])) {
                    $presentationItems += $this->activityPresenter
                        ->presentItemsById(collect([$audit]))
                        ->all();
                }

                $entry = $this->entryPresenter->fromAudit($audit, $presentationItems);

                if ($entry instanceof TeamActivityEntry) {
                    $history[] = $entry;
                }
            }
        }

        return new TeamActivityAgentRow(
            id: (int) config('dashboard-team-activity.ira_agent_id', 0),
            name: (string) config('dashboard-team-activity.ira_display_name', 'IRA'),
            status: TeamActivityStatus::Ira,
            statusLabel: $this->resolveStatusLabel(),
            statusTone: TeamActivityStatus::Ira->tone(),
            workingLabel: null,
            overtimeLabel: null,
            todayCount: $todayCount,
            latest: $latestAudit instanceof AuditLog
                ? $this->entryPresenter->fromAudit($latestAudit, $presentationItems)
                : null,
            history: $history,
            expanded: $expanded,
            isVirtual: true,
            badge: null,
            latestActivityAt: $latestAudit?->created_at,
            supplementaryKpiCount: $iraCounts['automation_cases'],
            supplementaryKpiLabel: 'Automated Cases',
        );
    }

    /**
     * @return list<AuditLog>
     */
    public function todayCountedAuditsForPresentation(): array
    {
        $audits = $this->kpiAuditQuery->todayCountedAuditsForIra();

        if ($audits === []) {
            return [];
        }

        return AuditLog::query()
            ->with(RecentActivityPresenter::eagerLoadRelations())
            ->whereIn('id', collect($audits)->pluck('id'))
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->all();
    }

    private function resolveStatusLabel(): string
    {
        $counts = $this->healthService->counts();

        if (($counts['validation_failed'] ?? 0) > 0 || ($counts['waiting_for_customer_serial'] ?? 0) > 0) {
            return 'Waiting Manual Correction';
        }

        if (($counts['radiumbox_pending'] ?? 0) > 0) {
            return 'Waiting RadiumBox';
        }

        if (($counts['automation_pending'] ?? 0) > 0 || ($counts['grace_expired'] ?? 0) > 0) {
            return 'Processing';
        }

        return 'Idle';
    }

    /**
     * @return list<string>
     */
    private function activityEventAllowlist(): array
    {
        return $this->normalizedEventList('ira_event_allowlist');
    }

    /**
     * @return list<string>
     */
    private function normalizedEventList(string $configKey): array
    {
        $events = config('dashboard-team-activity.'.$configKey, []);

        return array_values(array_filter(
            is_array($events) ? $events : [],
            static fn (mixed $event): bool => is_string($event) && $event !== '',
        ));
    }
}
