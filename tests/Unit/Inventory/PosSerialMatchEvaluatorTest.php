<?php

namespace Tests\Unit\Inventory;

use App\Enums\InventorySerialStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySerial;
use App\Support\Inventory\PosSerialMatchEvaluator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosSerialMatchEvaluatorTest extends TestCase
{
    use RefreshDatabase;

    public function test_available_serial_for_product_and_branch(): void
    {
        [$branch, $product] = $this->seedProduct('RBUGR89GPS');
        $serial = $this->seedSerial($product, $branch, 'H22057-MP826D817041603-09/26');

        $results = app(PosSerialMatchEvaluator::class)->evaluate(
            $branch,
            $product,
            null,
            ['h22057-mp826d817041603-09/26'],
        );

        $this->assertSame([
            [
                'input' => 'H22057-MP826D817041603-09/26',
                'serial_number' => $serial->serial_number,
                'status' => 'available',
                'message' => null,
            ],
        ], $results);
    }

    public function test_batch_evaluates_multiple_serials_in_one_query(): void
    {
        [$branch, $product] = $this->seedProduct('RBUGR89GPS');
        $this->seedSerial($product, $branch, 'H22057-MP826D817041603-09/26');
        $this->seedSerial($product, $branch, 'H22028-MP826D817043063-09/26');

        $results = app(PosSerialMatchEvaluator::class)->evaluate(
            $branch,
            $product,
            null,
            [
                'H22057-MP826D817041603-09/26',
                'H22028-MP826D817043063-09/26',
                'MISSING-1',
            ],
        );

        $this->assertSame('available', $results[0]['status']);
        $this->assertSame('available', $results[1]['status']);
        $this->assertSame('not_found', $results[2]['status']);
    }

    public function test_wrong_branch_serial_is_reported(): void
    {
        [$branch, $product] = $this->seedProduct('RBUGR89GPS');
        $otherBranch = InventoryBranch::query()->create([
            'code' => 'OTHER',
            'name' => 'Other branch',
            'is_active' => true,
        ]);
        $this->seedSerial($product, $otherBranch, 'H22031-MP826D817043071-09/26');

        $results = app(PosSerialMatchEvaluator::class)->evaluate(
            $branch,
            $product,
            null,
            ['H22031-MP826D817043071-09/26'],
        );

        $this->assertSame('wrong_branch', $results[0]['status']);
    }

    public function test_sold_serial_is_unavailable(): void
    {
        [$branch, $product] = $this->seedProduct('RBUGR89GPS');
        InventorySerial::query()->create([
            'product_id' => $product->id,
            'serial_number' => 'H22057-MP826D817041603-09/26',
            'branch_id' => $branch->id,
            'status' => InventorySerialStatus::Sold,
        ]);

        $results = app(PosSerialMatchEvaluator::class)->evaluate(
            $branch,
            $product,
            null,
            ['H22057-MP826D817041603-09/26'],
        );

        $this->assertSame('unavailable', $results[0]['status']);
    }

    public function test_reserved_serial_is_unavailable(): void
    {
        [$branch, $product] = $this->seedProduct('RBUGR89GPS');
        InventorySerial::query()->create([
            'product_id' => $product->id,
            'serial_number' => 'H22057-MP826D817041603-09/26',
            'branch_id' => $branch->id,
            'status' => InventorySerialStatus::Reserved,
        ]);

        $results = app(PosSerialMatchEvaluator::class)->evaluate(
            $branch,
            $product,
            null,
            ['H22057-MP826D817041603-09/26'],
        );

        $this->assertSame('unavailable', $results[0]['status']);
    }

    public function test_wrong_product_serial_is_not_found_for_selected_product(): void
    {
        [$branch, $product] = $this->seedProduct('RBUGR89GPS');
        [, $otherProduct] = $this->seedProduct('RBOTHERGPS', $branch);
        $this->seedSerial($otherProduct, $branch, 'H22057-MP826D817041603-09/26');

        $results = app(PosSerialMatchEvaluator::class)->evaluate(
            $branch,
            $product,
            null,
            ['H22057-MP826D817041603-09/26'],
        );

        $this->assertSame('wrong_product', $results[0]['status']);
    }

    /**
     * @return array{0: InventoryBranch, 1: InventoryProduct}
     */
    private function seedProduct(string $sku, ?InventoryBranch $branch = null): array
    {
        $branch ??= InventoryBranch::query()->create([
            'code' => 'POS-'.substr(md5($sku), 0, 6),
            'name' => 'POS branch '.$sku,
            'is_active' => true,
        ]);
        $product = InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => 'Serialized GPS device',
            'gst_percentage' => 18,
            'unit_price' => 4999,
            'is_serialized' => true,
            'is_active' => true,
        ]);

        return [$branch, $product];
    }

    private function seedSerial(
        InventoryProduct $product,
        InventoryBranch $branch,
        string $serialNumber,
    ): InventorySerial {
        return InventorySerial::query()->create([
            'product_id' => $product->id,
            'serial_number' => $serialNumber,
            'branch_id' => $branch->id,
            'status' => InventorySerialStatus::Available,
        ]);
    }
}
