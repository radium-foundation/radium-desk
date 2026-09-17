<?php

namespace Tests\Unit\Inventory;

use App\Models\InventoryProduct;
use App\Support\Inventory\PosSaleLineNormalizer;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PosSaleLineNormalizerTest extends TestCase
{
    use RefreshDatabase;

    public function test_one_serial_for_same_product_stays_one_line(): void
    {
        $product = $this->serializedProduct();

        $normalized = PosSaleLineNormalizer::normalize([[
            'product_id' => $product->id,
            'qty' => 1,
            'serials' => ['SER-A'],
        ]]);

        $this->assertCount(1, $normalized);
        $this->assertSame(1, $normalized[0]['qty']);
        $this->assertSame(['SER-A'], $normalized[0]['serials']);
    }

    public function test_two_different_serials_for_same_product_merge_to_one_line(): void
    {
        $product = $this->serializedProduct();

        $normalized = PosSaleLineNormalizer::normalize([
            [
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['SER-A'],
            ],
            [
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => ['SER-B'],
            ],
        ]);

        $this->assertCount(1, $normalized);
        $this->assertSame(2, $normalized[0]['qty']);
        $this->assertSame(['SER-A', 'SER-B'], $normalized[0]['serials']);
    }

    public function test_three_different_serials_for_same_product_merge_to_one_line(): void
    {
        $product = $this->serializedProduct();

        $normalized = PosSaleLineNormalizer::normalize([
            ['product_id' => $product->id, 'qty' => 1, 'serials' => ['SER-A']],
            ['product_id' => $product->id, 'qty' => 1, 'serials' => ['SER-B']],
            ['product_id' => $product->id, 'qty' => 1, 'serials' => ['SER-C']],
        ]);

        $this->assertCount(1, $normalized);
        $this->assertSame(3, $normalized[0]['qty']);
        $this->assertSame(['SER-A', 'SER-B', 'SER-C'], $normalized[0]['serials']);
    }

    public function test_duplicate_serial_for_same_product_stays_one_line_with_qty_one(): void
    {
        $product = $this->serializedProduct();

        $normalized = PosSaleLineNormalizer::normalize([
            ['product_id' => $product->id, 'qty' => 1, 'serials' => ['SER-A']],
            ['product_id' => $product->id, 'qty' => 1, 'serials' => ['SER-A']],
        ]);

        $this->assertCount(1, $normalized);
        $this->assertSame(1, $normalized[0]['qty']);
        $this->assertSame(['SER-A'], $normalized[0]['serials']);
    }

    public function test_two_different_products_remain_separate_lines(): void
    {
        $productA = $this->serializedProduct('SKU-A', 'Product A');
        $productB = $this->serializedProduct('SKU-B', 'Product B');

        $normalized = PosSaleLineNormalizer::normalize([
            ['product_id' => $productA->id, 'qty' => 1, 'serials' => ['SER-A']],
            ['product_id' => $productB->id, 'qty' => 1, 'serials' => ['SER-B']],
        ]);

        $this->assertCount(2, $normalized);
        $this->assertSame($productA->id, $normalized[0]['product_id']);
        $this->assertSame($productB->id, $normalized[1]['product_id']);
    }

    public function test_non_serialized_lines_are_not_merged(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'OTG-1',
            'name' => 'OTG Cable',
            'gst_percentage' => 18,
            'unit_price' => 50,
            'is_serialized' => false,
            'is_active' => true,
        ]);

        $normalized = PosSaleLineNormalizer::normalize([
            ['product_id' => $product->id, 'qty' => 1],
            ['product_id' => $product->id, 'qty' => 2],
        ]);

        $this->assertCount(2, $normalized);
        $this->assertSame(1, $normalized[0]['qty']);
        $this->assertSame(2, $normalized[1]['qty']);
    }

    public function test_variant_identity_is_preserved_when_merging_serials(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'CABLE-PARENT',
            'name' => 'USB Cable',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $variantA = $product->variants()->create([
            'sku' => 'CABLE-1M',
            'name' => '1 metre',
            'unit_price' => 90,
            'is_active' => true,
        ]);
        $variantB = $product->variants()->create([
            'sku' => 'CABLE-2M',
            'name' => '2 metre',
            'unit_price' => 120,
            'is_active' => true,
        ]);

        $normalized = PosSaleLineNormalizer::normalize([
            ['product_id' => $product->id, 'variant_id' => $variantA->id, 'qty' => 1, 'serials' => ['SER-A1']],
            ['product_id' => $product->id, 'variant_id' => $variantA->id, 'qty' => 1, 'serials' => ['SER-A2']],
            ['product_id' => $product->id, 'variant_id' => $variantB->id, 'qty' => 1, 'serials' => ['SER-B1']],
        ]);

        $this->assertCount(2, $normalized);
        $this->assertSame($variantA->id, $normalized[0]['variant_id']);
        $this->assertSame(2, $normalized[0]['qty']);
        $this->assertSame(['SER-A1', 'SER-A2'], $normalized[0]['serials']);
        $this->assertSame($variantB->id, $normalized[1]['variant_id']);
        $this->assertSame(1, $normalized[1]['qty']);
        $this->assertSame(['SER-B1'], $normalized[1]['serials']);
    }

    private function serializedProduct(string $sku = 'MFS110-MERGE', string $name = 'Mantra merge'): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => $name,
            'gst_percentage' => 18,
            'unit_price' => 2500,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }
}
