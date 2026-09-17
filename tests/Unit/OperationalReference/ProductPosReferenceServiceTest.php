<?php

namespace Tests\Unit\OperationalReference;

use App\Enums\InventorySaleStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventorySale;
use App\Models\ReferenceSequence;
use App\Models\User;
use App\Services\ProductPosReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ProductPosReferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private ProductPosReferenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ProductPosReferenceService::class);
    }

    public function test_first_new_product_pos_sale_is_pos_6720(): void
    {
        $this->assertSame('POS-6720', $this->service->allocate());
        $this->assertSame(6720, ReferenceSequence::query()->find(ReferenceSequence::PRODUCT_POS_OPERATIONAL)?->current_value);
    }

    public function test_next_product_pos_sale_is_pos_6721(): void
    {
        $this->service->allocate();

        $this->assertSame('POS-6721', $this->service->allocate());
    }

    public function test_existing_pos_6720_advances_to_pos_6721(): void
    {
        $this->seedSale('POS-6720');
        $this->syncSequenceTo(6720);

        $this->assertSame('POS-6721', $this->service->allocate());
    }

    public function test_existing_higher_pos_value_advances_safely(): void
    {
        $this->seedSale('POS-6730');
        $this->syncSequenceTo(6730);

        $this->assertSame('POS-6731', $this->service->allocate());
    }

    public function test_legacy_pos_000019_remains_unchanged(): void
    {
        $legacy = $this->seedSale('POS-000019');

        $this->service->allocate();

        $this->assertSame('POS-000019', $legacy->fresh()->sale_no);
    }

    public function test_allocate_produces_unique_sequential_references(): void
    {
        $references = [];

        for ($index = 0; $index < 10; $index++) {
            $references[] = $this->service->allocate();
        }

        $this->assertSame(10, count(array_unique($references)));
        $this->assertSame('POS-6729', end($references));
        $this->assertDoesNotMatchRegularExpression('/^POS-0+\d+$/', end($references));
    }

    public function test_peek_next_reports_upcoming_value_without_consuming(): void
    {
        $this->syncSequenceTo(6724);

        $this->assertSame(6725, $this->service->peekNext());
        $this->assertSame(6724, ReferenceSequence::query()->find(ReferenceSequence::PRODUCT_POS_OPERATIONAL)?->current_value);
    }

    private function seedSale(string $saleNo): InventorySale
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'POS Customer',
            'phone' => '9999911'.random_int(100, 999),
        ]);

        $branch = InventoryBranch::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'is_active' => true,
        ]);

        return InventorySale::query()->create([
            'sale_no' => $saleNo,
            'branch_id' => $branch->id,
            'customer_id' => $customer->id,
            'status' => InventorySaleStatus::Completed,
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 18,
            'total' => 118,
            'payment_method' => 'Cash',
            'created_by' => User::factory()->create()->id,
            'completed_at' => now(),
        ]);
    }

    private function syncSequenceTo(int $value): void
    {
        ReferenceSequence::query()
            ->where('name', ReferenceSequence::PRODUCT_POS_OPERATIONAL)
            ->update(['current_value' => $value]);
    }
}
