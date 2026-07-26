<?php

namespace App\Services\Dashboard;

use App\Data\TeamActivityPresenceMetrics;
use App\Models\WorkSession;
use App\Services\Operations\PresenceEngineService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;

class TeamActivityPresenceMetricsService
{
    public function __construct(
        private readonly PresenceEngineService $presenceEngine,
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

            $metrics[$userId] = $this->buildFromSessions($userSessions, $at);
        }

        return $metrics;
    }

    /**
     * @param  Collection<int, WorkSession>  $sessions
     */
    private function buildFromSessions(Collection $sessions, Carbon $at): TeamActivityPresenceMetrics
    {
        $sessionsToday = $sessions->count();
        $closedSeconds = 0;
        $openSession = null;

        foreach ($sessions as $session) {
            if ($session->isOpen()) {
                $openSession = $session;

                continue;
            }

            $closedSeconds += $this->closedSessionDurationSeconds($session);
        }

        $openElapsed = 0;

        if ($openSession?->login_at !== null) {
            $openElapsed = max(0, (int) $openSession->login_at->diffInSeconds($at));
        }

        $todaySeconds = $closedSeconds + $openElapsed;
        $currentSeconds = $openSession !== null ? $openElapsed : null;

        return new TeamActivityPresenceMetrics(
            sessionsToday: $sessionsToday,
            todayDurationSeconds: $todaySeconds,
            currentDurationSeconds: $currentSeconds,
            hasOpenSession: $openSession !== null,
            todayDurationLabel: $sessionsToday > 0
                ? $this->presenceEngine->formatDuration($todaySeconds)
                : null,
            currentDurationLabel: $currentSeconds !== null
                ? $this->presenceEngine->formatDuration($currentSeconds)
                : null,
        );
    }

    private function closedSessionDurationSeconds(WorkSession $session): int
    {
        $duration = (int) ($session->session_duration_seconds ?? 0);

        if ($duration > 0) {
            return $duration;
        }

        if ($session->login_at === null || $session->logout_at === null) {
            return 0;
        }

        return max(0, (int) $session->login_at->diffInSeconds($session->logout_at));
    }
}
