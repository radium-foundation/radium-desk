<?php

namespace App\Services\HardwareFulfilment;

use App\Enums\HardwareFulfilmentSerialStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\InventoryMovementType;
use App\Enums\InventorySerialStatus;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Support\HardwareFulfilment\HardwareConfigurableVariantDisplay;
use App\Support\Inventory\InventoryBranchScope;
use App\Support\Inventory\InventorySerialNumber;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

class HardwareSerialAllocationService
{
    public const STOCK_BRANCH_DELHI = 'DELHI-RETAIL';

    public const STOCK_BRANCH_MUMBAI = 'MUMBAI';

    /**
     * @var list<string>
     */
    public const STOCK_BRANCH_CODES = [
        self::STOCK_BRANCH_DELHI,
        self::STOCK_BRANCH_MUMBAI,
    ];

    public function __construct(
        private readonly HardwareFulfilmentWorkflowService $workflow,
        private readonly HardwareSkuMapService $skuMap,
        private readonly InventoryStockService $stock,
    ) {}

    /**
     * @return list<array{
     *     commerce_order_item_id: int,
     *     line_no: int|null,
     *     qty: int,
     *     description: string,
     *     sku: string|null,
     *     catalog_sku: string|null,
     *     model_id: int|null,
     *     rdserviceid: int|null,
     *     inventory_product_id: int|null,
     *     inventory_sku: string|null,
     *     map_ready: bool,
     *     available_qty: int,
     *     available_by_branch: array<string, int>,
     *     allocated_qty: int,
     *     allocated_serials: list<string>
     * }>
     */
    public function requirements(HardwareFulfilment $fulfilment): array
    {
        $order = $this->requireOrder($fulfilment);
        $lockedBranch = $this->storedStockBranch($fulfilment);
        $fulfilment->loadMissing('serials');
        $allocatedByItem = $fulfilment->serials
            ->where('status', HardwareFulfilmentSerialStatus::Allocated)
            ->groupBy(fn (HardwareFulfilmentSerial $row): int => (int) $row->commerce_order_item_id);
        $lines = [];

        foreach ($this->physicalItems($order) as $item) {
            $product = $item->model_id !== null
                ? $this->skuMap->findProduct($order->channel, (int) $item->model_id)
                : null;
            $byBranch = [];
            foreach (self::STOCK_BRANCH_CODES as $code) {
                $byBranch[$code] = 0;
            }
            if ($product !== null) {
                $counts = InventorySerial::query()
                    ->selectRaw('inventory_branches.code as branch_code, count(*) as available_qty')
                    ->join('inventory_branches', 'inventory_branches.id', '=', 'inventory_serials.branch_id')
                    ->where('inventory_serials.product_id', $product->id)
                    ->where('inventory_serials.status', InventorySerialStatus::Available)
                    ->whereIn('inventory_branches.code', self::STOCK_BRANCH_CODES)
                    ->where('inventory_branches.is_active', true)
                    ->groupBy('inventory_branches.code')
                    ->pluck('available_qty', 'branch_code');
                foreach ($counts as $code => $qty) {
                    $byBranch[(string) $code] = (int) $qty;
                }
            }

            $available = $lockedBranch !== null
                ? ($byBranch[$lockedBranch->code] ?? 0)
                : array_sum($byBranch);
            $allocated = $allocatedByItem->get((int) $item->id, collect());

            $lines[] = [
                'commerce_order_item_id' => (int) $item->id,
                'line_no' => $item->line_no !== null ? (int) $item->line_no : null,
                'qty' => (int) $item->qty,
                'description' => HardwareConfigurableVariantDisplay::label($item),
                'sku' => $item->sku,
                'catalog_sku' => $item->catalog_sku,
                'model_id' => $item->model_id !== null ? (int) $item->model_id : null,
                'rdserviceid' => $item->rdserviceid !== null ? (int) $item->rdserviceid : null,
                'inventory_product_id' => $product?->id,
                'inventory_sku' => $product?->sku,
                'map_ready' => $product !== null,
                'available_qty' => $available,
                'available_by_branch' => $byBranch,
                'allocated_qty' => $allocated->count(),
                'allocated_serials' => $allocated
                    ->pluck('serial_number')
                    ->filter()
                    ->values()
                    ->all(),
            ];
        }

        return $lines;
    }

