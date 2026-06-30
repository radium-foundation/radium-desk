<?php

namespace App\Services\GlobalSearch;

use App\Contracts\GlobalSearchProvider;
use App\Data\GlobalSearchResult;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\UniversalSearchService;
use Illuminate\Support\Collection;

class ServiceCaseGlobalSearchProvider implements GlobalSearchProvider
{
    public function __construct(
        private readonly UniversalSearchService $searchService,
    ) {}

    public function type(): string
    {
        return 'service_case';
    }

    /**
     * @return Collection<int, GlobalSearchResult>
     */
    public function search(User $user, string $query): Collection
    {
        return $this->searchService
            ->search($user, $query)
            ->map(fn (Incident $serviceCase): GlobalSearchResult => $this->toResult($serviceCase));
    }

    private function toResult(Incident $serviceCase): GlobalSearchResult
    {
        $order = $serviceCase->order;

        return new GlobalSearchResult(
            type: $this->type(),
            entityId: $serviceCase->id,
            url: route('incidents.show', $serviceCase),
            payload: [
                'incident_id' => $serviceCase->id,
                'service_case' => $serviceCase->display_reference,
                'reference_number' => $serviceCase->reference_no ?? '—',
                'order_id' => $order?->order_id ?? '—',
                'customer' => $order?->customer_name ?? '—',
                'phone' => $order?->customer_phone ?? '—',
                'assigned_to' => $serviceCase->assignee?->name ?? '—',
                'status' => $serviceCase->status->label(),
                'age' => Order::formatCompactDurationBetween($serviceCase->created_at) ?? '—',
            ],
        );
    }
}
