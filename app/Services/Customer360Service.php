<?php

namespace App\Services;

use App\Data\TimelineViewModel;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Services\RadiumBox\RadiumBoxOrderEnrichmentSyncStore;
use App\Services\Timeline\Customer360TimelineService;
use App\Services\Timeline\TimelineService;
use App\Support\DeviceModelFormatter;

class Customer360Service
{
    public function __construct(
        private readonly Customer360TimelineService $customer360TimelineService,
        private readonly RadiumBoxOrderEnrichmentSyncStore $enrichmentSyncStore,
    ) {}

    /**
     * @return array<string, mixed>
     */
    public function drawerData(Incident $incident): array
    {
        $incident->loadMissing(['order.deviceModel']);
        $order = $incident->order;

        if ($order === null) {
            return $this->emptyDrawerData($incident);
        }

        $fullModelName = $order->displayDeviceModelName();
        $enrichmentMetadata = $this->enrichmentSyncStore->metadata($order->id) ?? [];

        return [
            'incident' => $incident,
            'order' => $order,
            'customer' => $this->customerSection($order),
            'device' => $this->deviceSection($order, $fullModelName),
            'activeServices' => $this->activeServices($order, $enrichmentMetadata),
            'summary' => $this->customerSummary($order->customer_phone),
            'timeline' => $this->customer360TimelineService->forOrder($order),
            'timelineLoadMoreUrl' => route('dashboard.service-cases.customer-360.timeline', $incident),
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function customerSection(Order $order): array
    {
        return [
            'name' => $order->customer_name,
            'mobile' => $order->customer_phone,
            'email' => $order->customer_email,
            'city' => null,
        ];
    }

    /**
     * @return array<string, string|null>
     */
    private function deviceSection(Order $order, ?string $fullModelName): array
    {
        return [
            'model_short' => DeviceModelFormatter::shortDisplay($fullModelName),
            'model_canonical' => $fullModelName,
            'serial_number' => $order->serial_number,
            'order_id' => $order->order_id,
            'service_reference' => $order->transaction_id,
        ];
    }

    /**
     * @param  array<string, mixed>  $enrichmentMetadata
     * @return list<array{label: string, status: string, variant: string}>
     */
    private function activeServices(Order $order, array $enrichmentMetadata): array
    {
        $warranty = $this->normalizeServiceStatus($enrichmentMetadata['warranty'] ?? null);
        $amc = $this->normalizeServiceStatus($enrichmentMetadata['amc'] ?? null);

        return [
            [
                'label' => 'RD Service',
                'status' => $order->isTransactionLocked() ? 'Active' : 'Pending',
                'variant' => $order->isTransactionLocked() ? 'success' : 'warning',
            ],
            [
                'label' => 'Warranty',
                'status' => $warranty,
                'variant' => $warranty === 'Not Available' ? 'neutral' : 'info',
            ],
            [
                'label' => 'AMC',
                'status' => $amc,
                'variant' => $amc === 'Not Available' ? 'neutral' : 'info',
            ],
        ];
    }

    /**
     * @return array<string, int>
     */
    private function customerSummary(?string $customerPhone): array
    {
        if (! filled($customerPhone)) {
            return [
                'total_orders' => 0,
                'total_devices' => 0,
                'open_cases' => 0,
                'closed_cases' => 0,
            ];
        }

        $orderIds = Order::query()
            ->where('customer_phone', $customerPhone)
            ->pluck('id');

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
                ->where('customer_phone', $customerPhone)
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

    private function normalizeServiceStatus(mixed $value): string
    {
        if (! is_string($value) || trim($value) === '') {
            return 'Not Available';
        }

        return trim($value);
    }

    /**
     * @return array<string, mixed>
     */
    private function emptyDrawerData(Incident $incident): array
    {
        return [
            'incident' => $incident,
            'order' => null,
            'customer' => [
                'name' => null,
                'mobile' => null,
                'email' => null,
                'city' => null,
            ],
            'device' => [
                'model_short' => null,
                'model_canonical' => null,
                'serial_number' => null,
                'order_id' => null,
                'service_reference' => null,
            ],
            'activeServices' => [],
            'summary' => [
                'total_orders' => 0,
                'total_devices' => 0,
                'open_cases' => 0,
                'closed_cases' => 0,
            ],
            'timeline' => new TimelineViewModel(
                groups: collect(),
                totalCount: 0,
                loadedCount: 0,
                offset: 0,
                limit: TimelineService::DEFAULT_PAGE_SIZE,
                hasMore: false,
            ),
            'timelineLoadMoreUrl' => route('dashboard.service-cases.customer-360.timeline', $incident),
        ];
    }
}
