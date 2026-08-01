<?php

namespace App\Services\Workforce\Recognition;

use App\Models\User;
use App\Models\WorkSession;
use App\Models\WorkforceAttendanceDay;
use App\Services\Operations\WorkforceActivityTimelineService;
use App\Services\Workforce\Contribution\ContributionEngine;
use Illuminate\Support\Carbon;

/**
 * Builds a versioned evidence snapshot for Work Recognition reviews.
 * May READ contribution/sessions/timeline. Never writes attendance.
 */
class EvidenceSnapshotBuilder
{
    public function __construct(
        private readonly ContributionEngine $contributionEngine,
        private readonly WorkforceActivityTimelineService $timelineService,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function build(User $user, Carbon $date): array
    {
        $workDate = $date->copy()->startOfDay();
        $evaluation = $this->contributionEngine->evaluateQuiet($user, $workDate);
        $sessions = WorkSession::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->orderBy('login_at')
            ->get(['id', 'login_at', 'logout_at', 'session_duration_seconds', 'active_duration_seconds']);

        $attendance = WorkforceAttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->first();

        $loginSeconds = (int) ($attendance?->session_duration_seconds
            ?? $sessions->sum('session_duration_seconds'));
        $productiveSeconds = (int) ($attendance?->active_duration_seconds
            ?? $sessions->sum('active_duration_seconds'));

        $signals = [];
        foreach ($evaluation->signals as $signal) {
            $signals[] = [
                'id' => $signal->id->value,
                'label' => $signal->label(),
                'value' => $signal->value,
                'unit' => $signal->unit,
                'available' => $signal->available,
                'reserved' => $signal->reserved,
            ];
        }

        $timeline = array_map(
            static fn (array $row): array => [
                'time' => $row['time'],
                'label' => $row['label'],
                'event' => $row['event'],
                'source' => $row['source'],
            ],
            array_slice($this->timelineService->forUserOnDate($user, $workDate), 0, 40),
        );

        return [
            'version' => (int) config('workforce_recognition.snapshot_version', 1),
            'work_date' => $workDate->toDateString(),
            'login_seconds' => $loginSeconds,
            'productive_seconds' => $productiveSeconds,
            'session_count' => $sessions->count(),
            'session_ids' => $sessions->pluck('id')->values()->all(),
            'first_login_at' => $sessions->first()?->login_at?->toIso8601String(),
            'last_logout_at' => $sessions->filter(fn ($s) => $s->logout_at !== null)->last()?->logout_at?->toIso8601String(),
            'attendance_status' => $attendance?->status?->value,
            'contribution' => [
                'engine_enabled' => $evaluation->engineEnabled,
                'pack_id' => $evaluation->pack->id,
                'verdict' => $evaluation->verdict->value,
                'qualified' => $evaluation->isQualified(),
                'reasons' => $evaluation->reasons,
                'thresholds_met' => $evaluation->thresholdsMet,
                'thresholds_failed' => $evaluation->thresholdsFailed,
                'signals' => $signals,
            ],
            'timeline' => $timeline,
            'evidence_summary' => $this->summarizeSignals($signals),
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $signals
     * @return list<string>
     */
    private function summarizeSignals(array $signals): array
    {
        $lines = [];

        foreach ($signals as $signal) {
            if (! ($signal['available'] ?? false)) {
                continue;
            }
            $value = $signal['value'] ?? 0;
            if ((float) $value <= 0) {
                continue;
            }
            $lines[] = sprintf('%s: %s', $signal['label'] ?? $signal['id'], $value);
        }

        return $lines;
    }
}
