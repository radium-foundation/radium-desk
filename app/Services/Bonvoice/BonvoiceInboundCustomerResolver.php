<?php

namespace App\Services\Bonvoice;

use App\Enums\BonvoiceCallAlertType;
use App\Enums\IncidentStatus;
use App\Models\Incident;
use App\Models\Order;
use App\Services\Interakt\InteraktCustomerMatcher;

class BonvoiceInboundCustomerResolver
{
    public function __construct(
        private readonly InteraktCustomerMatcher $customerMatcher,
    ) {}

    /**
     * @return array{
     *     alert_type: BonvoiceCallAlertType,
     *     customer_phone: ?string,
     *     order_id: ?int,
     *     order_label: ?string,
     *     incident_id: ?int,
     * }
     */
    public function resolve(?string $phone): array
    {
        $storedPhones = $this->customerMatcher->matchingStoredPhones(null, null, $phone);

        if ($storedPhones === []) {
            return [
                'alert_type' => BonvoiceCallAlertType::UnknownCaller,
                'customer_phone' => $phone,
                'order_id' => null,
                'order_label' => null,
                'incident_id' => null,
            ];
        }

        $orders = Order::query()
            ->whereIn('customer_phone', $storedPhones)
            ->orderByDesc('id')
            ->get();

        $activeStatuses = array_map(
            fn (IncidentStatus $status): string => $status->value,
            IncidentStatus::operationallyActive(),
        );

        foreach ($orders as $order) {
            $incident = $order->incidents()
                ->whereIn('status', $activeStatuses)
                ->orderByDesc('id')
                ->first();

            if ($incident instanceof Incident) {
                return $this->customerFoundMatch($order, $incident, $phone);
            }
        }

        /** @var Order $latestOrder */
        $latestOrder = $orders->first();

        return $this->customerFoundMatch($latestOrder, null, $phone);
    }

    /**
     * @return array{
     *     alert_type: BonvoiceCallAlertType,
     *     customer_phone: ?string,
     *     order_id: ?int,
     *     order_label: ?string,
     *     incident_id: ?int,
     * }
     */
    private function customerFoundMatch(Order $order, ?Incident $incident, ?string $phone): array
    {
        return [
            'alert_type' => BonvoiceCallAlertType::CustomerFound,
            'customer_phone' => $order->customer_phone ?? $phone,
            'order_id' => $order->id,
            'order_label' => $order->order_id,
            'incident_id' => $incident?->id,
        ];
    }
}
