<?php

namespace App\Support\Dashboard;

use App\Enums\RemarkOrigin;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\AuditLog;
use App\Models\Remark;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TeamActivityKpiAuditQuery
{
    public function __construct(
        private readonly TeamActivityIncidentResolver $incidentResolver,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    public function todayCountsForUsers(array $userIds, ?Carbon $dayStart = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $allowlist = $this->humanCountAllowlist();
        $counts = array_fill_keys($userIds, 0);

        if ($allowlist === []) {
            return $counts;
        }

        $dayStart ??= $this->dayStart();
        $filteredEvents = ['created', 'deleted', 'whatsapp.template_sent'];
        $directEvents = array_values(array_filter(
            $allowlist,
            static fn (string $event): bool => ! in_array($event, $filteredEvents, true),
        ));

        foreach (
            $this->incidentResolver->distinctCaseCountsForUsers(
                userIds: $userIds,
                dayStart: $dayStart,
                directEvents: $directEvents,
                includeManualWhatsApp: in_array('whatsapp.template_sent', $allowlist, true),
                includeManualRemarkCreated: in_array('created', $allowlist, true),
                includeManualRemarkDeleted: in_array('deleted', $allowlist, true),
            ) as $userId => $aggregate
        ) {
            $counts[$userId] = $aggregate;
        }

        return $counts;
    }

    public function todayCountForIra(?Carbon $dayStart = null): int
    {
        return $this->todayIraPanelCounts($dayStart)['kpi'];
    }

    /**
     * IRA supervisor display metrics (single query).
     *
     * @return array{kpi: int, automation_cases: int}
     */
    public function todayIraPanelCounts(?Carbon $dayStart = null): array
    {
        $kpiEvents = $this->iraCountAllowlist();
        $automationEvents = $this->iraActivityAllowlist();
        $dayStart ??= $this->dayStart();

        if ($kpiEvents === [] && $automationEvents === []) {
            return ['kpi' => 0, 'automation_cases' => 0];
        }

        $allEvents = array_values(array_unique(array_merge($kpiEvents, $automationEvents)));

        $kpiExpression = $this->distinctCaseCountExpression('auditable_id', $kpiEvents, 'kpi_count');
        $automationExpression = $this->distinctCaseCountExpression('auditable_id', $automationEvents, 'automation_cases');

        $row = AuditLog::query()
            ->where('created_at', '>=', $dayStart)
            ->whereIn('event', $allEvents)
            ->selectRaw($kpiExpression['sql'], $kpiExpression['bindings'])
            ->selectRaw($automationExpression['sql'], $automationExpression['bindings'])
            ->first();

        return [
            'kpi' => (int) ($row->kpi_count ?? 0),
            'automation_cases' => (int) ($row->automation_cases ?? 0),
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, list<AuditLog>>
     */
    public function todayCountedAuditsForUsers(array $userIds, ?Carbon $dayStart = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $dayStart ??= $this->dayStart();
        $buckets = array_fill_keys($userIds, []);

        foreach ($this->todayCountedAuditLogsForUsers($userIds, $dayStart) as $log) {
            $userId = (int) $log->user_id;
            $buckets[$userId][] = $log;
        }

        return $buckets;
    }

    /**
     * @return list<AuditLog>
     */
    public function todayCountedAuditsForIra(?Carbon $dayStart = null): array
    {
        $countEvents = $this->iraCountAllowlist();

        if ($countEvents === []) {
            return [];
        }

        $dayStart ??= $this->dayStart();

        $latestIds = AuditLog::query()
            ->selectRaw('MAX(id) as id')
            ->whereIn('event', $countEvents)
            ->where('created_at', '>=', $dayStart)
            ->groupBy('auditable_type', 'auditable_id')
            ->pluck('id')
            ->filter()
            ->all();

        if ($latestIds === []) {
            return [];
        }

        return AuditLog::query()
            ->whereIn('id', $latestIds)
            ->latest('created_at')
            ->latest('id')
            ->get()
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, AuditLog>
     */
    private function todayCountedAuditLogsForUsers(array $userIds, Carbon $dayStart): Collection
    {
        $allowlist = $this->humanCountAllowlist();

        if ($allowlist === []) {
            return collect();
        }

        $filteredEvents = ['created', 'deleted', 'whatsapp.template_sent'];
        $directEvents = array_values(array_filter(
            $allowlist,
            static fn (string $event): bool => ! in_array($event, $filteredEvents, true),
        ));

        $logs = collect();

        if ($directEvents !== []) {
            $logs = $logs->merge(
                AuditLog::query()
                    ->whereIn('user_id', $userIds)
                    ->whereIn('event', $directEvents)
                    ->where('created_at', '>=', $dayStart)
                    ->get(),
            );
        }

        if (in_array('whatsapp.template_sent', $allowlist, true)) {
            $logs = $logs->merge($this->manualWhatsAppAudits($userIds, $dayStart));
        }

        if (in_array('created', $allowlist, true)) {
            $logs = $logs->merge($this->manualRemarkCreatedAudits($userIds, $dayStart));
        }

        if (in_array('deleted', $allowlist, true)) {
            $logs = $logs->merge($this->manualRemarkDeletedAudits($userIds, $dayStart));
        }

        return $logs
            ->sortByDesc(static fn (AuditLog $log): array => [
                $log->created_at?->getTimestamp() ?? 0,
                (int) $log->id,
            ])
            ->values();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, AuditLog>
     */
    private function manualWhatsAppAudits(array $userIds, Carbon $dayStart): Collection
    {
        return AuditLog::query()
            ->whereIn('user_id', $userIds)
            ->where('event', 'whatsapp.template_sent')
            ->where('new_values->trigger_source', WhatsAppTemplateTriggerSource::Manual->value)
            ->where('created_at', '>=', $dayStart)
            ->get();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, AuditLog>
     */
    private function manualRemarkCreatedAudits(array $userIds, Carbon $dayStart): Collection
    {
        $remarkMorph = (new Remark)->getMorphClass();

        return AuditLog::query()
            ->whereIn('user_id', $userIds)
            ->where('event', 'created')
            ->where('auditable_type', $remarkMorph)
            ->where('created_at', '>=', $dayStart)
            ->where('new_values->origin', RemarkOrigin::Manual->value)
            ->get();
    }

    /**
     * @param  list<int>  $userIds
     * @return Collection<int, AuditLog>
     */
    private function manualRemarkDeletedAudits(array $userIds, Carbon $dayStart): Collection
    {
        $remarkMorph = (new Remark)->getMorphClass();

        return AuditLog::query()
            ->whereIn('user_id', $userIds)
            ->where('event', 'deleted')
            ->where('auditable_type', $remarkMorph)
            ->where('created_at', '>=', $dayStart)
            ->where('old_values->origin', RemarkOrigin::Manual->value)
            ->get();
    }

    private function dayStart(): Carbon
    {
        return Carbon::now()->startOfDay();
    }

    /**
     * @return list<string>
     */
    private function humanCountAllowlist(): array
    {
        $events = config('dashboard-team-activity.event_count_allowlist', []);

        return array_values(array_filter(
            is_array($events) ? $events : [],
            static fn (mixed $event): bool => is_string($event) && $event !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function iraCountAllowlist(): array
    {
        $events = config('dashboard-team-activity.ira_event_count_allowlist', []);

        return array_values(array_filter(
            is_array($events) ? $events : [],
            static fn (mixed $event): bool => is_string($event) && $event !== '',
        ));
    }

    /**
     * @return list<string>
     */
    private function iraActivityAllowlist(): array
    {
        $events = config('dashboard-team-activity.ira_event_allowlist', []);

        return array_values(array_filter(
            is_array($events) ? $events : [],
            static fn (mixed $event): bool => is_string($event) && $event !== '',
        ));
    }

    /**
     * @param  list<string>  $events
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function distinctCaseCountExpression(string $column, array $events, string $alias): array
    {
        if ($events === []) {
            return [
                'sql' => '0 as '.$alias,
                'bindings' => [],
            ];
        }

        $placeholders = implode(', ', array_fill(0, count($events), '?'));

        return [
            'sql' => "COUNT(DISTINCT CASE WHEN event IN ({$placeholders}) THEN {$column} END) as {$alias}",
            'bindings' => $events,
        ];
    }
}