    /**
     * @return list<array{id: int, serial_number: string, product_id: int, sku: string|null, product_name: string|null, branch_id: int, branch_code: string}>
     */
    public function searchAvailable(
        HardwareFulfilment $fulfilment,
        int $commerceOrderItemId,
        string $query = '',
        int $limit = 20,
        ?string $branchCode = null,
        ?User $actor = null,
    ): array {
        $this->assertNotFrozen($fulfilment);
        $branches = $this->resolveSearchBranches($fulfilment, $branchCode, $actor);
        $order = $this->requireOrder($fulfilment);
        $item = $this->requirePhysicalItem($order, $commerceOrderItemId);
        $product = $this->skuMap->requireProductForItem($order->channel, $item);
        $q = trim($query);

        $serials = InventorySerial::query()
            ->with(['product', 'branch'])
            ->where('product_id', $product->id)
            ->whereIn('branch_id', array_map(
                static fn (InventoryBranch $branch): int => (int) $branch->id,
                $branches,
            ))
            ->where('status', InventorySerialStatus::Available)
            ->when($q !== '', fn ($builder) => $builder->where('serial_number', 'like', '%'.$q.'%'))
            ->orderBy('serial_number')
            ->limit(max(1, min($limit, 50)))
            ->get();

        return $serials->map(static fn (InventorySerial $serial): array => [
            'id' => $serial->id,
            'serial_number' => (string) $serial->serial_number,
            'product_id' => (int) $serial->product_id,
            'sku' => $serial->product?->sku,
            'product_name' => $serial->product?->name,
            'branch_id' => (int) $serial->branch_id,
            'branch_code' => (string) ($serial->branch?->code ?? ''),
        ])->values()->all();
    }

    /**
     * @param  array<int, list<string>|string>  $serialsByItemId
     */
    public function allocate(
        HardwareFulfilment $fulfilment,
        array $serialsByItemId,
        User $actor,
        ?string $claimedBranchCode = null,
    ): HardwareFulfilment {
        $this->assertNotFrozen($fulfilment);

        return DB::transaction(function () use ($fulfilment, $serialsByItemId, $actor, $claimedBranchCode): HardwareFulfilment {
            $locked = HardwareFulfilment::query()
                ->whereKey($fulfilment->id)
                ->lockForUpdate()
                ->with(['commerceOrder.items', 'serials'])
                ->firstOrFail();

            $this->assertNotFrozen($locked);

            if ($locked->state === HardwareFulfilmentState::SerialsAllocated) {
                return $this->idempotentAllocated($locked, $serialsByItemId);
            }

            if ($this->allocationIsImmutable($locked)) {
                throw ValidationException::withMessages([
                    'serials' => 'Allocated serials are immutable after invoice issuance.',
                ]);
            }

            if (($locked->state?->rank() ?? -1) >= HardwareFulfilmentState::ShipmentCreated->rank()) {
                throw ValidationException::withMessages([
                    'serials' => 'Hardware fulfilment already shipped cannot receive serial allocation.',
                ]);
            }

            $this->workflow->assertCanAllocateSerials($locked);
            $order = $this->requireOrder($locked);
            $items = $this->physicalItems($order);
            $this->assertNoPricedServiceCompanion($order);
            $selections = $this->normalizeSelections($items, $serialsByItemId);
            $branch = $this->establishFulfilmentBranch($locked, $items, $selections, $order, $actor, $claimedBranchCode);

            $created = [];
            foreach ($items as $item) {
                $product = $this->skuMap->requireProductForItem($order->channel, $item);
                $numbers = $selections[(int) $item->id];
                $stockSerials = $this->stock->lockAvailableSerialsForSale($product, $branch, $numbers);
                $byNumber = [];
                foreach ($stockSerials as $serial) {
                    $byNumber[InventorySerialNumber::normalize((string) $serial->serial_number)] = $serial;
                }

                foreach ($numbers as $position => $number) {
                    $stockSerial = $byNumber[$number] ?? null;
                    if ($stockSerial === null) {
                        throw ValidationException::withMessages([
                            'serials' => "Serial {$number} was not locked for this fulfilment.",
                        ]);
                    }

                    $this->assertSerialNotAllocatedElsewhere($stockSerial, $number);
                    $created[] = [$item, $position + 1, $stockSerial, $product];
                }
            }

            foreach ($created as [$item, $position, $stockSerial, $product]) {
                HardwareFulfilmentSerial::query()->create([
                    'hardware_fulfilment_id' => $locked->id,
                    'commerce_order_item_id' => $item->id,
                    'line_no' => $item->line_no,
                    'position' => $position,
                    'inventory_serial_id' => $stockSerial->id,
                    'serial_number' => InventorySerialNumber::normalize((string) $stockSerial->serial_number),
                    'status' => HardwareFulfilmentSerialStatus::Allocated,
                    'allocated_at' => now(),
                ]);

                $this->stock->markSerialSold($stockSerial, $branch);
                $this->stock->recordMovement(
                    type: InventoryMovementType::Sale,
                    product: $product,
                    branch: $branch,
                    qty: -1,
                    actor: $actor,
                    variant: $stockSerial->variant,
                    serial: $stockSerial,
                    fromStatus: InventorySerialStatus::Available,
                    toStatus: InventorySerialStatus::Sold,
                    notes: 'hardware_fulfilment:'.$locked->id,
                );
            }

            $allocated = $this->workflow->allocatedSerialNumbers($locked->fresh() ?? $locked);
            if (count($allocated) !== $this->requiredQuantity($items)) {
                throw ValidationException::withMessages([
                    'serials' => 'Partial serial allocation cannot mark SERIALS_ALLOCATED.',
                ]);
            }

            return $this->workflow->transition(
                $locked,
                HardwareFulfilmentState::SerialsAllocated,
                actorType: 'user',
                actorId: $actor->id,
                payload: [
                    'reason' => 'hardware_serials_allocated',
                    'serials' => $allocated,
                ],
            );
        });
    }

