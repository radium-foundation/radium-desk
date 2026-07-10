<?php

namespace App\Services\Operations;

use App\Data\Operations\IraOwnerReportData;
use Illuminate\Support\Carbon;

class IraOwnerReportFormatter
{
    public const TELEGRAM_MAX_LENGTH = 900;

    public function __construct(
        private readonly IraBriefingFormatter $briefingFormatter,
    ) {}

    /**
     * @return list<string>
     */
    public function formatTelegramMessages(
        IraOwnerReportData $report,
        ?string $recipientFirstName = null,
        ?Carbon $at = null,
    ): array {
        $at = ($at ?? now())->timezone(config('app.timezone'));
        $greeting = $this->briefingFormatter->greeting($recipientFirstName, $at);

        $sections = $report->period === 'evening'
            ? $this->eveningSections($report, $greeting)
            : $this->morningSections($report, $greeting);

        return $this->splitIntoTelegramParts($sections);
    }

    /**
     * @return list<string>
     */
    private function morningSections(IraOwnerReportData $report, string $greeting): array
    {
        $team = $report->team;
        $operations = $report->operations;
        $previousDay = $report->previousDay;

        $sections = [
            $greeting,
            '',
            '📋 Owner Intelligence — Morning',
            '',
            '👥 Team',
            $this->bullet('Present: '.$this->nameSummary($team['present'] ?? [])),
            $this->bullet('Absent: '.$this->nameSummary($team['absent'] ?? [])),
            $this->bullet('On leave: '.$this->nameSummary($team['on_leave'] ?? [])),
            $this->bullet('Late arrivals: '.$this->nameSummary($team['late_arrivals'] ?? [])),
            $this->bullet('Pending leave approvals: '.(int) ($team['pending_leave_approvals'] ?? 0)),
            '',
            '📊 Operations',
            $this->bullet('Open cases: '.(int) ($operations['open_cases'] ?? 0)),
            $this->bullet('SLA risk: '.(int) ($operations['sla_overdue'] ?? 0).' overdue, '.(int) ($operations['sla_warning'] ?? 0).' warning'),
            $this->bullet('Overdue cases: '.(int) ($operations['overdue_cases'] ?? 0)),
            $this->bullet('Escalations pending: '.(int) ($operations['escalations_pending'] ?? 0)),
            $this->bullet('Unassigned important: '.(int) ($operations['unassigned_important'] ?? 0)),
        ];

        if ((int) ($operations['waiting_customers'] ?? 0) > 0) {
            $sections[] = $this->bullet('Waiting customers: '.(int) $operations['waiting_customers']);
        }

        $sections[] = '';
        $sections[] = '📅 Previous day';
        $sections[] = $this->bullet('Unresolved carry forward: '.(int) ($previousDay['unresolved_carry_forward'] ?? 0));

        $criticalEvents = $previousDay['critical_events'] ?? [];

        if ($criticalEvents === []) {
            $sections[] = $this->bullet('Critical events: none flagged');
        } else {
            foreach ($criticalEvents as $event) {
                $sections[] = $this->bullet((string) $event);
            }
        }

        return $sections;
    }

