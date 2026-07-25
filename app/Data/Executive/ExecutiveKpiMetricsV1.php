<?php

namespace App\Data\Executive;

use Illuminate\Support\Carbon;

/**
 * Immutable Mission Control executive KPI scalars (v1).
 *
 * Projected from ExecutiveMetricsService snapshot — facts only, no formulas.
 * Includes only the eight existing executive metrics; no invented KPIs.
 */
readonly class ExecutiveKpiMetricsV1
{
    public function __construct(
        public int|float|string $openCases,
        public int|float|string $criticalCases,
        public int|float|string $refundQueue,
        public int|float|string $activeAgents,
        public int|float|string $customersWaiting,
        public int|float|string $ordersToday,
        public int|float|string $resolvedToday,
        public int|float|string $appointmentsToday,
        public ExecutiveMetricPeriod $period,
        public Carbon $generatedAt,
    ) {}

    public static function fromSnapshot(ExecutiveMetricsSnapshot $snapshot): self
    {
        return new self(
            openCases: $snapshot->get('open_cases')->value,
            criticalCases: $snapshot->get('critical_cases')->value,
            refundQueue: $snapshot->get('refund_queue')->value,
            activeAgents: $snapshot->get('active_agents')->value,
            customersWaiting: $snapshot->get('customers_waiting')->value,
            ordersToday: $snapshot->get('orders_today')->value,
            resolvedToday: $snapshot->get('resolved_today')->value,
            appointmentsToday: $snapshot->get('appointments_today')->value,
            period: $snapshot->period,
            generatedAt: $snapshot->generatedAt->copy(),
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return [
            'open_cases' => $this->openCases,
            'critical_cases' => $this->criticalCases,
            'refund_queue' => $this->refundQueue,
            'active_agents' => $this->activeAgents,
            'customers_waiting' => $this->customersWaiting,
            'orders_today' => $this->ordersToday,
            'resolved_today' => $this->resolvedToday,
            'appointments_today' => $this->appointmentsToday,
            'period' => $this->period->value,
            'generated_at' => $this->generatedAt->toIso8601String(),
        ];
    }
}