    /**
     * @param  list<string>  $serialNumbers
     */
    public function allocateSerials(
        HardwareFulfilment $fulfilment,
        array $serialNumbers,
        User $actor,
        ?string $claimedBranchCode = null,
    ): HardwareFulfilment {
        $this->assertNotFrozen($fulfilment);
        $order = $this->requireOrder($fulfilment);
        $items = $this->physicalItems($order);
        if (count($items) !== 1) {
            throw ValidationException::withMessages([
                'serials' => 'Multi-line hardware orders must allocate serials per commerce order item.',
            ]);
        }

        return $this->allocate($fulfilment, [(int) $items[0]->id => $serialNumbers], $actor, $claimedBranchCode);
    }

    private function idempotentAllocated(HardwareFulfilment $fulfilment, array $serialsByItemId): HardwareFulfilment
    {
        $existing = array_map(
            static fn (string $serial): string => InventorySerialNumber::normalize($serial),
            $this->workflow->allocatedSerialNumbers($fulfilment),
        );
        $requested = $this->flattenRequested($serialsByItemId);
        if ($requested !== [] && $this->sorted($requested) !== $this->sorted($existing)) {
            throw ValidationException::withMessages([
                'serials' => 'Retry must reuse the existing allocated serials. Re-allocation is not allowed.',
            ]);
        }

        return $fulfilment;
    }

    private function allocationIsImmutable(HardwareFulfilment $fulfilment): bool
    {
        if ($fulfilment->state === HardwareFulfilmentState::InvoiceIssued) {
            return true;
        }

        if (($fulfilment->state?->rank() ?? -1) >= HardwareFulfilmentState::InvoiceIssued->rank()) {
            return true;
        }

        return isset($fulfilment->metadata['invoice_serials']);
    }

