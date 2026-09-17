<?php

namespace App\Services\Pos;

use App\Enums\InventorySaleStatus;
use App\Models\InventoryCustomer;
use App\Models\InventorySale;

final class PosCustomerLookupService
{
    private const MIN_QUERY_LENGTH = 2;

    private const SEARCH_LIMIT = 10;

    /**
     * @return list<array{id: int, name: string, phone: string, email: ?string, gstin: ?string}>
     */
    public function search(string $query): array
    {
        $query = trim($query);
        if (mb_strlen($query) < self::MIN_QUERY_LENGTH) {
            return [];
        }

        $phoneQuery = preg_replace('/\s+/', '', $query) ?? '';

        return InventoryCustomer::query()
            ->where(function ($inner) use ($query, $phoneQuery): void {
                $inner->where('name', 'like', '%'.$query.'%');

                if ($phoneQuery !== '') {
                    $inner->orWhere('phone', 'like', '%'.$phoneQuery.'%');
                }

                $inner->orWhere('email', 'like', '%'.$query.'%');
            })
            ->orderBy('name')
            ->limit(self::SEARCH_LIMIT)
            ->get()
            ->map(static fn (InventoryCustomer $customer): array => [
                'id' => $customer->id,
                'name' => $customer->name,
                'phone' => $customer->phone,
                'email' => $customer->email,
                'gstin' => $customer->gstin,
            ])
            ->values()
            ->all();
    }

    /**
     * @return array{found: bool, id?: int, name?: string, phone?: string, email?: ?string, gstin?: ?string, billing_address?: ?string, billing_state?: ?string, place_of_supply_state?: ?string}
     */
    public function resolveByPhone(string $phone): array
    {
        $phone = preg_replace('/\s+/', '', trim($phone)) ?? '';
        if ($phone === '') {
            return ['found' => false];
        }

        $customer = InventoryCustomer::query()->where('phone', $phone)->first();

        return $customer ? $this->payloadForCustomer($customer) : ['found' => false];
    }

    /**
     * @return array{found: bool, id?: int, name?: string, phone?: string, email?: ?string, gstin?: ?string, billing_address?: ?string, billing_state?: ?string, place_of_supply_state?: ?string}
     */
    public function resolveById(int $customerId): array
    {
        $customer = InventoryCustomer::query()->find($customerId);

        return $customer ? $this->payloadForCustomer($customer) : ['found' => false];
    }

    /**
     * @return array{found: true, id: int, name: string, phone: string, email: ?string, gstin: ?string, billing_address: ?string, billing_state: ?string, place_of_supply_state: ?string}
     */
    private function payloadForCustomer(InventoryCustomer $customer): array
    {
        $snapshot = $this->latestSaleSnapshot($customer);

        return [
            'found' => true,
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'gstin' => $customer->gstin,
            'billing_address' => $snapshot['billing_address'],
            'billing_state' => $snapshot['billing_state'],
            'place_of_supply_state' => $snapshot['place_of_supply_state'],
        ];
    }

    /**
     * @return array{billing_address: ?string, billing_state: ?string, place_of_supply_state: ?string}
     */
    private function latestSaleSnapshot(InventoryCustomer $customer): array
    {
        $sale = InventorySale::query()
            ->where('customer_id', $customer->id)
            ->where('status', InventorySaleStatus::Completed)
            ->latest('completed_at')
            ->first();

        if ($sale === null) {
            return [
                'billing_address' => null,
                'billing_state' => null,
                'place_of_supply_state' => null,
            ];
        }

        $structured = is_array($sale->billing_address_structured) ? $sale->billing_address_structured : [];

        return [
            'billing_address' => $sale->billing_address ?: ($structured['line1'] ?? null),
            'billing_state' => $structured['state'] ?? null,
            'place_of_supply_state' => $sale->place_of_supply_state,
        ];
    }
}
