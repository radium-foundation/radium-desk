<?php

namespace App\Services\Executive;

use App\Data\Executive\ExecutiveMetricPeriod;
use App\Data\Executive\ExecutiveMetricsContext;
use App\Enums\IncidentStatus;
use App\Enums\RefundStatus;
use App\Models\Incident;
use App\Models\IncidentWaitingState;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\SupportAppointment;
use App\Models\WorkSession;
use Illuminate\Support\Carbon;

class ExecutiveMetricsContextBuilder
{
    public function build(ExecutiveMetricPeriod $period = ExecutiveMetricPeriod::Today): ExecutiveMetricsContext
    {
        [$dayStart, $dayEnd] = $this->boundsFor($period);

        $activeStatuses = array_map(
            fn (IncidentStatus $status): string => $status->value,
            IncidentStatus::operationallyActive(),
        );

        $incidentAggregates = Incident::query()
            ->whereIn('status', $activeStatuses)
            ->selectRaw('COUNT(*) as open_cases')
            ->selectRaw('SUM(CASE WHEN high_priority = 1 THEN 1 ELSE 0 END) as critical_cases')
            ->first();

        $resolvedToday = Incident::query()
            ->whereIn('status', [IncidentStatus::Closed, IncidentStatus::Resolved])
            ->whereBetween('updated_at', [$dayStart, $dayEnd])
            ->count();

        $refundQueue = RefundRequest::query()
            ->where('status', RefundStatus::Pending)
            ->count();

        $activeAgents = WorkSession::query()
            ->whereNull('logout_at')
            ->whereHas('user', fn ($query) => $query->where('is_active', true))
            ->count();

        $customersWaiting = IncidentWaitingState::query()
            ->whereNull('cleared_at')
            ->count();

        $ordersToday = Order::query()
            ->whereBetween('created_at', [$dayStart, $dayEnd])
            ->count();

        $appointmentsToday = SupportAppointment::query()
            ->scheduled()
            ->whereDate('preferred_date', $dayStart->toDateString())
            ->count();

        return new ExecutiveMetricsContext(
            period: $period,
            dayStart: $dayStart,
            dayEnd: $dayEnd,
            openCases: (int) ($incidentAggregates?->open_cases ?? 0),
            criticalCases: (int) ($incidentAggregates?->critical_cases ?? 0),
            activeAgents: $activeAgents,
            customersWaiting: $customersWaiting,
            refundQueue: $refundQueue,
            ordersToday: $ordersToday,
            resolvedToday: $resolvedToday,
            appointmentsToday: $appointmentsToday,
            computedAt: now(),
        );
    }

    /**
     * @return array{0: Carbon, 1: Carbon}
     */
    private function boundsFor(ExecutiveMetricPeriod $period): array
    {
        return match ($period) {
            ExecutiveMetricPeriod::Today => [now()->startOfDay(), now()->endOfDay()],
            ExecutiveMetricPeriod::Yesterday => [now()->subDay()->startOfDay(), now()->subDay()->endOfDay()],
            ExecutiveMetricPeriod::Last7Days => [now()->subDays(6)->startOfDay(), now()->endOfDay()],
        };
    }
}
