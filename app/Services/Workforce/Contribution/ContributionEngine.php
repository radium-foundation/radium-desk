<?php

namespace App\Services\Workforce\Contribution;

use App\Contracts\Workforce\ContributionPolicy;
use App\Contracts\Workforce\ContributionSignalCollector;
use App\Contracts\Workforce\WorkforceEventPublisher;
use App\Data\Workforce\Contribution\ContributionEvaluation;
use App\Data\Workforce\Contribution\ContributionSignal;
use App\Data\Workforce\Contribution\ContributionSnapshot;
use App\Data\Workforce\WorkforceEvent;
use App\Enums\ContributionSignalId;
use App\Enums\WorkforceEventType;
use App\Models\User;
use App\Models\WorkSession;
use App\Models\WorkforceAttendanceDay;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

/**
 * Contribution Engine — first business engine atop Daily Workforce Engine concepts.
 *
 * May READ Attendance / sessions. Must NEVER modify Attendance.
 * Feature-flagged: workforce.contribution.enabled (default false).
 */
class ContributionEngine
{
    /**
     * @param  list<ContributionSignalCollector>  $collectors
     */
    public function __construct(
        private readonly ContributionPolicy $contributionPolicy,
        private readonly WorkforceEventPublisher $workforceEventPublisher,
        private readonly array $collectors,
    ) {}

    public function enabled(): bool
    {
        return (bool) config('workforce_contribution.enabled', false);
    }

    public function evaluate(User $user, Carbon $date): ContributionEvaluation
    {
        return $this->evaluateInternal($user, $date, publishEvents: true);
    }

    /**
     * Read-only evaluation for evidence snapshots — never publishes ContributionQualified.
     */
    public function evaluateQuiet(User $user, Carbon $date): ContributionEvaluation
    {
        return $this->evaluateInternal($user, $date, publishEvents: false);
    }

    private function evaluateInternal(User $user, Carbon $date, bool $publishEvents): ContributionEvaluation
    {
        $workDate = $date->copy()->startOfDay();
        $pack = $this->contributionPolicy->resolvePack($user);
        $sessions = $this->loadSessions($user, $workDate);
        $signals = $this->collectSignals($user, $workDate, $sessions);

        // Optional read of attendance for snapshot context only — never write.
        $attendance = WorkforceAttendanceDay::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->first();

        $snapshot = new ContributionSnapshot(
            userId: (int) $user->id,
            workDate: $workDate,
            pack: $pack,
            signals: $signals,
            sessionCount: $sessions->count(),
            activeDurationSeconds: (int) ($attendance?->active_duration_seconds
                ?? $sessions->sum('active_duration_seconds')),
            engineEnabled: $this->enabled(),
        );

        $evaluation = $this->contributionPolicy->evaluate($snapshot);

        if ($publishEvents && $evaluation->isQualified()) {
            $this->workforceEventPublisher->publish(WorkforceEvent::make(
                type: WorkforceEventType::ContributionQualified,
                userId: (int) $user->id,
                workDate: $workDate,
                payload: [
                    'verdict' => $evaluation->verdict->value,
                    'pack_id' => $evaluation->pack->id,
                    'thresholds_met' => $evaluation->thresholdsMet,
                    'thresholds_failed' => $evaluation->thresholdsFailed,
                    'explanations' => $evaluation->explanationPayload(),
                ],
            ));
        }

        return $evaluation;
    }

    /**
     * @return Collection<int, WorkSession>
     */
    private function loadSessions(User $user, Carbon $workDate): Collection
    {
        return WorkSession::query()
            ->where('user_id', $user->id)
            ->whereDate('work_date', $workDate->toDateString())
            ->where(function ($query): void {
                $query->where('is_attributable', true)
                    ->orWhereNull('is_attributable');
            })
            ->get();
    }

    /**
     * @param  Collection<int, WorkSession>  $sessions
     * @return list<ContributionSignal>
     */
    private function collectSignals(User $user, Carbon $workDate, Collection $sessions): array
    {
        $signals = [];

        foreach ($this->collectors as $collector) {
            try {
                $signals[] = $collector->collect($user, $workDate, $sessions);
            } catch (\Throwable $exception) {
                report($exception);

                $signals[] = new ContributionSignal(
                    id: $collector->id(),
                    value: 0,
                    unit: $collector->id()->unit(),
                    available: false,
                    reserved: false,
                    note: 'Collector failed; degraded gracefully.',
                );
            }
        }

        return $signals;
    }
}
