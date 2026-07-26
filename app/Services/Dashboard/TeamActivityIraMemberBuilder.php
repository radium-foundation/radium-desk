<?php

namespace App\Services\Dashboard;

use App\Data\RecentActivityItem;
use App\Data\TeamActivityAgentRow;
use App\Data\TeamActivityEntry;
use App\Enums\TeamActivityStatus;
use App\Models\AuditLog;
use App\Services\ServiceCaseAutomationHealthService;
use App\Support\Dashboard\RecentActivityPresenter;
use App\Support\Dashboard\TeamActivityLabelFormatter;
use Illuminate\Support\Carbon;

class TeamActivityIraMemberBuilder
{
    public function __construct(
        private readonly ServiceCaseAutomationHealthService $healthService,
        private readonly RecentActivityPresenter $activityPresenter,
        private readonly TeamActivityLabelFormatter $labelFormatter,
    ) {}

    public function build(): TeamActivityAgentRow
    {
        $activityEvents = $this->activityEventAllowlist();
        $countEvents = $this->countEventAllowlist();
        $dayStart = Carbon::now()->startOfDay();

        $todayCount = $countEvents === []
            ? 0
            : (int) AuditLog::query()
                ->whereIn('event', $countEvents)
                ->where('created_at', '>=', $dayStart)
                ->count();

        $latestAudit = $activityEvents === []
            ? null
            : AuditLog::query()
                ->with(RecentActivityPresenter::eagerLoadRelations())
                ->whereIn('event', $activityEvents)
                ->latest('created_at')
                ->latest('id')
                ->first();

        $itemsByAuditId = $latestAudit instanceof AuditLog
            ? $this->activityPresenter->presentItemsById(collect([$latestAudit]))->all()
            : [];

        return new TeamActivityAgentRow(
            id: (int) config('dashboard-team-activity.ira_agent_id', 0),
            name: (string) config('dashboard-team-activity.ira_display_name', 'IRA'),
            status: TeamActivityStatus::Ira,
            statusLabel: $this->resolveStatusLabel(),
            statusTone: TeamActivityStatus::Ira->tone(),
            workingLabel: null,
            overtimeLabel: null,
            todayCount: $todayCount,
            latest: $this->entryFromAudit($latestAudit, $itemsByAuditId),
            history: [],
            expanded: false,
            isVirtual: true,
            badge: (string) config('dashboard-team-activity.ira_badge', 'AI / Automation'),
            latestActivityAt: $latestAudit?->created_at,
        );
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
    private function countEventAllowlist(): array
    {
        return $this->normalizedEventList('ira_event_count_allowlist');
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

    /**
     * @param  array<int, RecentActivityItem>  $itemsByAuditId
     */
    private function entryFromAudit(?AuditLog $audit, array $itemsByAuditId): ?TeamActivityEntry
    {
        if (! $audit instanceof AuditLog || $audit->created_at === null) {
            return null;
        }

        $item = $itemsByAuditId[(int) $audit->id] ?? null;

        if (! $item instanceof RecentActivityItem) {
            return null;
        }

        $reference = $item->incidentLabel();

        if ($reference === '') {
            $reference = null;
        }

        $label = $this->labelFormatter->labelFor($audit, $item);

        return new TeamActivityEntry(
            at: $audit->created_at,
            time: $audit->created_at->format('H:i'),
            label: $label,
            reference: $reference,
            incidentId: $item->entityIncidentId,
        );
    }
}
