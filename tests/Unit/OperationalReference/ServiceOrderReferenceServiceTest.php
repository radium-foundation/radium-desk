<?php

namespace Tests\Unit\OperationalReference;

use App\Enums\ServiceOrderPaymentStatus;
use App\Enums\ServiceOrderStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\ReferenceSequence;
use App\Models\ServiceOrder;
use App\Models\User;
use App\Services\ServiceOrderReferenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ServiceOrderReferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private ServiceOrderReferenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(ServiceOrderReferenceService::class);
    }

    public function test_first_new_service_order_is_svc_671(): void
    {
        $this->assertSame('SVC-671', $this->service->allocate());
        $this->assertSame(671, ReferenceSequence::query()->find(ReferenceSequence::SERVICE_ORDER_OPERATIONAL)?->current_value);
    }

    public function test_next_service_order_is_svc_672(): void
    {
        $this->service->allocate();

        $this->assertSame('SVC-672', $this->service->allocate());
    }

    public function test_existing_svc_671_advances_to_svc_672(): void
    {
        $this->seedServiceOrder('SVC-671');
        $this->syncSequenceTo(671);

        $this->assertSame('SVC-672', $this->service->allocate());
    }

    public function test_existing_higher_svc_value_advances_safely(): void
    {
        $this->seedServiceOrder('SVC-680');
        $this->syncSequenceTo(680);

        $this->assertSame('SVC-681', $this->service->allocate());
    }

    public function test_legacy_svc_000001_remains_unchanged(): void
    {
        $legacy = $this->seedServiceOrder('SVC-000001');

        $this->service->allocate();

        $this->assertSame('SVC-000001', $legacy->fresh()->order_number);
    }

    public function test_allocate_produces_unique_sequential_references(): void
    {
        $references = [];

        for ($index = 0; $index < 10; $index++) {
            $references[] = $this->service->allocate();
        }

        $this->assertSame(10, count(array_unique($references)));
        $this->assertSame('SVC-680', end($references));
        $this->assertDoesNotMatchRegularExpression('/^SVC-0+\d+$/', end($references));
    }

    public function test_peek_next_reports_upcoming_value_without_consuming(): void
    {
        $this->syncSequenceTo(675);

        $this->assertSame(676, $this->service->peekNext());
        $this->assertSame(675, ReferenceSequence::query()->find(ReferenceSequence::SERVICE_ORDER_OPERATIONAL)?->current_value);
    }

    private function seedServiceOrder(string $orderNumber): ServiceOrder
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'Service Customer',
            'phone' => '9999900'.random_int(100, 999),
        ]);

        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI',
            'name' => 'Delhi',
            'is_active' => true,
        ]);

        return ServiceOrder::query()->create([
            'order_number' => $orderNumber,
            'customer_id' => $customer->id,
            'branch_id' => $branch->id,
            'buyer_name' => $customer->name,
            'buyer_phone' => $customer->phone,
            'status' => ServiceOrderStatus::Open,
            'payment_status' => ServiceOrderPaymentStatus::Unpaid,
            'subtotal' => 100,
            'tax_total' => 18,
            'discount' => 0,
            'total' => 118,
            'created_by' => User::factory()->create()->id,
        ]);
    }

    private function syncSequenceTo(int $value): void
    {
        ReferenceSequence::query()
            ->where('name', ReferenceSequence::SERVICE_ORDER_OPERATIONAL)
            ->update(['current_value' => $value]);
    }
}
