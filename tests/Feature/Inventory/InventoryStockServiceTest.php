<?php

namespace Tests\Feature\Inventory;

use App\Enums\InventoryAdjustmentReason;
use App\Enums\InventoryMovementType;
use App\Enums\InventoryReservationStatus;
use App\Enums\InventorySerialStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class InventoryStockServiceTest extends TestCase
{
    use RefreshDatabase;

    private InventoryStockService $stock;

    private User $actor;

    private InventoryBranch $hq;

    private InventoryBranch $store;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->stock = app(InventoryStockService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->hq = InventoryBranch::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'is_active' => true,
        ]);
        $this->store = InventoryBranch::query()->create([
            'code' => 'BLR',
            'name' => 'Bengaluru',
            'is_active' => true,
        ]);
    }

    public function test_stock_in_creates_unique_available_serial_at_branch(): void
    {
        $product = $this->serializedProduct();

        $serials = $this->stock->stockInSerialized($product, $this->hq, ['abc-123', 'abc-123', ' def-456 '], $this->actor);

        $this->assertCount(2, $serials);
        $this->assertDatabaseHas('inventory_serials', [
            'serial_number' => 'ABC-123',
            'branch_id' => $this->hq->id,
            'status' => InventorySerialStatus::Available->value,
        ]);
        $this->assertSame(2, InventorySerial::query()->where('product_id', $product->id)->count());
        $this->assertDatabaseHas('inventory_stock_balances', [
            'product_id' => $product->id,
            'branch_id' => $this->hq->id,
            'available_qty' => 2,
            'reserved_qty' => 0,
        ]);
        $this->assertSame(2, InventoryMovement::query()->where('type', InventoryMovementType::StockIn)->count());
    }

    public function test_duplicate_serial_is_rejected(): void
    {
        $product = $this->serializedProduct();
        $this->stock->stockInSerialized($product, $this->hq, ['SN-1'], $this->actor);

        $this->expectException(ValidationException::class);
        $this->stock->stockInSerialized($product, $this->store, ['SN-1'], $this->actor);
    }

    public function test_transfer_relocates_serial_instead_of_cloning(): void
    {
        $product = $this->serializedProduct();
        $this->stock->stockInSerialized($product, $this->hq, ['MOVE-1'], $this->actor);

        $transfer = $this->stock->transferSerials($this->hq, $this->store, ['MOVE-1'], $this->actor, 'Counter restock');

        $this->assertSame(1, InventorySerial::query()->where('serial_number', 'MOVE-1')->count());
        $serial = InventorySerial::query()->where('serial_number', 'MOVE-1')->firstOrFail();
        $this->assertSame($this->store->id, $serial->branch_id);
        $this->assertSame(InventorySerialStatus::Available, $serial->status);
        $this->assertSame(0, (int) $product->balances()->where('branch_id', $this->hq->id)->value('available_qty'));
        $this->assertSame(1, (int) $product->balances()->where('branch_id', $this->store->id)->value('available_qty'));
        $this->assertSame($this->hq->id, $transfer->from_branch_id);
        $this->assertSame($this->store->id, $transfer->to_branch_id);
        $this->assertTrue(InventoryMovement::query()->where('type', InventoryMovementType::TransferOut)->where('from_branch_id', $this->hq->id)->exists());
        $this->assertTrue(InventoryMovement::query()->where('type', InventoryMovementType::TransferIn)->where('to_branch_id', $this->store->id)->exists());
    }

    public function test_cannot_transfer_serial_from_wrong_branch(): void
    {
        $product = $this->serializedProduct();
        $this->stock->stockInSerialized($product, $this->hq, ['STAY-1'], $this->actor);

        $this->expectException(ValidationException::class);
        $this->stock->transferSerials($this->store, $this->hq, ['STAY-1'], $this->actor);
    }

    public function test_reservation_holds_serial_and_release_restores_it(): void
    {
        $product = $this->serializedProduct();
        $this->stock->stockInSerialized($product, $this->hq, ['HOLD-1'], $this->actor);

        $reservation = $this->stock->reserveSerials($this->hq, ['HOLD-1'], $this->actor);
        $serial = InventorySerial::query()->where('serial_number', 'HOLD-1')->firstOrFail();

        $this->assertSame(InventorySerialStatus::Reserved, $serial->status);
        $this->assertSame(InventoryReservationStatus::Active, $reservation->status);
        $this->assertSame(0, (int) $product->balances()->where('branch_id', $this->hq->id)->value('available_qty'));
        $this->assertSame(1, (int) $product->balances()->where('branch_id', $this->hq->id)->value('reserved_qty'));

        $this->stock->releaseReservation($reservation, $this->actor);
        $serial->refresh();

        $this->assertSame(InventorySerialStatus::Available, $serial->status);
        $this->assertSame(1, (int) $product->balances()->where('branch_id', $this->hq->id)->value('available_qty'));
    }

    public function test_quantity_stock_in_transfer_and_adjustment(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'CABLE-01',
            'name' => 'USB Cable',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        $this->stock->stockInQuantity($product, $this->hq, 10, $this->actor);
        $this->stock->transferQuantity($product, $this->hq, $this->store, 4, $this->actor);
        $this->stock->adjustQuantity($product, $this->store, -1, InventoryAdjustmentReason::Damage, $this->actor, notes: 'Broken in transit');

        $this->assertSame(6, (int) $product->balances()->where('branch_id', $this->hq->id)->value('available_qty'));
        $this->assertSame(3, (int) $product->balances()->where('branch_id', $this->store->id)->value('available_qty'));
    }

    public function test_variant_stock_in_is_tracked_on_the_variant_balance(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'CABLE-VAR',
            'name' => 'USB Cable variants',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $variant = $product->variants()->create([
            'sku' => 'CABLE-VAR-2M',
            'name' => '2 metre',
            'unit_price' => 140,
            'is_active' => true,
        ]);

        $this->stock->stockInQuantity($product, $this->hq, 7, $this->actor, $variant);

        $this->assertDatabaseHas('inventory_stock_balances', [
            'product_id' => $product->id,
            'variant_id' => $variant->id,
            'branch_id' => $this->hq->id,
            'available_qty' => 7,
        ]);
    }

    public function test_quantity_cannot_go_negative(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'CABLE-02',
            'name' => 'USB Cable 2',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => false,
            'is_active' => true,
        ]);
        $this->stock->stockInQuantity($product, $this->hq, 1, $this->actor);

        $this->expectException(ValidationException::class);
        $this->stock->transferQuantity($product, $this->hq, $this->store, 2, $this->actor);
    }

    public function test_serial_damage_adjustment_writes_audit_trail(): void
    {
        $product = $this->serializedProduct();
        $created = $this->stock->stockInSerialized($product, $this->hq, ['DMG-1'], $this->actor);
        $adjustment = $this->stock->adjustSerialStatus(
            $created[0],
            InventorySerialStatus::Damaged,
            InventoryAdjustmentReason::Damage,
            $this->actor,
            'Screen crack',
        );

        $serial = $created[0]->fresh();
        $this->assertSame(InventorySerialStatus::Damaged, $serial->status);
        $this->assertSame(0, (int) $product->balances()->where('branch_id', $this->hq->id)->value('available_qty'));
        $this->assertSame('Screen crack', $adjustment->notes);
        $this->assertTrue(InventoryMovement::query()->where('adjustment_id', $adjustment->id)->exists());
    }

    public function test_sale_lock_rejects_a_variant_serial_without_that_variant(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'SCAN-LOCK',
            'name' => 'Scanner lock',
            'gst_percentage' => 18,
            'unit_price' => 2500,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $variant = $product->variants()->create([
            'sku' => 'SCAN-LOCK-BLK',
            'name' => 'Black',
            'is_active' => true,
        ]);
        $this->stock->stockInSerialized($product, $this->hq, ['LOCK-VAR-1'], $this->actor, $variant);

        $this->expectException(ValidationException::class);
        $this->stock->lockAvailableSerialsForSale($product, $this->hq, ['LOCK-VAR-1']);
    }

    private function serializedProduct(): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => 'MFS110',
            'name' => 'Mantra MFS110',
            'gst_percentage' => 18,
            'unit_price' => 2500,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }
}
