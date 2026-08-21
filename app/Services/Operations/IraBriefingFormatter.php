<?php

namespace App\Services\Operations;

use App\Data\Operations\IraBriefingFormatted;
use App\Data\Operations\IraMorningBriefing;
use App\Data\Operations\IraOperationalRecommendation;
use App\Data\Operations\IraOperationalRisk;
use App\Data\Operations\IraOperationalSnapshotData;
use App\Enums\AI\AIRiskLevel;
use App\Models\User;
use Illuminate\Support\Carbon;

class IraBriefingFormatter
{
    private const TELEGRAM_MAX_LENGTH = 900;

    public function __construct(
        private readonly OperationsRoleService $roleService,
        private readonly WorkCalendarService $workCalendarService,
    ) {}

    public function format(
        IraMorningBriefing $briefing,
        ?string $recipientFirstName = null,
        ?Carbon $at = null,
    ): IraBriefingFormatted {
        $at = $this->appNow($at);
        $greeting = $this->greeting($recipientFirstName, $at);
        $operationsLines = $this->operationsLines($briefing->snapshot);
        $teamPresenceCollecting = ! $this->hasPresenceData($briefing->snapshot);
        $teamLines = $this->teamLines($briefing->snapshot, $teamPresenceCollecting);
        $riskCounts = $this->classifyRisks($briefing->risks, $briefing->snapshot);
        $attentionLines = $this->attentionLines($briefing, $riskCounts);
        $suggestion = $this->selectSuggestion($briefing->recommendations, $briefing->risks);

        $telegramMessage = $this->buildTelegramMessage(
            greeting: $greeting,
            operationsLines: $operationsLines,
            teamPresenceCollecting: $teamPresenceCollecting,
            teamLines: $teamLines,
            attentionLines: $attentionLines,
            suggestion: $suggestion,
        );

        return new IraBriefingFormatted(
            greeting: $greeting,
            operationsLines: $operationsLines,
            teamPresenceCollecting: $teamPresenceCollecting,
            teamLines: $teamLines,
            attentionLines: $attentionLines,
            suggestion: $suggestion,
            telegramMessage: $telegramMessage,
            criticalRiskCount: $riskCounts['critical'],
            attentionRiskCount: $riskCounts['attention'],
            monitoringRiskCount: $riskCounts['monitoring'],
        );
    }

    public function greeting(?string $firstName = null, ?Carbon $at = null): string
    {
        $at = $this->appNow($at);
        $hour = (int) $at->format('G');

        $timeGreeting = match (true) {
            $hour < 12 => 'Good morning',
            $hour < 17 => 'Good afternoon',
            default => 'Good evening',
        };

        $name = trim((string) $firstName);

        if ($name !== '') {
            return "{$timeGreeting} {$name}.";
        }

        return "{$timeGreeting}.";
    }

    /**
     * @return list<string>
     */
    public function operationsLines(IraOperationalSnapshotData $snapshot): array
    {
        $operations = $snapshot->operations;

        return [
            'Ready Queue: '.(int) ($operations['action_required'] ?? $operations['open_cases'] ?? 0),
            'Scheduled Today: '.(int) ($operations['scheduled_today'] ?? $operations['support']['today']['scheduled'] ?? 0),
            'Customer Waiting: '.(int) ($operations['waiting'] ?? 0),
        ];
    }

    public function hasPresenceData(IraOperationalSnapshotData $snapshot): bool
    {
        $team = $snapshot->team;

        if ((int) ($team['average_active_seconds'] ?? 0) > 0) {
            return true;
        }

        $members = $this->operationalTeamMembers();

        if ($members->isEmpty()) {
            return false;
        }

        return $members->contains(
            fn (User $user): bool => $this->workCalendarService->scheduleFor($user, now()) !== null,
        );
    }

    /**
     * @return list<string>
     */
    public function teamLines(IraOperationalSnapshotData $snapshot, bool $presenceCollecting): array
    {
        if ($presenceCollecting) {
            return ['Presence data collecting'];
        }

        $team = $snapshot->team;
        $working = (int) ($team['available'] ?? 0);
        $onLeave = (int) ($team['leave'] ?? 0);

        return [
            $working === 1 ? '1 working' : "{$working} working",
            $onLeave === 1 ? '1 on leave' : "{$onLeave} on leave",
        ];
    }

