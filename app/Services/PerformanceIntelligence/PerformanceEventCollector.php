<?php

namespace App\Services\PerformanceIntelligence;

use App\Data\PerformanceIntelligence\PerformanceDayInputs;
use App\Enums\AttendanceDayStatus;
use App\Enums\IncidentStatus;
use App\Enums\RemarkOrigin;
use App\Enums\WhatsAppTemplateTriggerSource;
use App\Models\AuditLog;
use App\Models\BonvoiceCallEvent;
use App\Models\Remark;
use App\Models\User;
use App\Models\WorkforceAttendanceDay;
use App\Services\Operations\OperationsRoleService;
use App\Support\Dashboard\TeamActivityIncidentResolver;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Phase 0 input collector.
 *
 * Reuses KPI allowlists/config and TeamActivityIncidentResolver for Cases Worked.
 * Applies an exclusive day window [dayStart, dayEnd) so historical snapshots are stable.
 * Does not mutate attendance, payroll, or Team Activity KPIs.
 */
class PerformanceEventCollector
{
    public function __construct(
        private readonly TeamActivityIncidentResolver $incidentResolver,
        private readonly OperationsRoleService $roleService,
    ) {}

    /**
     * @return list<int>
     */
    public function trackedUserIds(): array
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn(
                'name',
                $this->roleService->attendanceTrackedRoleSlugs(),
            ))
            ->orderBy('id')
            ->pluck('id')
            ->map(fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, PerformanceDayInputs>
     */
    public function collectForUsers(array $userIds, Carbon $workDate): array
    {
        if ($userIds === []) {
            return [];
        }

        $dayStart = $workDate->copy()->startOfDay();
        $dayEnd = $dayStart->copy()->addDay();
        $dateString = $dayStart->toDateString();

        $casesWorked = $this->casesWorkedByUser($userIds, $dayStart, $dayEnd);
        $touch = $this->touchMetricsByUser($userIds, $dayStart, $dayEnd);
        $lifecycle = $this->lifecycleCountsByUser($userIds, $dayStart, $dayEnd);
        $refunds = $this->refundDecisionsByUser($userIds, $dayStart, $dayEnd);
        $assignEscalate = $this->assignEscalateByUser($userIds, $dayStart, $dayEnd);
        $calls = $this->answeredCallsByUser($userIds, $dayStart, $dayEnd);
        $attendance = $this->attendanceByUser($userIds, $dateString);

        $inputs = [];

        foreach ($userIds as $userId) {
            $touchRow = $touch[$userId] ?? $this->emptyTouch();
            $life = $lifecycle[$userId] ?? ['resolved' => 0, 'closed' => 0, 'reopen' => 0];
            $day = $attendance[$userId] ?? null;

            $inputs[$userId] = new PerformanceDayInputs(
                userId: $userId,
                workDate: $dateString,
                casesWorked: (int) ($casesWorked[$userId] ?? 0),
                customerTouches: (int) array_sum($touchRow),
                touchBreakdown: $touchRow,
                resolvedCount: (int) $life['resolved'],
                closedCount: (int) $life['closed'],
                reopenCount: (int) $life['reopen'],
                refundDecisionCount: (int) ($refunds[$userId] ?? 0),
                assignOrEscalateCount: (int) ($assignEscalate[$userId] ?? 0),
                answeredCallCount: (int) ($calls[$userId] ?? 0),
                attendanceExtra: $day?->status === AttendanceDayStatus::Extra,
                attendanceOnLeave: (bool) ($day?->is_on_leave ?? false),
                isCompanyHoliday: (bool) ($day?->is_company_holiday ?? false),
                isWorkingDay: (bool) ($day?->is_working_day ?? true),
                overtimeSeconds: (int) ($day?->overtime_seconds ?? 0),
                attendanceStatus: $day?->status?->value,
            );
        }

        return $inputs;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function casesWorkedByUser(array $userIds, Carbon $dayStart, Carbon $dayEnd): array
    {
        $allowlist = $this->stringList(config('dashboard-team-activity.event_count_allowlist', []));
        $filtered = ['created', 'deleted', 'whatsapp.template_sent'];
        $directEvents = array_values(array_filter(
            $allowlist,
            static fn (string $event): bool => ! in_array($event, $filtered, true),
        ));

        $query = $this->incidentResolver->casesWorkedRowsQuery(
            userIds: $userIds,
            dayStart: $dayStart,
            directEvents: $directEvents,
            includeManualWhatsApp: in_array('whatsapp.template_sent', $allowlist, true),
            includeManualRemarkCreated: in_array('created', $allowlist, true),
            includeManualRemarkDeleted: in_array('deleted', $allowlist, true),
        );

        if ($query === null) {
            return [];
        }

        $query->where('al.created_at', '<', $dayEnd);

        ['sql' => $incidentIdSql, 'bindings' => $bindings] = $this->invokeIncidentIdExpression();

        $counts = [];

        foreach (
            $query
                ->select('al.user_id')
                ->selectRaw("COUNT(DISTINCT {$incidentIdSql}) as case_count", $bindings)
                ->groupBy('al.user_id')
                ->havingRaw("COUNT(DISTINCT {$incidentIdSql}) > 0", $bindings)
                ->pluck('case_count', 'al.user_id') as $userId => $aggregate
        ) {
            $counts[(int) $userId] = (int) $aggregate;
        }

        return $counts;
    }

    /**
     * Reflect private incidentIdExpression via distinctCaseCounts path —
     * use the public resolver API by counting through a thin wrap.
     *
     * @return array{sql: string, bindings: list<mixed>}
     */
    private function invokeIncidentIdExpression(): array
    {
        // TeamActivityIncidentResolver::incidentIdExpression is private; reuse
        // distinctCaseCountsForUsers for the common case and re-query with dayEnd
        // via reflection-free duplicate of COALESCE using public morph helpers.
        $incidentMorph = $this->incidentResolver->incidentMorph();
        $orderMorph = $this->incidentResolver->orderMorph();
        $remarkMorph = $this->incidentResolver->remarkMorph();
        $driver = \Illuminate\Support\Facades\DB::connection()->getDriverName();
        $jsonIncidentId = $driver === 'sqlite'
            ? "CAST(json_extract(al.new_values, '$.incident_id') AS INTEGER)"
            : "CAST(JSON_UNQUOTE(JSON_EXTRACT(al.new_values, '$.incident_id')) AS UNSIGNED)";
        $latestOrderIncident = '(SELECT i.id FROM incidents i WHERE i.order_id = al.auditable_id ORDER BY i.updated_at DESC, i.id DESC LIMIT 1)';
        $latestRemarkOrderIncident = '(SELECT i.id FROM incidents i WHERE i.order_id = r.remarkable_id ORDER BY i.updated_at DESC, i.id DESC LIMIT 1)';

        return [
            'sql' => <<<SQL
COALESCE(
    CASE WHEN al.auditable_type = ? THEN al.auditable_id END,
    {$jsonIncidentId},
    CASE WHEN al.auditable_type = ? AND r.remarkable_type = ? THEN r.remarkable_id END,
    CASE WHEN al.auditable_type = ? AND r.remarkable_type = ? THEN {$latestRemarkOrderIncident} END,
    rr.incident_id,
    ai.incident_id,
    CASE WHEN al.auditable_type = ? THEN {$latestOrderIncident} END
)
SQL,
            'bindings' => [
                $incidentMorph,
                $remarkMorph,
                $incidentMorph,
                $remarkMorph,
                $orderMorph,
                $orderMorph,
            ],
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array<string, int>>
     */
    private function touchMetricsByUser(array $userIds, Carbon $dayStart, Carbon $dayEnd): array
    {
        $counts = array_fill_keys($userIds, $this->emptyTouch());
        $eventMap = config('operations-kpi.support.effort_events', []);

        $statusEvents = $this->stringList($eventMap['status_updates'] ?? ['service_case.status_changed']);
        $emailEvents = $this->stringList($eventMap['emails'] ?? ['notification.dispatched', 'communication_action.lifecycle']);

        foreach ($this->countEvents($userIds, $dayStart, $dayEnd, $statusEvents) as $userId => $count) {
            $counts[$userId]['status_updates'] = $count;
        }

        foreach ($this->countEvents($userIds, $dayStart, $dayEnd, $emailEvents) as $userId => $count) {
            $counts[$userId]['emails'] = $count;
        }

        foreach ($this->manualWhatsAppCounts($userIds, $dayStart, $dayEnd) as $userId => $count) {
            $counts[$userId]['whatsapp'] = $count;
        }

        foreach ($this->manualRemarkCounts($userIds, $dayStart, $dayEnd) as $userId => $count) {
            $counts[$userId]['remarks'] = $count;
        }

        // calls filled separately (answered only) — keep raw CT-style call slot at 0 here
        return $counts;
    }

    /**
     * @return array<string, int>
     */
    private function emptyTouch(): array
    {
        return [
            'calls' => 0,
            'whatsapp' => 0,
            'emails' => 0,
            'remarks' => 0,
            'status_updates' => 0,
        ];
    }

    /**
     * @param  list<int>  $userIds
     * @param  list<string>  $events
     * @return array<int, int>
     */
    private function countEvents(array $userIds, Carbon $dayStart, Carbon $dayEnd, array $events): array
    {
        if ($events === []) {
            return [];
        }

        return AuditLog::query()
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->whereIn('user_id', $userIds)
            ->whereIn('event', $events)
            ->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayEnd)
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function manualWhatsAppCounts(array $userIds, Carbon $dayStart, Carbon $dayEnd): array
    {
        return AuditLog::query()
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->whereIn('user_id', $userIds)
            ->where('event', 'whatsapp.template_sent')
            ->where('new_values->trigger_source', WhatsAppTemplateTriggerSource::Manual->value)
            ->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayEnd)
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function manualRemarkCounts(array $userIds, Carbon $dayStart, Carbon $dayEnd): array
    {
        $remarkMorph = (new Remark)->getMorphClass();
        $counts = array_fill_keys($userIds, 0);

        foreach (
            AuditLog::query()
                ->selectRaw('user_id, COUNT(*) as aggregate')
                ->whereIn('user_id', $userIds)
                ->where('event', 'created')
                ->where('auditable_type', $remarkMorph)
                ->where('new_values->origin', RemarkOrigin::Manual->value)
                ->where('created_at', '>=', $dayStart)
                ->where('created_at', '<', $dayEnd)
                ->groupBy('user_id')
                ->pluck('aggregate', 'user_id') as $userId => $count
        ) {
            $counts[(int) $userId] += (int) $count;
        }

        // Note deletes intentionally excluded from PI contribution inputs (Phase 0 / blueprint).

        return $counts;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, array{resolved: int, closed: int, reopen: int}>
     */
    private function lifecycleCountsByUser(array $userIds, Carbon $dayStart, Carbon $dayEnd): array
    {
        $rows = AuditLog::query()
            ->whereIn('user_id', $userIds)
            ->where('event', 'service_case.status_changed')
            ->where('created_at', '>=', $dayStart)
            ->where('created_at', '<', $dayEnd)
            ->get(['user_id', 'old_values', 'new_values']);

        $out = array_fill_keys($userIds, ['resolved' => 0, 'closed' => 0, 'reopen' => 0]);

        foreach ($rows as $row) {
            $userId = (int) $row->user_id;
            $old = is_array($row->old_values) ? ($row->old_values['status'] ?? null) : null;
            $new = is_array($row->new_values) ? ($row->new_values['status'] ?? null) : null;

            if ($new === IncidentStatus::Resolved->value) {
                $out[$userId]['resolved']++;
            }

            if ($new === IncidentStatus::Closed->value) {
                $out[$userId]['closed']++;
            }

            if ($old === IncidentStatus::Closed->value && $new === IncidentStatus::Open->value) {
                $out[$userId]['reopen']++;
            }
        }

        return $out;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function refundDecisionsByUser(array $userIds, Carbon $dayStart, Carbon $dayEnd): array
    {
        return $this->countEvents($userIds, $dayStart, $dayEnd, [
            'refund.approved',
            'refund.rejected',
            'refund.completed',
        ]);
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function assignEscalateByUser(array $userIds, Carbon $dayStart, Carbon $dayEnd): array
    {
        return $this->countEvents($userIds, $dayStart, $dayEnd, [
            'service_case.assigned',
            'service_case.reassigned',
            'service_case.escalated',
        ]);
    }

    /**
     * Answered/completed Bonvoice calls only (blueprint: not all legs).
     *
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function answeredCallsByUser(array $userIds, Carbon $dayStart, Carbon $dayEnd): array
    {
        $users = User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('bonvoice_extension')
            ->get(['id', 'bonvoice_extension']);

        if ($users->isEmpty()) {
            return [];
        }

        $events = BonvoiceCallEvent::query()
            ->where('started_at', '>=', $dayStart)
            ->where('started_at', '<', $dayEnd)
            ->get(['id', 'call_id', 'destination_number', 'source_number', 'callback_params', 'status'])
            ->filter(function (BonvoiceCallEvent $event): bool {
                $status = strtoupper((string) $event->status);

                return in_array($status, ['ANSWERED', 'COMPLETED'], true);
            });

        $counts = array_fill_keys($userIds, 0);
        $seen = [];

        foreach ($events as $event) {
            $userId = $this->resolveCallUserId($event, $users);

            if ($userId === null || ! isset($counts[$userId])) {
                continue;
            }

            $dedupeKey = $userId.':'.$event->call_id;

            if (isset($seen[$dedupeKey])) {
                continue;
            }

            $seen[$dedupeKey] = true;
            $counts[$userId]++;
        }

        return $counts;
    }

    /**
     * @param  Collection<int, User>  $users
     */
    private function resolveCallUserId(BonvoiceCallEvent $event, Collection $users): ?int
    {
        $callbackParams = is_array($event->callback_params) ? $event->callback_params : [];
        $callbackUserId = (int) ($callbackParams['user_id'] ?? 0);

        if ($callbackUserId > 0 && $users->contains('id', $callbackUserId)) {
            return $callbackUserId;
        }

        foreach ([$event->destination_number, $event->source_number] as $phone) {
            if (! filled($phone)) {
                continue;
            }

            foreach ($users as $user) {
                if ($this->phoneNumbersMatch((string) $user->bonvoice_extension, (string) $phone)) {
                    return (int) $user->id;
                }
            }
        }

        return null;
    }

    private function phoneNumbersMatch(string $left, string $right): bool
    {
        $normalize = static function (string $value): string {
            $digits = preg_replace('/\D+/', '', $value) ?? '';

            if (strlen($digits) > 10) {
                return substr($digits, -10);
            }

            return $digits;
        };

        $leftDigits = $normalize($left);
        $rightDigits = $normalize($right);

        return $leftDigits !== '' && $leftDigits === $rightDigits;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, WorkforceAttendanceDay|null>
     */
    private function attendanceByUser(array $userIds, string $dateString): array
    {
        $days = WorkforceAttendanceDay::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('work_date', $dateString)
            ->get()
            ->keyBy(fn (WorkforceAttendanceDay $day): int => (int) $day->user_id);

        $map = [];

        foreach ($userIds as $userId) {
            $map[$userId] = $days->get($userId);
        }

        return $map;
    }

    /**
     * @param  mixed  $value
     * @return list<string>
     */
    private function stringList(mixed $value): array
    {
        if (! is_array($value)) {
            return [];
        }

        return array_values(array_filter(
            $value,
            static fn (mixed $item): bool => is_string($item) && $item !== '',
        ));
    }
}
