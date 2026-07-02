<?php

namespace App\Services\AI;

use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use Illuminate\Support\Collection;

class CustomerScopeQueryCache
{
    private ?Collection $orderIds = null;

    /** @var Collection<int, Order>|null */
    private ?Collection $orders = null;

    /** @var Collection<int, Incident>|null */
    private ?Collection $incidents = null;

    /** @var Collection<int, Incident>|null */
    private ?Collection $incidentsWithAssignee = null;

    public function __construct(
        private readonly ?string $customerPhone,
    ) {}

    /**
     * @return Collection<int, int>
     */
    public function orderIds(): Collection
    {
        if ($this->orderIds !== null) {
            return $this->orderIds;
        }

        if (! filled($this->customerPhone)) {
            return $this->orderIds = collect();
        }

        return $this->orderIds = Order::query()
            ->where('customer_phone', $this->customerPhone)
            ->pluck('id');
    }

    /**
     * @return Collection<int, Order>
     */
    public function orders(): Collection
    {
        if ($this->orders !== null) {
            return $this->orders;
        }

        $orderIds = $this->orderIds();

        if ($orderIds->isEmpty()) {
            return $this->orders = collect();
        }

        return $this->orders = Order::query()
            ->whereIn('id', $orderIds)
            ->get();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function incidents(): Collection
    {
        if ($this->incidents !== null) {
            return $this->incidents;
        }

        $orderIds = $this->orderIds();

        if ($orderIds->isEmpty()) {
            return $this->incidents = collect();
        }

        return $this->incidents = Incident::query()
            ->whereIn('order_id', $orderIds)
            ->get();
    }

    /**
     * @return Collection<int, Incident>
     */
    public function incidentsWithAssignee(): Collection
    {
        if ($this->incidentsWithAssignee !== null) {
            return $this->incidentsWithAssignee;
        }

        $orderIds = $this->orderIds();

        if ($orderIds->isEmpty()) {
            return $this->incidentsWithAssignee = collect();
        }

        return $this->incidentsWithAssignee = Incident::query()
            ->with('assignee')
            ->whereIn('order_id', $orderIds)
            ->get();
    }

    /**
     * @return array<string, int>
     */
    public function customerSummary(): array
    {
        $orderIds = $this->orderIds();

        if ($orderIds->isEmpty()) {
            return [
                'total_orders' => 0,
                'total_devices' => 0,
                'open_cases' => 0,
                'closed_cases' => 0,
            ];
        }

        $openStatuses = array_map(
            fn (IncidentStatus $status) => $status->value,
            IncidentStatus::operationallyActive(),
        );

        $closedStatuses = [
            IncidentStatus::Resolved->value,
            IncidentStatus::Closed->value,
        ];

        return [
            'total_orders' => $orderIds->count(),
            'total_devices' => Order::query()
                ->whereIn('id', $orderIds)
                ->whereNotNull('serial_number')
                ->where('serial_number', '!=', '')
                ->distinct()
                ->count('serial_number'),
            'open_cases' => Incident::query()
                ->whereIn('order_id', $orderIds)
                ->whereIn('status', $openStatuses)
                ->count(),
            'closed_cases' => Incident::query()
                ->whereIn('order_id', $orderIds)
                ->whereIn('status', $closedStatuses)
                ->count(),
        ];
    }
}