    /**
     * @param  list<IraOperationalRisk>  $risks
     * @return array{critical: int, attention: int, monitoring: int}
     */
    public function classifyRisks(array $risks, IraOperationalSnapshotData $snapshot): array
    {
        $operations = $snapshot->operations;
        $critical = (int) ($operations['overdue'] ?? 0);
        $monitoring = (int) ($operations['warning'] ?? 0);
        $attention = 0;

        foreach ($risks as $risk) {
            if ($risk->key === 'customer.sla_danger') {
                continue;
            }

            match ($risk->severity) {
                AIRiskLevel::High => $critical++,
                AIRiskLevel::Medium => $attention++,
                AIRiskLevel::Low => $monitoring++,
            };
        }

        return [
            'critical' => $critical,
            'attention' => $attention,
            'monitoring' => $monitoring,
        ];
    }

    /**
     * @param  array{critical: int, attention: int, monitoring: int}  $riskCounts
     * @return list<string>
     */
    public function attentionLines(IraMorningBriefing $briefing, array $riskCounts): array
    {
        $operations = $briefing->snapshot->operations;
        $lines = [];

        $actionRequired = (int) ($operations['action_required'] ?? 0);
        $attentionQueue = (int) ($operations['attention'] ?? 0);
        $casesNeedAction = $actionRequired + $attentionQueue;
        $waiting = (int) ($operations['waiting'] ?? 0);
        $overdue = (int) ($operations['overdue'] ?? 0);
        $warning = (int) ($operations['warning'] ?? 0);

        if ($overdue > 0) {
            $lines[] = $overdue === 1
                ? '1 case requires action'
                : "{$overdue} cases require action";
        } elseif ($casesNeedAction > 0) {
            $lines[] = $casesNeedAction === 1
                ? '1 case needs action today'
                : "{$casesNeedAction} cases need action today";
        } elseif ($riskCounts['critical'] > 0) {
            $lines[] = $riskCounts['critical'] === 1
                ? '1 requires action'
                : "{$riskCounts['critical']} require action";
        }

        if ($warning > 0) {
            $lines[] = $warning === 1
                ? '1 being monitored'
                : "{$warning} being monitored";
        } elseif ($riskCounts['monitoring'] > 0) {
            $lines[] = $riskCounts['monitoring'] === 1
                ? '1 being monitored'
                : "{$riskCounts['monitoring']} being monitored";
        }

        if ($riskCounts['attention'] > 0) {
            $lines[] = $riskCounts['attention'] === 1
                ? '1 should review'
                : "{$riskCounts['attention']} should review";
        }

        if ($waiting > 0) {
            $lines[] = $waiting === 1
                ? '1 waiting customer follow-up'
                : "{$waiting} waiting customer follow-ups";
        }

        return array_slice($lines, 0, 3);
    }

    /**
     * @param  list<IraOperationalRecommendation>  $recommendations
     * @param  list<IraOperationalRisk>  $risks
     */
    public function selectSuggestion(array $recommendations, array $risks): ?string
    {
        $recommendation = $this->selectTopRecommendation($recommendations, $risks);

        if ($recommendation === null) {
            return null;
        }

        return $this->suggestionText($recommendation);
    }

    /**
     * @param  list<IraOperationalRecommendation>  $recommendations
     * @param  list<IraOperationalRisk>  $risks
     */
    public function selectTopRecommendation(array $recommendations, array $risks): ?IraOperationalRecommendation
    {
        if ($recommendations === []) {
            return null;
        }

        $priorityPrefixes = [
            'waiting.',
            'sla.',
            'trend.',
            'capacity.',
        ];

        foreach ($priorityPrefixes as $prefix) {
            foreach ($recommendations as $recommendation) {
                if (str_starts_with($recommendation->key, $prefix)) {
                    return $recommendation;
                }
            }
        }

        return $recommendations[0];
    }

    /**
     * @param  list<string>  $operationsLines
     * @param  list<string>  $teamLines
     * @param  list<string>  $attentionLines
     */
    private function buildTelegramMessage(
        string $greeting,
        array $operationsLines,
        bool $teamPresenceCollecting,
        array $teamLines,
        array $attentionLines,
        ?string $suggestion,
    ): string {
        $sections = [
            $greeting,
            '',
            "📊 Operations\n".$this->bulletLines($operationsLines),
        ];

        if ($teamPresenceCollecting) {
            $sections[] = "\n👥 Team\n• Presence data collecting";
        } else {
            $sections[] = "\n👥 Team\n".$this->bulletLines($this->telegramTeamLines($teamLines));
        }

        if ($attentionLines !== []) {
            $sections[] = "\n⚠️ Attention\n".$this->bulletLines($attentionLines);
        }

        if ($suggestion !== null) {
            $sections[] = "\n💡 Ira Suggestion\n{$suggestion}";
        }

        $message = implode('', $sections);

        if (strlen($message) > self::TELEGRAM_MAX_LENGTH) {
            $message = substr($message, 0, self::TELEGRAM_MAX_LENGTH - 3).'...';
        }

        return $message;
    }

