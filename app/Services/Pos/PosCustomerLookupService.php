<?php

namespace App\Services\Pos;

use App\Enums\InventorySaleStatus;
use App\Models\InventoryCustomer;
use App\Models\InventorySale;
use App\Support\Finance\IndianStates;

final class PosCustomerLookupService
{
    public const BILLING_SOURCE_NONE = 'none';

    public const BILLING_SOURCE_LAST_SALE = 'last_sale_snapshot';

    public const BILLING_SOURCE_IMPORTED_PROFILE = 'imported_billing_profile';

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
            ->when($phoneQuery !== '', function ($builder) use ($query, $phoneQuery): void {
                $builder->where(function ($inner) use ($query, $phoneQuery): void {
                    $inner->where('phone', 'like', '%'.$phoneQuery.'%')
                        ->orWhere('name', 'like', '%'.$query.'%');
                });
            }, function ($builder) use ($query): void {
                $builder->where('name', 'like', '%'.$query.'%');
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
     * @return array{
     *     found: bool,
     *     id?: int,
     *     name?: string,
     *     phone?: string,
     *     email?: ?string,
     *     gstin?: ?string,
     *     billing_address?: ?string,
     *     billing_city?: ?string,
     *     billing_state?: ?string,
     *     billing_pincode?: ?string,
     *     place_of_supply_state?: ?string,
     *     billing_source?: string,
     *     place_of_supply_source?: string
     * }
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
     * @return array{
     *     found: bool,
     *     id?: int,
     *     name?: string,
     *     phone?: string,
     *     email?: ?string,
     *     gstin?: ?string,
     *     billing_address?: ?string,
     *     billing_city?: ?string,
     *     billing_state?: ?string,
     *     billing_pincode?: ?string,
     *     place_of_supply_state?: ?string,
     *     billing_source?: string,
     *     place_of_supply_source?: string
     * }
     */
    public function resolveById(int $customerId): array
    {
        $customer = InventoryCustomer::query()->find($customerId);

        return $customer ? $this->payloadForCustomer($customer) : ['found' => false];
    }

    /**
     * @return array{
     *     found: true,
     *     id: int,
     *     name: string,
     *     phone: string,
     *     email: ?string,
     *     gstin: ?string,
     *     billing_address: ?string,
     *     billing_city: ?string,
     *     billing_state: ?string,
     *     billing_pincode: ?string,
     *     place_of_supply_state: ?string,
     *     billing_source: string,
     *     place_of_supply_source: string
     * }
     */
    private function payloadForCustomer(InventoryCustomer $customer): array
    {
        $snapshot = $this->billingSnapshot($customer);

        return [
            'found' => true,
            'id' => $customer->id,
            'name' => $customer->name,
            'phone' => $customer->phone,
            'email' => $customer->email,
            'gstin' => $customer->gstin,
            'billing_address' => $snapshot['billing_address'],
            'billing_city' => $snapshot['billing_city'],
            'billing_state' => $snapshot['billing_state'],
            'billing_pincode' => $snapshot['billing_pincode'],
            'place_of_supply_state' => $snapshot['place_of_supply_state'],
            'billing_source' => $snapshot['billing_source'],
            'place_of_supply_source' => $snapshot['place_of_supply_source'],
        ];
    }

    /**
     * Identity comes from inventory_customers. Billing prefers the latest completed
     * Desk sale snapshot. Imported Admin last-invoiced POS userdetails is a last-known
     * profile only when no Desk sale snapshot exists. Place of supply is never stored
     * on the profile: B2B uses billing state when valid; otherwise the counter default.
     *
     * @return array{
     *     billing_address: ?string,
     *     billing_city: ?string,
     *     billing_state: ?string,
     *     billing_pincode: ?string,
     *     place_of_supply_state: ?string,
     *     billing_source: string,
     *     place_of_supply_source: string
     * }
     */
    private function billingSnapshot(InventoryCustomer $customer): array
    {
        $saleSnapshot = $this->latestSaleSnapshot($customer);
        if ($saleSnapshot['billing_source'] === self::BILLING_SOURCE_LAST_SALE
            || $saleSnapshot['place_of_supply_source'] === self::BILLING_SOURCE_LAST_SALE) {
            return $saleSnapshot;
        }

        $profile = $customer->billingProfile;
        if ($profile === null) {
            return $saleSnapshot;
        }

        $address = $this->nullableString($profile->line1);
        $city = $this->nullableString($profile->city);
        $state = $this->nullableString($profile->state);
        $pincode = $this->nullableString($profile->pincode);
        $hasAddress = $address !== null || $city !== null || $state !== null || $pincode !== null;
        if (! $hasAddress) {
            return $saleSnapshot;
        }

        $place = null;
        $placeSource = self::BILLING_SOURCE_NONE;
        $gstin = $this->nullableString($customer->gstin);
        if ($gstin !== null && $state !== null && IndianStates::contains($state)) {
            $place = $state;
            $placeSource = 'billing_state';
        }

        return [
            'billing_address' => $address,
            'billing_city' => $city,
            'billing_state' => $state,
            'billing_pincode' => $pincode,
            'place_of_supply_state' => $place,
            'billing_source' => self::BILLING_SOURCE_IMPORTED_PROFILE,
            'place_of_supply_source' => $placeSource,
        ];
    }

    /**
     * @return array{
     *     billing_address: ?string,
     *     billing_city: ?string,
     *     billing_state: ?string,
     *     billing_pincode: ?string,
     *     place_of_supply_state: ?string,
     *     billing_source: string,
     *     place_of_supply_source: string
     * }
     */
    private function latestSaleSnapshot(InventoryCustomer $customer): array
    {
        $empty = [
            'billing_address' => null,
            'billing_city' => null,
            'billing_state' => null,
            'billing_pincode' => null,
            'place_of_supply_state' => null,
            'billing_source' => self::BILLING_SOURCE_NONE,
            'place_of_supply_source' => self::BILLING_SOURCE_NONE,
        ];

        $sale = InventorySale::query()
            ->where('customer_id', $customer->id)
            ->where('status', InventorySaleStatus::Completed)
            ->latest('completed_at')
            ->first();

        if ($sale === null) {
            return $empty;
        }

        $structured = is_array($sale->billing_address_structured) ? $sale->billing_address_structured : [];
        $address = $this->nullableString($sale->billing_address) ?? $this->nullableString($structured['line1'] ?? null);
        $city = $this->nullableString($structured['city'] ?? null);
        $state = $this->nullableString($structured['state'] ?? null);
        $pincode = $this->nullableString($structured['pincode'] ?? null);
        $place = $this->nullableString($sale->place_of_supply_state);
        $hasAddress = $address !== null || $city !== null || $state !== null || $pincode !== null;

        return [
            'billing_address' => $address,
            'billing_city' => $city,
            'billing_state' => $state,
            'billing_pincode' => $pincode,
            'place_of_supply_state' => $place,
            'billing_source' => $hasAddress ? self::BILLING_SOURCE_LAST_SALE : self::BILLING_SOURCE_NONE,
            'place_of_supply_source' => $place !== null ? self::BILLING_SOURCE_LAST_SALE : self::BILLING_SOURCE_NONE,
        ];
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $value = trim($value);

        return $value === '' ? null : $value;
    }
}
