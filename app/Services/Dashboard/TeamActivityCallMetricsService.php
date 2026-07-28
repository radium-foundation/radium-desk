<?php

namespace App\Services\Dashboard;

use App\Data\TeamActivityCallMetrics;
use App\Models\User;
use App\Services\Interakt\InteraktCustomerMatcher;
use App\Services\Operations\PresenceEngineService;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class TeamActivityCallMetricsService
{
    /** @var list<string> */
    private const ANSWERED_STATUSES = ['ANSWERED', 'COMPLETED'];

    public function __construct(
        private readonly InteraktCustomerMatcher $customerMatcher,
        private readonly PresenceEngineService $presenceEngine,
    ) {}

    /**
     * @param  list<int>  $userIds
     * @return array<int, TeamActivityCallMetrics>
     */
    public function forUsers(array $userIds, ?Carbon $at = null): array
    {
        if ($userIds === []) {
            return [];
        }

        $at ??= now();
        $todayStart = $at->copy()->startOfDay();

        $agents = User::query()
            ->whereIn('id', $userIds)
            ->whereNotNull('bonvoice_extension')
            ->get(['id', 'bonvoice_extension']);

        if ($agents->isEmpty()) {
            return [];
        }

        $rows = DB::table('bonvoice_call_events')
            ->where('started_at', '>=', $todayStart)
            ->whereNotNull('destination_number')
            ->where(function ($query): void {
                $query->whereRaw('LOWER(direction) IN (?, ?, ?)', ['inbound', 'in', 'incoming']);
            })
            ->selectRaw('
                destination_number,
                COUNT(*) as total_calls,
                SUM(CASE WHEN UPPER(status) IN (?, ?) THEN 1 ELSE 0 END) as answered_count,
                '.$this->talkDurationSumExpression().'
            ', [
                self::ANSWERED_STATUSES[0],
                self::ANSWERED_STATUSES[1],
                self::ANSWERED_STATUSES[0],
                self::ANSWERED_STATUSES[1],
            ])
            ->groupBy('destination_number')
            ->get();

        $metricsByUserId = [];

        foreach ($rows as $row) {
            $user = $this->resolveAgentForDestination((string) $row->destination_number, $agents);

            if (! $user instanceof User) {
                continue;
            }

            if (! isset($metricsByUserId[$user->id])) {
                $metricsByUserId[$user->id] = [
                    'answered_count' => 0,
                    'total_calls' => 0,
                    'talk_duration_seconds' => 0,
                ];
            }

            $metricsByUserId[$user->id]['answered_count'] += (int) $row->answered_count;
            $metricsByUserId[$user->id]['total_calls'] += (int) $row->total_calls;
            $metricsByUserId[$user->id]['talk_duration_seconds'] += (int) ($row->talk_duration_seconds ?? 0);
        }

        $metrics = [];

        foreach ($metricsByUserId as $userId => $counts) {
            $talkDurationSeconds = (int) $counts['talk_duration_seconds'];

            $metrics[$userId] = new TeamActivityCallMetrics(
                answeredCount: (int) $counts['answered_count'],
                totalCount: (int) $counts['total_calls'],
                talkDurationSeconds: $talkDurationSeconds,
                talkDurationLabel: $this->presenceEngine->formatDuration($talkDurationSeconds),
            );
        }

        return $metrics;
    }

    private function talkDurationSumExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => 'SUM(CASE WHEN UPPER(status) IN (?, ?) THEN COALESCE(CAST(json_extract(payload, \'$.CallDuration\') AS INTEGER), 0) ELSE 0 END) as talk_duration_seconds',
            default => 'SUM(CASE WHEN UPPER(status) IN (?, ?) THEN COALESCE(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, \'$.CallDuration\')) AS UNSIGNED), 0) ELSE 0 END) as talk_duration_seconds',
        };
    }

    /**
     * @param  Collection<int, User>  $agents
     */
    private function resolveAgentForDestination(string $destinationNumber, Collection $agents): ?User
    {
        $incomingCandidates = $this->customerMatcher->channelPhoneCandidates($destinationNumber);

        if ($incomingCandidates === []) {
            return null;
        }

        return $agents->first(function (User $user) use ($incomingCandidates): bool {
            $storedCandidates = $this->customerMatcher->channelPhoneCandidates($user->bonvoice_extension);

            return $storedCandidates !== []
                && array_intersect($storedCandidates, $incomingCandidates) !== [];
        });
    }
}