    /**
     * @param  list<string>  $lines
     */
    private function bulletLines(array $lines): string
    {
        return implode("\n", array_map(
            fn (string $line): string => '• '.$this->telegramLabel($line),
            $lines,
        ));
    }

    private function telegramLabel(string $line): string
    {
        if (! str_contains($line, ': ')) {
            return $line;
        }

        [$label, $value] = explode(': ', $line, 2);

        return "{$label}: {$value}";
    }

    /**
     * @param  list<string>  $teamLines
     * @return list<string>
     */
    private function telegramTeamLines(array $teamLines): array
    {
        return array_map(function (string $line): string {
            if (preg_match('/^(\d+) working$/', $line, $matches) === 1) {
                return 'Working Now: '.$matches[1];
            }

            if (preg_match('/^(\d+) on leave$/', $line, $matches) === 1) {
                return 'On Leave: '.$matches[1];
            }

            return $line;
        }, $teamLines);
    }

    private function suggestionText(IraOperationalRecommendation $recommendation): string
    {
        if (str_starts_with($recommendation->key, 'trend.product.')) {
            $prefix = (string) ($recommendation->context['prefix'] ?? 'pending');

            return "Review {$prefix} pending cases first.";
        }

        if (str_starts_with($recommendation->key, 'sla.')) {
            $overdue = (int) ($recommendation->context['overdue'] ?? 0);

            return $overdue > 0
                ? 'Prioritize overdue cases first.'
                : 'Review warning cases before SLA breach.';
        }

        if (str_starts_with($recommendation->key, 'waiting.')) {
            return 'Follow up on long-waiting customers.';
        }

        if (str_starts_with($recommendation->key, 'capacity.')) {
            return 'Assign open work to available team members.';
        }

        if (str_starts_with($recommendation->key, 'trend.')) {
            return 'Review rising open-case workload.';
        }

        return rtrim($recommendation->message, '.').'.';
    }

    public function formatOpsDigest(
        IraMorningBriefing $briefing,
        string $period,
        array $digestContext = [],
        ?string $recipientFirstName = null,
        ?Carbon $at = null,
    ): string {
        $at = $this->appNow($at);
        $greeting = $this->greeting($recipientFirstName, $at);
        $normalizedPeriod = in_array($period, ['morning', 'open'], true) ? 'morning' : 'evening';
        $title = $normalizedPeriod === 'morning'
            ? 'Morning Operations Summary'
            : 'Evening Operations Summary';

        $team = is_array($digestContext['team'] ?? null) ? $digestContext['team'] : [];
        $operations = is_array($digestContext['operations'] ?? null)
            ? $digestContext['operations']
            : $briefing->snapshot->operations;
        $refunds = is_array($digestContext['refunds'] ?? null) ? $digestContext['refunds'] : [];
        $overloadLines = is_array($digestContext['overload_lines'] ?? null) ? $digestContext['overload_lines'] : [];

        $sections = [
            $greeting,
            '',
            $title,
            '',
            '👥 Team',
            $this->bulletLines([
                'Present: '.$this->nameSummary($team['present'] ?? []),
                'Absent: '.$this->nameSummary($team['absent'] ?? []),
                'On leave: '.$this->nameSummary($team['on_leave'] ?? []),
                'Pending leave approvals: '.(int) ($team['pending_leave_approvals'] ?? 0),
            ]),
        ];

        $lateLines = $this->lateArrivalLines($team['late_arrivals'] ?? []);
        if ($lateLines !== []) {
            $sections[] = '';
            $sections[] = '⏰ Late arrivals';
            $sections[] = $this->bulletLines($lateLines);
        }

        $shortfallLines = $this->attendanceShortfallLines($team['attendance_shortfalls'] ?? []);
        if ($shortfallLines !== []) {
            $sections[] = '';
            $sections[] = '⌛ Attendance shortfall';
            $sections[] = $this->bulletLines($shortfallLines);
        }

        $sections[] = '';
        $sections[] = '📊 Operations';
        $sections[] = $this->bulletLines([
            'Open cases: '.(int) ($operations['open_cases'] ?? 0),
            'SLA risk: '.(int) ($operations['overdue'] ?? 0).' overdue, '.(int) ($operations['warning'] ?? 0).' warning',
            'Waiting backlog: '.(int) ($operations['waiting'] ?? 0),
            'Missed appointments: '.(int) ($operations['missed_appointments'] ?? 0),
            'Unassigned scheduled: '.(int) ($operations['unassigned_scheduled'] ?? 0),
        ]);

        if ($normalizedPeriod === 'evening') {
            $sections[] = $this->bulletLines([
                'Unassigned important: '.(int) ($operations['unassigned_important'] ?? 0),
                'Escalations pending: '.(int) ($operations['escalations_pending'] ?? 0),
            ]);
        }

        $sections[] = $this->bulletLines([
            'Refunds: '.(int) ($refunds['pending_approval'] ?? 0).' pending approval, '
                .(int) ($refunds['pending_execution'] ?? 0).' pending execution, '
                .(int) ($refunds['submitted_today'] ?? 0).' submitted today',
        ]);

        if ($overloadLines !== []) {
            $sections[] = '';
            $sections[] = 'Overloaded agents';
            $sections[] = $this->bulletLines(array_slice($overloadLines, 0, 5));
        }

        if ($normalizedPeriod === 'evening') {
            $unresolved = array_values(array_filter([
                (int) ($operations['escalations_pending'] ?? 0) > 0
                    ? (int) $operations['escalations_pending'].' escalation(s) pending'
                    : null,
                (int) ($operations['unassigned_important'] ?? 0) > 0
                    ? (int) $operations['unassigned_important'].' unassigned important case(s)'
                    : null,
                (int) ($operations['unassigned_scheduled'] ?? 0) > 0
                    ? (int) $operations['unassigned_scheduled'].' unassigned scheduled case(s)'
                    : null,
            ]));

            if ($unresolved !== []) {
                $sections[] = '';
                $sections[] = 'Needs handover';
                $sections[] = $this->bulletLines($unresolved);
            }
        }

        $message = implode("\n", $sections);

        if (strlen($message) > self::TELEGRAM_MAX_LENGTH) {
            $message = substr($message, 0, self::TELEGRAM_MAX_LENGTH - 3).'...';
        }

        return $message;
    }

