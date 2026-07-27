<?php

namespace App\Services\Dashboard;

use App\Data\TeamActivityPresenceMetrics;
use App\Data\Operations\WorkingHoursToday;
use App\Models\WorkSession;
use App\Services\Operations\PresenceEngineService;
use App\Services\Operations\WorkingHoursTodayService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TeamActivityPresenceMetricsService
{
    public function __construct(
        private readonly PresenceEngineService $presenceEngine,
        private readonly WorkingHoursTodayService $workingHoursToday,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @return array<int, TeamActivityPresenceMetrics>
     */
    public function forUsers(array $userIds, ?Carbon $at = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $at ??= now();
        $workDate = $at->toDateString();

        $hoursByUser = $this->workingHoursToday->forUsers($userIds, $at);

        $sessions = WorkSession::query()
            ->whereIn('user_id', $userIds)
            ->whereDate('work_date', $workDate)
            ->orderBy('login_at')
            ->get()
            ->groupBy('user_id');

        $metrics = [];

        foreach ($userIds as $userId) {
            /** @var Collection<int, WorkSession> $userSessions */
            $userSessions = $sessions->get($userId, collect());
            $hours = $hoursByUser[$userId] ?? WorkingHoursToday::empty();

            $metrics[$userId] = $this->buildFromSessionsAndHours($userSessions, $hours, $at);
        }

        return $metrics;
    }

    /**
     * @param  Collection<int, WorkSession>  $sessions
     */
    private function buildFromSessionsAndHours(
        Collection $sessions,
        WorkingHoursToday $hours,
        Carbon $at,
    ): TeamActivityPresenceMetrics {
        $openSession = $sessions
            ->filter(fn (WorkSession $session): bool => $session->isOpen())
            ->sortByDesc(fn (WorkSession $session): int => $session->login_at?->getTimestamp() ?? 0)
            ->first();

        $openElapsed = 0;

        if ($openSession?->login_at !== null) {
            $openElapsed = max(0, (int) $openSession->login_at->diffInSeconds($at));
        }

        $todaySeconds = $hours->activeDurationSeconds;
        $sessionsToday = $hours->sessionCount;
        $shouldShowToday = $hours->shouldDisplay() || $openSession !== null;
        $currentSeconds = $openSession !== null ? $openElapsed : null;

        return new TeamActivityPresenceMetrics(
            sessionsToday: $sessionsToday,
            todayDurationSeconds: $todaySeconds,
            currentDurationSeconds: $currentSeconds,
            hasOpenSession: $openSession !== null,
            todayDurationLabel: $shouldShowToday
                ? $this->presenceEngine->formatDuration($todaySeconds)
                : null,
            currentDurationLabel: $currentSeconds !== null
                ? $this->presenceEngine->formatDuration($currentSeconds)
                : null,
        );
    }
}
