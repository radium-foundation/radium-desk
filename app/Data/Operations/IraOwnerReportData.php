<?php

namespace App\Data\Operations;

/**
 * Structured owner intelligence input for rule-based formatting and future AI narrative providers.
 *
 * @phpstan-type NameList list<string>
 */
readonly class IraOwnerReportData
{
    /**
     * @param  'morning'|'evening'  $period
     * @param  array{
     *     present: NameList,
     *     absent: NameList,
     *     on_leave: NameList,
     *     late_arrivals: NameList,
     *     pending_leave_approvals: int,
     *     pending_leave_requesters: NameList,
     * }  $team
     * @param  array{
     *     open_cases: int,
     *     sla_overdue: int,
     *     sla_warning: int,
     *     overdue_cases: int,
     *     escalations_pending: int,
     *     unassigned_important: int,
     *     waiting_customers: int,
     *     cases_created: int,
     *     cases_closed: int,
     *     escalated_today: int,
     * }  $operations
     * @param  array{
     *     unresolved_carry_forward: int,
     *     critical_events: list<string>,
     * }  $previousDay
     * @param  array{
     *     on_time_logins: int,
     *     late_logins: int,
     *     manual_logouts: int,
     *     timeout_events: int,
     *     extra_working_members: list<array{name: string, overtime_label: string}>,
     *     away_timeout_members: list<array{name: string, timeout_count: int}>,
     * }  $attendance
     * @param  array{
     *     highlights: list<array{name: string, metric: string, value: int|string}>,
     *     bottlenecks: list<array{name: string, metric: string, value: int|string}>,
     * }  $people
     */
    public function __construct(
        public string $date,
        public string $period,
        public array $team,
        public array $operations,
        public array $previousDay,
        public array $attendance,
        public array $people,
        public IraOperationalSnapshotData $snapshot,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'date' => $this->date,
            'period' => $this->period,
            'team' => $this->team,
            'operations' => $this->operations,
            'previous_day' => $this->previousDay,
            'attendance' => $this->attendance,
            'people' => $this->people,
            'snapshot' => $this->snapshot->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function fromArray(array $payload): self
    {
        return new self(
            date: (string) ($payload['date'] ?? now()->toDateString()),
            period: (string) ($payload['period'] ?? 'morning'),
            team: is_array($payload['team'] ?? null) ? $payload['team'] : [],
            operations: is_array($payload['operations'] ?? null) ? $payload['operations'] : [],
            previousDay: is_array($payload['previous_day'] ?? null) ? $payload['previous_day'] : [],
            attendance: is_array($payload['attendance'] ?? null) ? $payload['attendance'] : [],
            people: is_array($payload['people'] ?? null) ? $payload['people'] : [],
            snapshot: IraOperationalSnapshotData::fromArray(is_array($payload['snapshot'] ?? null) ? $payload['snapshot'] : []),
        );
    }
}