    /**
     * @param  list<string>  $names
     */
    private function nameSummary(array $names): string
    {
        if ($names === []) {
            return 'none';
        }

        if (count($names) <= 3) {
            return implode(', ', $names);
        }

        $visible = array_slice($names, 0, 3);

        return implode(', ', $visible).' +'.(count($names) - 3).' more';
    }

    /**
     * @param  list<array<string, mixed>>  $lateArrivals
     * @return list<string>
     */
    private function lateArrivalLines(array $lateArrivals): array
    {
        $lines = [];

        foreach ($lateArrivals as $entry) {
            $name = (string) ($entry['name'] ?? 'Unknown');
            $minutesLate = (int) ($entry['minutes_late'] ?? 0);
            $loginAt = trim((string) ($entry['login_at'] ?? ''));
            $lateDays = (int) ($entry['late_days_in_window'] ?? 0);
            $evaluatedDays = (int) ($entry['evaluated_days'] ?? 0);

            $line = "{$name} — late {$minutesLate} min";

            if ($loginAt !== '') {
                $line .= " (login {$loginAt})";
            }

            if ($evaluatedDays >= 3 && $lateDays >= 3) {
                $line .= "; late on {$lateDays} of last {$evaluatedDays} working days";
            }

            $lines[] = $line;
        }

        return $lines;
    }

    /**
     * @param  list<array<string, mixed>>  $shortfalls
     * @return list<string>
     */
    private function attendanceShortfallLines(array $shortfalls): array
    {
        $lines = [];

        foreach ($shortfalls as $entry) {
            $name = (string) ($entry['name'] ?? 'Unknown');
            $minutes = (int) ($entry['shortfall_minutes'] ?? 0);

            if ($minutes <= 0) {
                continue;
            }

            $lines[] = "{$name} — expected shortfall {$minutes} min";
        }

        return $lines;
    }

    private function appNow(?Carbon $at): Carbon
    {
        return ($at ?? now())->timezone(config('app.timezone'));
    }

    /**
     * @return \Illuminate\Support\Collection<int, User>
     */
    private function operationalTeamMembers()
    {
        return User::query()
            ->where('is_active', true)
            ->whereHas('roles', fn ($query) => $query->whereIn('name', $this->roleService->operationalRoleSlugs()))
            ->get();
    }
}
