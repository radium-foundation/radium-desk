<?php

namespace App\Services\Operations;

use App\Data\Operations\HumanEffortOutcomeMetrics;
use App\Enums\OperationsKpiProfile;
use App\Models\AuditLog;
use App\Models\Order;
use App\Models\User;
use Illuminate\Support\Carbon;

class AdminActivationMetricsService
{
    public function metricsFor(User $user, ?Carbon $rangeStart = null, ?Carbon $rangeEnd = null): HumanEffortOutcomeMetrics
    {
        return $this->metricsForUsers([$user->id], $rangeStart, $rangeEnd)[$user->id]
            ?? new HumanEffortOutcomeMetrics(
                profile: OperationsKpiProfile::Activation,
                outcome: 0,
                effort: 0,
            );
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, HumanEffortOutcomeMetrics>
     */
    public function metricsForUsers(
        array $userIds,
        ?Carbon $rangeStart = null,
        ?Carbon $rangeEnd = null,
    ): array {
        if ($userIds === []) {
            return [];
        }

        $rangeStart ??= Carbon::now()->startOfDay();
        $rangeEnd ??= Carbon::now();
        $ordersEvent = (string) config('operations-kpi.activation.orders_activated_event', 'service_reference.assigned');
        $failedEvent = (string) config('operations-kpi.activation.failed_activation_event', 'transaction.assignment_blocked');
        $driverGuideEvent = (string) config('operations-kpi.activation.driver_guide_event', 'service_reference.driver_guide_sent');
        $orderMorph = (new Order)->getMorphClass();

        $activationLogs = AuditLog::query()
            ->whereIn('user_id', $userIds)
            ->where('event', $ordersEvent)
            ->where('created_at', '>=', $rangeStart)
            ->where('created_at', '<=', $rangeEnd)
            ->orderBy('user_id')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['id', 'user_id', 'new_values', 'created_at']);

        $ordersActivated = array_fill_keys($userIds, 0);
        $activationSessions = array_fill_keys($userIds, 0);
        $logsByUser = [];

        foreach ($activationLogs as $log) {
            $userId = (int) $log->user_id;
            $logsByUser[$userId][] = $log;
            $ordersActivated[$userId]++;
        }

        foreach ($logsByUser as $userId => $logs) {
            $activationSessions[$userId] = $this->countActivationSessions($logs);
        }

        $failedActivations = $this->countEventsForUsers($userIds, $rangeStart, $rangeEnd, $failedEvent, $orderMorph);
        $driverGuidesSent = $this->countEventsForUsers($userIds, $rangeStart, $rangeEnd, $driverGuideEvent, $orderMorph);

        $metrics = [];

        foreach ($userIds as $userId) {
            $sessions = (int) ($activationSessions[$userId] ?? 0);
            $orders = (int) ($ordersActivated[$userId] ?? 0);

            $metrics[$userId] = new HumanEffortOutcomeMetrics(
                profile: OperationsKpiProfile::Activation,
                outcome: $orders,
                effort: $sessions,
                breakdown: [
                    'activation_sessions' => $sessions,
                    'orders_activated' => $orders,
                    'average_orders_per_session' => $sessions > 0
                        ? round($orders / $sessions, 1)
                        : null,
                    'failed_activations' => (int) ($failedActivations[$userId] ?? 0),
                    'driver_guides_sent' => (int) ($driverGuidesSent[$userId] ?? 0),
                ],
            );
        }

        return $metrics;
    }

    /**
     * @param  list<AuditLog>  $logs
     */
    public function countActivationSessions(array $logs): int
    {
        if ($logs === []) {
            return 0;
        }

        $gapSeconds = max(1, (int) config('operations-kpi.activation_session_gap_seconds', 2));
        $sessions = 0;
        $currentUserId = null;
        $currentReference = null;
        $lastTimestamp = null;

        foreach ($logs as $log) {
            $userId = (int) $log->user_id;
            $reference = trim((string) ($log->new_values['transaction_id'] ?? ''));
            $timestamp = $log->created_at?->getTimestamp();

            if ($reference === '' || $timestamp === null) {
                continue;
            }

            $isNewSession = $currentUserId !== $userId
                || $currentReference !== $reference
                || $lastTimestamp === null
                || ($timestamp - $lastTimestamp) > $gapSeconds;

            if ($isNewSession) {
                $sessions++;
                $currentUserId = $userId;
                $currentReference = $reference;
            }

            $lastTimestamp = $timestamp;
        }

        return $sessions;
    }

    /**
     * @param  list<int>  $userIds
     * @return array<int, int>
     */
    private function countEventsForUsers(
        array $userIds,
        Carbon $rangeStart,
        Carbon $rangeEnd,
        string $event,
        string $auditableType,
    ): array {
        return AuditLog::query()
            ->selectRaw('user_id, COUNT(*) as aggregate')
            ->whereIn('user_id', $userIds)
            ->where('event', $event)
            ->where('auditable_type', $auditableType)
            ->where('created_at', '>=', $rangeStart)
            ->where('created_at', '<=', $rangeEnd)
            ->groupBy('user_id')
            ->pluck('aggregate', 'user_id')
            ->map(fn (mixed $count): int => (int) $count)
            ->all();
    }
}
