<?php

namespace Tests\Unit\OperationalReference;

use App\Models\PurchaseOrder;
use App\Models\ReferenceSequence;
use App\Models\Vendor;
use App\Services\Purchasing\PurchaseOrderReferenceService;
use App\Services\StatutoryInvoice\StatutoryFinancialYear;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseOrderReferenceServiceTest extends TestCase
{
    use RefreshDatabase;

    private PurchaseOrderReferenceService $service;

    protected function setUp(): void
    {
        parent::setUp();

        $this->service = app(PurchaseOrderReferenceService::class);
    }

    public function test_first_new_purchase_order_for_fy_2026_27_is_po_671(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21'));

        $this->assertSame('PO-671', $this->service->allocate());
    }

    public function test_next_purchase_order_is_po_672(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21'));

        $this->service->allocate();

        $this->assertSame('PO-672', $this->service->allocate());
    }

    public function test_legacy_po_2026_format_is_not_renumbered_and_sequence_advances(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21'));
        $this->seedPurchaseOrder('PO-2026-00001');
        $this->syncSequenceTo(670);

        $this->assertSame('PO-671', $this->service->allocate());
    }

    public function test_existing_po_671_advances_to_po_672(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21'));
        $this->seedPurchaseOrder('PO-671');
        $this->syncSequenceTo(671);

        $this->assertSame('PO-672', $this->service->allocate());
    }

    public function test_allocate_produces_unique_sequential_references(): void
    {
        $this->travelTo(Carbon::parse('2026-09-21'));

        $references = [];
        for ($index = 0; $index < 5; $index++) {
            $references[] = $this->service->allocate();
        }

        $this->assertSame(5, count(array_unique($references)));
        $this->assertSame('PO-675', end($references));
    }

    public function test_fy_2027_28_starts_at_po_781(): void
    {
        $this->travelTo(Carbon::parse('2027-04-01'));

        $this->assertSame('PO-781', $this->service->allocate());
    }

    private function seedPurchaseOrder(string $poNumber): PurchaseOrder
    {
        if (! Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function ($table): void {
                $table->id();
                $table->string('po_number')->unique();
                $table->foreignId('vendor_id');
                $table->foreignId('branch_id');
                $table->date('po_date');
                $table->string('status');
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('vendors')) {
            Schema::create('vendors', function ($table): void {
                $table->id();
                $table->string('business_name');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        $vendor = Vendor::query()->create([
            'business_name' => 'Legacy Vendor',
            'is_active' => true,
        ]);

        return PurchaseOrder::query()->create([
            'po_number' => $poNumber,
            'vendor_id' => $vendor->id,
            'branch_id' => 1,
            'po_date' => now()->toDateString(),
            'status' => 'draft',
        ]);
    }

    private function syncSequenceTo(int $value): void
    {
        $financialYear = StatutoryFinancialYear::containing(now());

        ReferenceSequence::query()
            ->where('name', ReferenceSequence::purchaseOrderSequenceName($financialYear))
            ->update(['current_value' => $value]);
    }
}