    /**
     * @param  list<CommerceOrderItem>  $items
     * @param  array<int, list<string>|string>  $serialsByItemId
     * @return array<int, list<string>>
     */
    private function normalizeSelections(array $items, array $serialsByItemId): array
    {
        $all = [];
        $out = [];

        foreach ($items as $item) {
            $raw = $serialsByItemId[(int) $item->id] ?? null;
            if ($raw === null) {
                throw ValidationException::withMessages([
                    'serials' => sprintf(
                        'Too few serials: item %d requires %d serials.',
                        $item->id,
                        (int) $item->qty,
                    ),
                ]);
            }

            $rawList = is_array($raw) ? $raw : [$raw];
            $seen = [];
            foreach ($rawList as $candidate) {
                if (! is_string($candidate)) {
                    continue;
                }
                $normalized = InventorySerialNumber::normalize($candidate);
                if ($normalized === '') {
                    continue;
                }
                if (isset($seen[$normalized])) {
                    throw ValidationException::withMessages([
                        'serials' => 'Duplicate serial assignment within the same fulfilment is rejected.',
                    ]);
                }
                $seen[$normalized] = true;
            }

            $numbers = InventorySerialNumber::parseList($raw);
            if (count($numbers) < (int) $item->qty) {
                throw ValidationException::withMessages([
                    'serials' => sprintf(
                        'Too few serials: item %d requires %d serials.',
                        $item->id,
                        (int) $item->qty,
                    ),
                ]);
            }

            if (count($numbers) > (int) $item->qty) {
                throw ValidationException::withMessages([
                    'serials' => sprintf(
                        'Too many serials: item %d accepts exactly %d serials.',
                        $item->id,
                        (int) $item->qty,
                    ),
                ]);
            }

            foreach ($numbers as $number) {
                if (isset($all[$number])) {
                    throw ValidationException::withMessages([
                        'serials' => 'Duplicate serial assignment within the same fulfilment is rejected.',
                    ]);
                }
                $all[$number] = true;
            }

            $out[(int) $item->id] = $numbers;
        }

        $unexpected = array_diff(array_map('intval', array_keys($serialsByItemId)), array_map('intval', array_keys($out)));
        if ($unexpected !== []) {
            throw ValidationException::withMessages([
                'serials' => 'Serials were submitted for a commerce line that is not a physical hardware item.',
            ]);
        }

        return $out;
    }

    /**
     * @param  array<int, list<string>|string>  $serialsByItemId
     * @return list<string>
     */
    private function flattenRequested(array $serialsByItemId): array
    {
        $out = [];
        foreach ($serialsByItemId as $raw) {
            foreach (InventorySerialNumber::parseList($raw) as $number) {
                $out[] = $number;
            }
        }

        return $out;
    }

    /**
     * @param  list<string>  $serials
     * @return list<string>
     */
    private function sorted(array $serials): array
    {
        $copy = $serials;
        sort($copy, SORT_STRING);

        return array_values($copy);
    }

    private function assertSerialNotAllocatedElsewhere(InventorySerial $serial, string $number): void
    {
        $taken = HardwareFulfilmentSerial::query()
            ->where(function ($query) use ($serial, $number) {
                $query->where('inventory_serial_id', $serial->id)
                    ->orWhere('serial_number', $number);
            })
            ->exists();

        if ($taken) {
            throw ValidationException::withMessages([
                'serials' => "Serial {$number} is already allocated to a hardware fulfilment.",
            ]);
        }
    }

    /**
     * @return list<CommerceOrderItem>
     */
    private function physicalItems(CommerceOrder $order): array
    {
        $items = [];
        foreach ($order->items as $item) {
            if (HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                $items[] = $item;
            }
        }

        if ($items === []) {
            throw ValidationException::withMessages([
                'serials' => 'Hardware serial allocation requires at least one physical merchandise line.',
            ]);
        }

        return $items;
    }

    /**
     * @param  list<CommerceOrderItem>  $items
     */
    private function requiredQuantity(array $items): int
    {
        $qty = 0;
        foreach ($items as $item) {
            $qty += (int) $item->qty;
        }

        return $qty;
    }

    private function requirePhysicalItem(CommerceOrder $order, int $itemId): CommerceOrderItem
    {
        foreach ($this->physicalItems($order) as $item) {
            if ((int) $item->id === $itemId) {
                return $item;
            }
        }

        throw ValidationException::withMessages([
            'serials' => 'Commerce line is not a physical hardware item on this fulfilment.',
        ]);
    }

