<?php

namespace App\Services;

use App\Data\CustomerIntakeSearchResult;
use App\Enums\CustomerIdentityType;
use App\Models\Order;
use App\Services\Interakt\InteraktCustomerMatcher;
use App\Services\RadiumBox\RadiumBoxClient;
use Illuminate\Support\Collection;

class CustomerIntakeSearchService
{
    public function __construct(
        private readonly InteraktCustomerMatcher $customerMatcher,
        private readonly RadiumBoxClient $radiumBoxClient,
    ) {}

    public function search(
        ?string $phone = null,
        ?string $orderId = null,
        ?string $serialNumber = null,
    ): CustomerIntakeSearchResult {
        $phone = $this->normalizeOptional($phone);
        $orderId = $this->normalizeOptional($orderId);
        $serialNumber = $this->normalizeSerial($serialNumber);

        $matches = $this->findDeskMatches($phone, $orderId, $serialNumber);

        if ($matches->isNotEmpty()) {
            $classification = $this->classifyFromMatches($matches);

            return new CustomerIntakeSearchResult(
                classification: $classification,
                matches: $this->formatMatches($matches),
                legacySource: $classification === CustomerIdentityType::Legacy ? 'desk' : null,
            );
        }

        if ($orderId !== null) {
            $radiumBoxMatch = $this->findRadiumBoxMatch($orderId);

            if ($radiumBoxMatch !== null) {
                return new CustomerIntakeSearchResult(
                    classification: CustomerIdentityType::Legacy,
                    matches: [$radiumBoxMatch],
                    legacySource: 'radiumbox',
                );
            }
        }

        return new CustomerIntakeSearchResult(
            classification: CustomerIdentityType::NewContact,
            matches: [],
        );
    }

    /**
     * @return Collection<int, Order>
     */
    private function findDeskMatches(?string $phone, ?string $orderId, ?string $serialNumber): Collection
    {
        $orders = collect();

        if ($orderId !== null) {
            $order = Order::query()->where('order_id', $orderId)->first();

            if ($order !== null) {
                $orders->push($order);
            }
        }

        if ($phone !== null) {
            $storedPhones = $this->customerMatcher->matchingStoredPhones(null, $phone);

            if ($storedPhones !== []) {
                $phoneOrders = Order::query()
                    ->whereIn('customer_phone', $storedPhones)
                    ->orderByDesc('id')
                    ->get();

                $orders = $orders->merge($phoneOrders);
            }
        }

        if ($serialNumber !== null) {
            $serialOrders = Order::query()
                ->where('serial_number', $serialNumber)
                ->orderByDesc('id')
                ->get();

            $orders = $orders->merge($serialOrders);
        }

        return $orders->unique('id')->values();
    }

    /**
     * @return array{
     *     id: int,
     *     order_id: string,
     *     customer_phone: ?string,
     *     serial_number: ?string,
     *     product_name: ?string,
     *     identity_type: string,
     *     legacy_source: ?string,
     * }|null
     */
    private function findRadiumBoxMatch(string $orderId): ?array
    {
        $enrichment = $this->radiumBoxClient->fetchOrderEnrichment($orderId);

        if ($enrichment === null || ! $enrichment->hasData()) {
            return null;
        }

        return [
            'id' => 0,
            'order_id' => $orderId,
            'customer_phone' => null,
            'serial_number' => $enrichment->serialNumber,
            'product_name' => $enrichment->deviceModel,
            'identity_type' => CustomerIdentityType::Legacy->value,
            'legacy_source' => 'radiumbox',
        ];
    }

    /**
     * @param  Collection<int, Order>  $matches
     */
    private function classifyFromMatches(Collection $matches): CustomerIdentityType
    {
        $hasCashfree = $matches->contains(
            fn (Order $order): bool => filled($order->cashfree_payment_id),
        );

        if ($hasCashfree) {
            return CustomerIdentityType::CashfreeVerified;
        }

        return CustomerIdentityType::Legacy;
    }

    /**
     * @param  Collection<int, Order>  $matches
     * @return list<array{
     *     id: int,
     *     order_id: string,
     *     customer_phone: ?string,
     *     serial_number: ?string,
     *     product_name: ?string,
     *     identity_type: string,
     *     legacy_source: ?string,
     * }>
     */
    private function formatMatches(Collection $matches): array
    {
        return $matches
            ->map(function (Order $order): array {
                $identityType = $this->identityTypeForOrder($order);

                return [
                    'id' => $order->id,
                    'order_id' => $order->order_id,
                    'customer_phone' => $order->customer_phone,
                    'serial_number' => $order->serial_number,
                    'product_name' => $order->displayDeviceModelName(),
                    'identity_type' => $identityType->value,
                    'legacy_source' => $identityType === CustomerIdentityType::Legacy ? 'desk' : null,
                ];
            })
            ->values()
            ->all();
    }

    public function identityTypeForOrder(Order $order): CustomerIdentityType
    {
        if (filled($order->cashfree_payment_id)) {
            return CustomerIdentityType::CashfreeVerified;
        }

        if (Order::isInquiryOrderId($order->order_id)) {
            return CustomerIdentityType::NewContact;
        }

        return CustomerIdentityType::Legacy;
    }

    private function normalizeOptional(?string $value): ?string
    {
        if ($value === null) {
            return null;
        }

        $normalized = trim($value);

        return $normalized === '' ? null : $normalized;
    }

    private function normalizeSerial(?string $value): ?string
    {
        $normalized = $this->normalizeOptional($value);

        return $normalized !== null ? strtoupper($normalized) : null;
    }
}