    /**
     * @return list<string>
     */
    private function eveningSections(IraOwnerReportData $report, string $greeting): array
    {
        $team = $report->team;
        $operations = $report->operations;
        $attendance = $report->attendance;
        $people = $report->people;

        $sections = [
            $greeting,
            '',
            '📈 Owner Performance — Evening',
            '',
            '👥 Team discipline',
            $this->bullet('On-time logins: '.(int) ($attendance['on_time_logins'] ?? 0)),
            $this->bullet('Late logins: '.(int) ($attendance['late_logins'] ?? 0)),
            $this->bullet('Manual logouts: '.(int) ($attendance['manual_logouts'] ?? 0)),
            $this->bullet('Away timeouts: '.(int) ($attendance['timeout_events'] ?? 0)),
            $this->bullet('Present today: '.$this->nameSummary($team['present'] ?? [])),
            $this->bullet('On leave: '.$this->nameSummary($team['on_leave'] ?? [])),
        ];

        $extraWorking = $attendance['extra_working_members'] ?? [];

        if ($extraWorking !== []) {
            $sections[] = '';
            $sections[] = '⏱ Extra working';
            foreach ($extraWorking as $member) {
                $sections[] = $this->bullet(
                    ($member['name'] ?? 'Unknown').': '.($member['overtime_label'] ?? '—'),
                );
            }
        }

        $awayTimeouts = $attendance['away_timeout_members'] ?? [];

        if ($awayTimeouts !== []) {
            $sections[] = '';
            $sections[] = '⏸ Away / timeout';
            foreach ($awayTimeouts as $member) {
                $sections[] = $this->bullet(
                    ($member['name'] ?? 'Unknown').': '.(int) ($member['timeout_count'] ?? 0).' timeout(s)',
                );
            }
        }

        $sections[] = '';
        $sections[] = '📊 Operations';
        $sections[] = $this->bullet('Cases created: '.(int) ($operations['cases_created'] ?? 0));
        $sections[] = $this->bullet('Cases closed: '.(int) ($operations['cases_closed'] ?? 0));
        $sections[] = $this->bullet('Pending cases: '.(int) ($operations['open_cases'] ?? 0));
        $sections[] = $this->bullet('Escalated today: '.(int) ($operations['escalated_today'] ?? 0));
        $sections[] = $this->bullet('SLA risk: '.(int) ($operations['sla_overdue'] ?? 0).' overdue, '.(int) ($operations['sla_warning'] ?? 0).' warning');

        if ((int) ($operations['waiting_customers'] ?? 0) > 0) {
            $sections[] = $this->bullet('Customer waiting: '.(int) $operations['waiting_customers']);
        }

        $highlights = $people['highlights'] ?? [];
        $bottlenecks = $people['bottlenecks'] ?? [];

        $sections[] = '';
        $sections[] = '👤 People';

        if ($highlights === []) {
            $sections[] = $this->bullet('Highlights: steady day');
        } else {
            $sections[] = 'Highlights:';
            foreach ($highlights as $item) {
                $sections[] = $this->bullet(
                    ($item['name'] ?? 'Unknown').' — '.(int) ($item['value'] ?? 0).' '.($item['metric'] ?? ''),
                );
            }
        }

        if ($bottlenecks === []) {
            $sections[] = $this->bullet('Bottlenecks: none flagged');
        } else {
            $sections[] = 'Needs attention:';
            foreach ($bottlenecks as $item) {
                $sections[] = $this->bullet(
                    ($item['name'] ?? 'Unknown').' — '.(int) ($item['value'] ?? 0).' '.($item['metric'] ?? ''),
                );
            }
        }

        return $sections;
    }

    /**
     * @param  list<string>  $lines
     * @return list<string>
     */
    private function splitIntoTelegramParts(array $lines): array
    {
        $message = implode("\n", $lines);
        $maxLength = self::TELEGRAM_MAX_LENGTH;

        if (strlen($message) <= $maxLength) {
            return [$message];
        }

        $parts = [];
        $current = '';

        foreach ($lines as $line) {
            $candidate = $current === '' ? $line : $current."\n".$line;

            if (strlen($candidate) <= $maxLength) {
                $current = $candidate;

                continue;
            }

            if ($current !== '') {
                $parts[] = $current;
            }

            if (strlen($line) <= $maxLength) {
                $current = $line;

                continue;
            }

            $parts = [...$parts, ...str_split($line, $maxLength - 3)];
            $current = '';
        }

        if ($current !== '') {
            $parts[] = $current;
        }

        return $parts === [] ? [''] : $parts;
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

    private function bullet(string $line): string
    {
        return '• '.$line;
    }
}
