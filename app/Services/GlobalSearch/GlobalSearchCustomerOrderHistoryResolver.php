<?php

namespace App\Services\GlobalSearch;

use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\CustomerIntakeSearchService;
use App\Services\CustomerOrderHistory\CustomerOrderHistoryService;
use Illuminate\Support\Collection;

final class GlobalSearchCustomerOrderHistoryResolver
{
    public function __construct(
        private readonly CustomerIntakeSearchService $customerIntakeSearchService,
        private readonly CustomerOrderHistoryService $customerOrderHistoryService,
    ) {}

    /**
     * @param  Collection<int, array<string, mixed>>  $serviceCaseResults
     * @return array<string, mixed>|null
     */
    public function resolveForServiceCaseSearch(User $user, string $query, Collection $serviceCaseResults): ?array
    {
        if (! $user->can('orders.view') || $serviceCaseResults->isEmpty()) {
            return null;
        }

        $parsed = $this->customerIntakeSearchService->parseQuery($query);
        $context = $this->contextFromServiceCaseResults($serviceCaseResults, $parsed);

        $history = $this->customerOrderHistoryService->historyForContext(
            phone: $context['phone'],
            email: $context['email'],
            businessOrderId: $context['business_order_id'],
            serialNumber: $context['serial_number'],
            highlightOrderDbId: $context['highlight_order_db_id'],
        );

        if ($history['ambiguous'] ?? false) {
            return $history;
        }

        if (! ($history['resolved'] ?? false) && ($history['valid_prior_rd_reference'] ?? null) === null) {
            return null;
        }

        if (($history['resolved'] ?? false) && ($history['orders'] ?? []) === []) {
            return null;
        }

        return $history;
    }

    /**
     * @param  Collection<int, array<string, mixed>>  $serviceCaseResults
     * @param  array{phone: ?string, order_id: ?string, serial_number: ?string, email: ?string}  $parsed
     * @return array{
     *     phone: ?string,
     *     email: ?string,
     *     business_order_id: ?string,
     *     serial_number: ?string,
     *     highlight_order_db_id: ?int,
     * }
     */
    private function contextFromServiceCaseResults(Collection $serviceCaseResults, array $parsed): array
    {
        $phone = $parsed['phone'];
        $email = $parsed['email'];
        $serialNumber = $parsed['serial_number'];
        $businessOrderId = $parsed['order_id'];
        $highlightOrderDbId = null;

        if ($businessOrderId !== null) {
            $highlightOrderDbId = Order::query()
                ->where('order_id', $businessOrderId)
                ->value('id');
            $highlightOrderDbId = $highlightOrderDbId !== null ? (int) $highlightOrderDbId : null;
        }

        $incidentIds = $serviceCaseResults
            ->pluck('incident_id')
            ->filter()
            ->map(fn ($id): int => (int) $id)
            ->unique()
            ->values();

        if ($incidentIds->isEmpty()) {
            return [
                'phone' => $phone,
                'email' => $email,
                'business_order_id' => $businessOrderId,
                'serial_number' => $serialNumber,
                'highlight_order_db_id' => $highlightOrderDbId,
            ];
        }

        $orders = Incident::query()
            ->whereIn('id', $incidentIds)
            ->with('order')
            ->get()
            ->map(fn (Incident $incident): ?Order => $incident->order)
            ->filter()
            ->values();

        if ($highlightOrderDbId === null && $orders->count() === 1) {
            $highlightOrderDbId = $orders->first()->id;
        }

        foreach ($orders as $order) {
            if ($phone === null && filled($order->customer_phone)) {
                $phone = $order->customer_phone;
            }

            if ($email === null && filled($order->customer_email)) {
                $email = $order->customer_email;
            }

            if ($businessOrderId === null && filled($order->order_id)) {
                $businessOrderId = $order->order_id;
            }

            if ($highlightOrderDbId === null && $parsed['order_id'] !== null
                && strcasecmp((string) $order->order_id, (string) $parsed['order_id']) === 0) {
                $highlightOrderDbId = $order->id;
            }
        }

        return [
            'phone' => $phone,
            'email' => $email,
            'business_order_id' => $businessOrderId,
            'serial_number' => $serialNumber,
            'highlight_order_db_id' => $highlightOrderDbId,
        ];
    }
}
