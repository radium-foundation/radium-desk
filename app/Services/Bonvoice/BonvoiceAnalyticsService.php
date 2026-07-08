<?php

namespace App\Services\Bonvoice;

use App\Models\BonvoiceCallEvent;
use App\Models\Order;
use App\Models\User;
use App\Services\Interakt\InteraktCustomerMatcher;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;

class BonvoiceAnalyticsService
{
    private const CACHE_KEY = 'bonvoice:analytics:operations';

    private const CACHE_TTL_SECONDS = 60;

    private const MISSED_CALLS_LIMIT = 15;

    /** @var list<string> */
    private const ANSWERED_STATUSES = ['ANSWERED', 'COMPLETED'];

    /** @var list<string> */
    private const MISSED_STATUSES = ['NOANSWER', 'NOINPUT', 'FAILED'];

    public function __construct(
        private readonly InteraktCustomerMatcher $customerMatcher,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function widgets(bool $useCache = true): array
    {
        if (! $useCache) {
            return $this->build();
        }

        return Cache::remember(
            self::CACHE_KEY,
            now()->addSeconds(self::CACHE_TTL_SECONDS),
            fn (): array => $this->build(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    private function build(): array
    {
        return [
            'ivr_health' => $this->ivrHealthToday(),
            'agent_performance' => $this->agentPerformanceToday(),
            'missed_calls' => $this->recentMissedCalls(),
            'generated_at' => now()->toIso8601String(),
        ];
    }

    /**
     * @return array<string, mixed>
     */
    private function ivrHealthToday(): array
    {
        $todayStart = today();

        $counts = DB::table('bonvoice_call_events')
            ->where('started_at', '>=', $todayStart)
            ->selectRaw('
                COUNT(*) as total_calls,
                SUM(CASE WHEN UPPER(status) IN (?, ?) THEN 1 ELSE 0 END) as answered_count,
                SUM(CASE WHEN UPPER(status) IN (?, ?, ?) THEN 1 ELSE 0 END) as missed_count
            ', [
                self::ANSWERED_STATUSES[0],
                self::ANSWERED_STATUSES[1],
                self::MISSED_STATUSES[0],
                self::MISSED_STATUSES[1],
                self::MISSED_STATUSES[2],
            ])
            ->first();

        $totalCalls = (int) ($counts->total_calls ?? 0);
        $answeredCount = (int) ($counts->answered_count ?? 0);
        $missedCount = (int) ($counts->missed_count ?? 0);

        $avgDurationSeconds = $this->averageAnsweredDurationSeconds($todayStart);

        return [
            'total_calls' => $totalCalls,
            'answered_count' => $answeredCount,
            'answered_percent' => $this->percent($answeredCount, $totalCalls),
            'missed_count' => $missedCount,
            'missed_percent' => $this->percent($missedCount, $totalCalls),
            'average_duration_seconds' => $avgDurationSeconds,
            'average_duration_label' => $this->formatDuration($avgDurationSeconds),
        ];
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function agentPerformanceToday(): array
    {
        $todayStart = today();

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
                SUM(CASE WHEN UPPER(status) IN (?, ?, ?) THEN 1 ELSE 0 END) as missed_count
            ', [
                self::ANSWERED_STATUSES[0],
                self::ANSWERED_STATUSES[1],
                self::MISSED_STATUSES[0],
                self::MISSED_STATUSES[1],
                self::MISSED_STATUSES[2],
            ])
            ->groupBy('destination_number')
            ->get();

        $agents = User::query()
            ->whereNotNull('bonvoice_extension')
            ->get(['id', 'name', 'bonvoice_extension']);

        $performanceByUserId = [];

        foreach ($rows as $row) {
            $user = $this->resolveAgentForDestination((string) $row->destination_number, $agents);

            if (! $user instanceof User) {
                continue;
            }

            if (! isset($performanceByUserId[$user->id])) {
                $performanceByUserId[$user->id] = [
                    'agent_id' => $user->id,
                    'agent_name' => $user->name,
                    'total_calls' => 0,
                    'answered_count' => 0,
                    'missed_count' => 0,
                ];
            }

            $performanceByUserId[$user->id]['total_calls'] += (int) $row->total_calls;
            $performanceByUserId[$user->id]['answered_count'] += (int) $row->answered_count;
            $performanceByUserId[$user->id]['missed_count'] += (int) $row->missed_count;
        }

        return collect($performanceByUserId)
            ->sortByDesc('total_calls')
            ->values()
            ->all();
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function recentMissedCalls(): array
    {
        $calls = BonvoiceCallEvent::query()
            ->whereRaw('UPPER(status) IN (?, ?, ?)', self::MISSED_STATUSES)
            ->orderByDesc('started_at')
            ->limit(self::MISSED_CALLS_LIMIT)
            ->get(['call_id', 'customer_phone', 'started_at', 'status']);

        $ordersByPhone = $this->latestOrdersByPhone(
            $calls->pluck('customer_phone')->filter()->unique()->values(),
        );

        return $calls
            ->map(function (BonvoiceCallEvent $call) use ($ordersByPhone): array {
                $order = $ordersByPhone->get((string) $call->customer_phone);

                return [
                    'call_id' => $call->call_id,
                    'customer_phone' => $call->customer_phone,
                    'started_at' => $this->toIso8601String($call->started_at),
                    'status' => $call->status,
                    'order_id' => $order?->id,
                    'order_label' => $order?->order_id,
                    'order_url' => $order instanceof Order ? route('orders.show', $order) : null,
                ];
            })
            ->all();
    }

    /**
     * @param  Collection<int, string|null>  $phones
     * @return Collection<string, Order>
     */
    private function latestOrdersByPhone(Collection $phones): Collection
    {
        if ($phones->isEmpty()) {
            return collect();
        }

        return Order::query()
            ->whereIn('customer_phone', $phones->all())
            ->orderByDesc('id')
            ->get(['id', 'order_id', 'customer_phone'])
            ->unique('customer_phone')
            ->keyBy('customer_phone');
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

    private function averageAnsweredDurationSeconds(Carbon $todayStart): ?int
    {
        $avg = DB::table('bonvoice_call_events')
            ->where('started_at', '>=', $todayStart)
            ->whereRaw('UPPER(status) IN (?, ?)', self::ANSWERED_STATUSES)
            ->whereNotNull('payload')
            ->selectRaw($this->averageDurationSelectExpression())
            ->value('avg_duration');

        if ($avg === null) {
            return null;
        }

        return (int) round((float) $avg);
    }

    private function averageDurationSelectExpression(): string
    {
        return match (DB::connection()->getDriverName()) {
            'sqlite' => 'AVG(CAST(json_extract(payload, \'$.CallDuration\') AS INTEGER)) as avg_duration',
            default => 'AVG(CAST(JSON_UNQUOTE(JSON_EXTRACT(payload, \'$.CallDuration\')) AS UNSIGNED)) as avg_duration',
        };
    }

    private function percent(int $count, int $total): float
    {
        if ($total === 0) {
            return 0.0;
        }

        return round(($count / $total) * 100, 1);
    }

    private function formatDuration(?int $seconds): ?string
    {
        if ($seconds === null) {
            return null;
        }

        if ($seconds < 60) {
            return $seconds.'s';
        }

        $minutes = intdiv($seconds, 60);
        $remainingSeconds = $seconds % 60;

        return $remainingSeconds > 0
            ? "{$minutes}m {$remainingSeconds}s"
            : "{$minutes}m";
    }

    private function toIso8601String(mixed $value): ?string
    {
        if ($value instanceof Carbon) {
            return $value->toIso8601String();
        }

        if (is_string($value) && $value !== '') {
            return $value;
        }

        return null;
    }
}
