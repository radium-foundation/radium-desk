<?php

namespace Tests\Unit\Purchasing;

use App\Enums\PurchaseOrderStatus;
use App\Models\InventoryBranch;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use App\Services\Purchasing\PurchasingNumberService;
use App\Services\Purchasing\VendorService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class PurchasingNumberServiceTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    private Vendor $vendor;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(RolePermissionSeeder::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'is_active' => true,
        ]);

        $this->vendor = app(VendorService::class)->create([
            'business_name' => 'Number Vendor',
            'is_active' => true,
        ], $this->admin);
    }

    public function test_first_po_in_fy_2026_27_is_po_07_001(): void
    {
        $number = app(PurchasingNumberService::class)->allocatePurchaseOrderNumber(
            Carbon::parse('2026-09-12'),
        );

        $this->assertSame('PO-07-001', $number);
    }

    public function test_sequence_increments_within_financial_year(): void
    {
        $this->createPurchaseOrder('PO-07-001');

        $number = app(PurchasingNumberService::class)->allocatePurchaseOrderNumber(
            Carbon::parse('2026-09-12'),
        );

        $this->assertSame('PO-07-002', $number);
    }

    public function test_fy_2027_28_resets_sequence_to_po_08_001(): void
    {
        $this->createPurchaseOrder('PO-07-671', '2027-03-31');

        $number = app(PurchasingNumberService::class)->allocatePurchaseOrderNumber(
            Carbon::parse('2027-04-01'),
        );

        $this->assertSame('PO-08-001', $number);
    }

    public function test_cancelled_numbers_are_not_reused(): void
    {
        $this->createPurchaseOrder('PO-07-001', '2026-09-12', PurchaseOrderStatus::Cancelled);

        $number = app(PurchasingNumberService::class)->allocatePurchaseOrderNumber(
            Carbon::parse('2026-09-12'),
        );

        $this->assertSame('PO-07-002', $number);
    }

    private function createPurchaseOrder(
        string $poNumber,
        string $poDate = '2026-09-12',
        PurchaseOrderStatus $status = PurchaseOrderStatus::Draft,
    ): PurchaseOrder {
        return PurchaseOrder::query()->create([
            'po_number' => $poNumber,
            'vendor_id' => $this->vendor->id,
            'branch_id' => $this->branch->id,
            'po_date' => $poDate,
            'status' => $status,
            'created_by_user_id' => $this->admin->id,
            'updated_by_user_id' => $this->admin->id,
        ]);
    }
}