    private function requireOrder(HardwareFulfilment $fulfilment): CommerceOrder
    {
        $order = $fulfilment->commerceOrder;
        if ($order === null) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Hardware fulfilment is missing its commerce order.',
            ]);
        }

        $order->loadMissing('items');

        return $order;
    }

    /**
     * Physical stock on the selected serials is the only source of fulfilment branch.
     * Customer / order / GST state is never consulted.
     *
     * @param  list<CommerceOrderItem>  $items
     * @param  array<int, list<string>>  $selections
     */
    private function establishFulfilmentBranch(
        HardwareFulfilment $fulfilment,
        array $items,
        array $selections,
        CommerceOrder $order,
        User $actor,
        ?string $claimedBranchCode,
    ): InventoryBranch {
        $expectedByNumber = [];
        $allNumbers = [];
        foreach ($items as $item) {
            $product = $this->skuMap->requireProductForItem($order->channel, $item);
            foreach ($selections[(int) $item->id] as $number) {
                $allNumbers[] = $number;
                $expectedByNumber[$number] = $product;
            }
        }

        if ($allNumbers === []) {
            throw ValidationException::withMessages([
                'serials' => 'No serials selected.',
            ]);
        }

        $ordered = $allNumbers;
        sort($ordered, SORT_STRING);

        $branchIds = [];
        foreach ($ordered as $number) {
            $serial = $this->stock->lockSerialByNumber($number);
            $this->assertSerialNotAllocatedElsewhere($serial, $number);
            $this->assertSelectedSerialEligible($serial, $number, $expectedByNumber[$number]);
            $branchIds[(int) $serial->branch_id] = true;
        }

        if (count($branchIds) !== 1) {
            throw ValidationException::withMessages([
                'serials' => 'Selected serials belong to multiple physical stock branches. One hardware fulfilment cannot mix Delhi and Mumbai stock.',
            ]);
        }

        $physical = $this->requireSupportedStockBranch((int) array_key_first($branchIds));
        $claimed = $this->normalizeClaimedBranchCode($claimedBranchCode);
        if ($claimed !== null && $claimed !== $physical->code) {
            throw ValidationException::withMessages([
                'branch' => sprintf(
                    'Selected serials are not at the claimed stock branch %s. The allocation transaction uses inventory_serials.branch_id, not the UI filter.',
                    $claimed,
                ),
            ]);
        }

        $stored = $this->storedStockBranch($fulfilment);
        if ($stored !== null && (int) $stored->id !== (int) $physical->id) {
            throw ValidationException::withMessages([
                'serials' => sprintf('Selected serials are not available at %s.', $stored->code),
            ]);
        }

        InventoryBranchScope::assertCanOperate($actor, $physical);

        if ((int) $fulfilment->fulfilment_branch_id !== (int) $physical->id) {
            $fulfilment->forceFill(['fulfilment_branch_id' => $physical->id])->save();
        }

        return $physical;
    }

    private function assertSelectedSerialEligible(
        InventorySerial $serial,
        string $number,
        InventoryProduct $expected,
    ): void {
        if ((int) $serial->product_id !== (int) $expected->id) {
            throw ValidationException::withMessages([
                'serials' => "Serial {$number} belongs to the wrong product.",
            ]);
        }

        if ($serial->status === InventorySerialStatus::Sold) {
            throw ValidationException::withMessages([
                'serials' => "Serial {$number} is already sold.",
            ]);
        }

        if ($serial->status !== InventorySerialStatus::Available) {
            throw ValidationException::withMessages([
                'serials' => "Serial {$number} is unavailable.",
            ]);
        }

        $branch = $serial->branch;
        if ($branch === null || ! $branch->is_active || ! in_array($branch->code, self::STOCK_BRANCH_CODES, true)) {
            throw ValidationException::withMessages([
                'branch' => "Serial {$number} belongs to an inactive or unsupported stock branch.",
            ]);
        }
    }

    /**
     * Search may optionally narrow by stock location. The operator cannot choose
     * the fulfilment branch; allocation writes it from inventory_serials.branch_id.
     *
     * @return list<InventoryBranch>
     */
    private function resolveSearchBranches(
        HardwareFulfilment $fulfilment,
        ?string $branchCode,
        ?User $actor,
    ): array {
        $stored = $this->storedStockBranch($fulfilment);
        $filter = $this->normalizeClaimedBranchCode($branchCode);

        if ($stored !== null) {
            if ($filter !== null && $filter !== $stored->code) {
                throw ValidationException::withMessages([
                    'branch' => 'Search branch filter must match the stored fulfilment stock branch.',
                ]);
            }
            if ($actor !== null) {
                InventoryBranchScope::assertCanOperate($actor, $stored);
            }

            return [$stored];
        }

        if ($filter !== null) {
            $branch = $this->requireSupportedStockBranchByCode($filter);
            if ($actor !== null) {
                InventoryBranchScope::assertCanOperate($actor, $branch);
            }

            return [$branch];
        }

        $branches = [];
        foreach (self::STOCK_BRANCH_CODES as $code) {
            $branch = InventoryBranch::query()
                ->where('code', $code)
                ->where('is_active', true)
                ->first();
            if ($branch === null) {
                continue;
            }
            if ($actor !== null && ! InventoryBranchScope::allows($actor, $branch)) {
                continue;
            }
            $branches[] = $branch;
        }

        if ($branches === []) {
            throw ValidationException::withMessages([
                'branch' => 'No supported hardware stock branch is available to search.',
            ]);
        }

        return $branches;
    }

    private function storedStockBranch(HardwareFulfilment $fulfilment): ?InventoryBranch
    {
        if ($fulfilment->fulfilment_branch_id === null) {
            return null;
        }

        $branch = InventoryBranch::query()->find($fulfilment->fulfilment_branch_id);
        if ($branch === null || ! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch' => 'Hardware fulfilment branch is missing or inactive.',
            ]);
        }

        return $branch;
    }

    private function requireSupportedStockBranch(int $branchId): InventoryBranch
    {
        $branch = InventoryBranch::query()->find($branchId);
        if ($branch === null || ! $branch->is_active || ! in_array($branch->code, self::STOCK_BRANCH_CODES, true)) {
            throw ValidationException::withMessages([
                'branch' => 'Selected serials belong to an inactive or unsupported stock branch.',
            ]);
        }

        return $branch;
    }

    private function requireSupportedStockBranchByCode(string $code): InventoryBranch
    {
        $branch = InventoryBranch::query()->where('code', $code)->first();
        if ($branch === null || ! $branch->is_active) {
            throw ValidationException::withMessages([
                'branch' => 'Hardware stock branch is missing or inactive.',
            ]);
        }

        if (! in_array($branch->code, self::STOCK_BRANCH_CODES, true)) {
            throw ValidationException::withMessages([
                'branch' => 'Invalid branch. Hardware stock branches are DELHI-RETAIL and MUMBAI only.',
            ]);
        }

        return $branch;
    }

    private function normalizeClaimedBranchCode(?string $code): ?string
    {
        $trimmed = strtoupper(trim((string) $code));
        if ($trimmed === '') {
            return null;
        }

        return match ($trimmed) {
            self::STOCK_BRANCH_DELHI, 'DELHI' => self::STOCK_BRANCH_DELHI,
            self::STOCK_BRANCH_MUMBAI => self::STOCK_BRANCH_MUMBAI,
            default => throw ValidationException::withMessages([
                'branch' => 'Invalid branch. Hardware stock branches are DELHI-RETAIL and MUMBAI only.',
            ]),
        };
    }

    private function assertNotFrozen(HardwareFulfilment $fulfilment): void
    {
        if (HardwareFulfilmentEligibility::isFrozenForFulfilment((string) $fulfilment->source_id, $fulfilment->commerceOrder)) {
            throw ValidationException::withMessages([
                'fulfilment' => 'Frozen pending hardware orders cannot receive serial allocation.',
            ]);
        }
    }

    private function assertNoPricedServiceCompanion(CommerceOrder $order): void
    {
        foreach ($order->items as $item) {
            if (HardwareFulfilmentEligibility::isPhysicalCommerceItem($item)) {
                continue;
            }
            $hsn = trim((string) $item->hsn_sac);
            if ($hsn !== '' && str_starts_with($hsn, '99')
                && ((float) $item->unit_price > 0 || (float) $item->line_total > 0)) {
                throw ValidationException::withMessages([
                    'lines' => 'A second priced service line on a hardware order is not allocated. Bundled RD remains an annotation on the hardware line.',
                ]);
            }
        }
    }
}
