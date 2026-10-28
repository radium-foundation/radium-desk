<?php

namespace App\Services\Pos;

use App\Enums\InventorySaleStatus;
use App\Enums\ServiceOrderStatus;
use App\Enums\ServiceQuoteStatus;
use App\Models\InventoryCustomer;
use App\Models\InventorySale;
use App\Models\ServiceOrder;
use App\Models\ServiceQuote;
use Carbon\CarbonInterface;

final class PosCustomerLookupService
{
    private const MIN_QUERY_LENGTH = 2;

    private const SEARCH_LIMIT = 10;

    /** @var int Tie-break rank when timestamps are equal: service order > service quote > product sale. */
    private const SOURCE_RANK_ORDER = 3;

    private const SOURCE_RANK_QUOTE = 2;

    private const SOURCE_RANK_SALE = 1;

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
     * @return array{found: bool, id?: int, name?: string, phone?: string, email?: ?string, gstin?: ?string, billing_address?: ?string, billing_city?: ?string, billing_state?: ?string, billing_pincode?: ?string, place_of_supply_state?: ?string}
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
     * @return array{found: bool, id?: int, name?: string, phone?: string, email?: ?string, gstin?: ?string, billing_address?: ?string, billing_city?: ?string, billing_state?: ?string, billing_pincode?: ?string, place_of_supply_state?: ?string}
     */
    public function resolveById(int $customerId): array
    {
        $customer = InventoryCustomer::query()->find($customerId);

        return $customer ? $this->payloadForCustomer($customer) : ['found' => false];
    }

    /**
     * @return array{found: true, id: int, name: string, phone: string, email: ?string, gstin: ?string, billing_address: ?string, billing_city: ?string, billing_state: ?string, billing_pincode: ?string, place_of_supply_state: ?string}
     */
    private function payloadForCustomer(InventoryCustomer $customer): array
    {
        $snapshot = $this->latestBillingSnapshot($customer);

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
        ];
    }

    /**
     * @return array{billing_address: ?string, billing_city: ?string, billing_state: ?string, billing_pincode: ?string, place_of_supply_state: ?string}
     */
    private function latestBillingSnapshot(InventoryCustomer $customer): array
    {
        $candidates = [];

        $sale = InventorySale::query()
            ->where('customer_id', $customer->id)
            ->where('status', InventorySaleStatus::Completed)
            ->whereNotNull('completed_at')
            ->orderByDesc('completed_at')
            ->orderByDesc('id')
            ->first();

        if ($sale !== null) {
            $candidates[] = $this->candidate(
                $this->snapshotFromParts(
                    $sale->billing_address,
                    is_array($sale->billing_address_structured) ? $sale->billing_address_structured : null,
                    null,
                    $sale->place_of_supply_state,
                ),
                $sale->completed_at,
                self::SOURCE_RANK_SALE,
                $sale->id,
            );
        }

        $order = ServiceOrder::query()
            ->where('customer_id', $customer->id)
            ->where('status', '!=', ServiceOrderStatus::Cancelled)
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($order !== null) {
            $candidates[] = $this->candidate(
                $this->snapshotFromParts(
                    $order->billing_address,
                    is_array($order->billing_address_structured) ? $order->billing_address_structured : null,
                    $order->billing_state,
                    $order->place_of_supply_state,
                ),
                $order->updated_at,
                self::SOURCE_RANK_ORDER,
                $order->id,
            );
        }

        $quote = ServiceQuote::query()
            ->where('customer_id', $customer->id)
            ->whereNotIn('status', [ServiceQuoteStatus::Cancelled, ServiceQuoteStatus::Expired])
            ->orderByDesc('updated_at')
            ->orderByDesc('id')
            ->first();

        if ($quote !== null) {
            $candidates[] = $this->candidate(
                $this->snapshotFromParts(
                    $quote->billing_address,
                    is_array($quote->billing_address_structured) ? $quote->billing_address_structured : null,
                    $quote->billing_state,
                    $quote->place_of_supply_state,
                ),
                $quote->updated_at,
                self::SOURCE_RANK_QUOTE,
                $quote->id,
            );
        }

        if ($candidates === []) {
            return $this->emptyBillingSnapshot();
        }

        usort($candidates, static function (array $left, array $right): int {
            $atCompare = $right['at'] <=> $left['at'];
            if ($atCompare !== 0) {
                return $atCompare;
            }

            $rankCompare = $right['rank'] <=> $left['rank'];
            if ($rankCompare !== 0) {
                return $rankCompare;
            }

            return $right['id'] <=> $left['id'];
        });

        foreach ($candidates as $candidate) {
            if (! $this->isVacuousBillingSnapshot($candidate['snapshot'])) {
                return $candidate['snapshot'];
            }
        }

        return $this->emptyBillingSnapshot();
    }

    /**
     * @param  array<string, mixed>|null  $structured
     * @return array{billing_address: ?string, billing_city: ?string, billing_state: ?string, billing_pincode: ?string, place_of_supply_state: ?string}
     */
    private function snapshotFromParts(
        ?string $billingAddress,
        ?array $structured,
        ?string $billingStateColumn,
        ?string $placeOfSupply,
    ): array {
        $structured = is_array($structured) ? $structured : [];

        return [
            'billing_address' => $billingAddress ?: ($structured['line1'] ?? null),
            'billing_city' => $structured['city'] ?? null,
            'billing_state' => $structured['state'] ?? $billingStateColumn,
            'billing_pincode' => $structured['pincode'] ?? null,
            'place_of_supply_state' => $placeOfSupply,
        ];
    }

    /**
     * @param  array{billing_address: ?string, billing_city: ?string, billing_state: ?string, billing_pincode: ?string, place_of_supply_state: ?string}  $snapshot
     * @return array{snapshot: array{billing_address: ?string, billing_city: ?string, billing_state: ?string, billing_pincode: ?string, place_of_supply_state: ?string}, at: CarbonInterface, rank: int, id: int}
     */
    private function candidate(array $snapshot, CarbonInterface $at, int $rank, int $id): array
    {
        return [
            'snapshot' => $snapshot,
            'at' => $at,
            'rank' => $rank,
            'id' => $id,
        ];
    }

    /**
     * A transaction may exist without captured billing (e.g. identity-only draft). Such rows are
     * not treated as an intentional "empty address" for lookup; older non-vacuous snapshots remain eligible.
     *
     * @param  array{billing_address: ?string, billing_city: ?string, billing_state: ?string, billing_pincode: ?string, place_of_supply_state: ?string}  $snapshot
     */
    private function isVacuousBillingSnapshot(array $snapshot): bool
    {
        foreach (['billing_address', 'billing_city', 'billing_state', 'billing_pincode', 'place_of_supply_state'] as $key) {
            $value = $snapshot[$key] ?? null;
            if ($value !== null && trim((string) $value) !== '') {
                return false;
            }
        }

        return true;
    }

    /**
     * @return array{billing_address: null, billing_city: null, billing_state: null, billing_pincode: null, place_of_supply_state: null}
     */
    private function emptyBillingSnapshot(): array
    {
        return [
            'billing_address' => null,
            'billing_city' => null,
            'billing_state' => null,
            'billing_pincode' => null,
            'place_of_supply_state' => null,
        ];
    }
}
