<?php

namespace Tests\Feature\Pos;

use App\Http\Controllers\Pos\CounterController;
use App\Models\CustomerPayment;
use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventoryUserBranch;
use App\Models\PaymentAllocation;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Support\Inventory\PosSalePaymentState;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Concerns\DisablesRequestForgeryProtection;
use Tests\TestCase;

class PosCounterPaymentStatusUiTest extends TestCase
{
    use DisablesRequestForgeryProtection;
    use RefreshDatabase;

    private User $seller;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();
        $this->disableRequestForgeryProtection();

        $this->seller = User::factory()->create(['is_active' => true]);
        $this->seller->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);
        InventoryUserBranch::query()->create([
            'user_id' => $this->seller->id,
            'branch_id' => $this->branch->id,
        ]);
    }

    public function test_counter_uses_single_payment_method_dropdown_with_exact_options_and_unpaid_default(): void
    {
        $html = $this->counterHtml();

        $this->assertStringContainsString('for="payment_method">Payment method</', $html);
        $this->assertStringNotContainsString('name="payment_status"', $html);
        $this->assertStringNotContainsString('type="radio" name="payment_status"', $html);
        $this->assertStringNotContainsString('Expected payment method', $html);
        $this->assertStringNotContainsString('Bank Transfer', $html);
        $this->assertStringNotContainsString('Cashfree', $html);
        $this->assertStringNotContainsString('>UPI<', $html);

        foreach (CounterController::POS_COUNTER_PAYMENT_METHODS as $method) {
            $this->assertStringContainsString('value="'.$method.'"', $html);
            $this->assertStringContainsString('>'.$method.'<', $html);
        }

        $this->assertMatchesRegularExpression(
            '/<option value="Unpaid"[^>]*selected/s',
            $html,
        );
    }

    public function test_counter_includes_unpaid_confirmation_modal_markup(): void
    {
        $html = $this->counterHtml();

        $this->assertStringContainsString('id="pos-unpaid-confirm-modal"', $html);
        $this->assertStringContainsString('Payment not received', $html);
        $this->assertStringContainsString('This sale will be completed as Unpaid. No payment will be recorded.', $html);
        $this->assertStringContainsString('id="pos-unpaid-confirm-submit"', $html);
        $this->assertStringContainsString('Complete as Unpaid', $html);
    }

    public function test_place_of_supply_and_billing_address_default_from_branch_location_series(): void
    {
        $html = $this->counterHtml();

        $this->assertMatchesRegularExpression(
            '/<option value="Delhi"[^>]*selected/s',
            $html,
        );
        $this->assertStringContainsString(
            'Test-only Delhi registered address',
            $html,
        );
    }

    public function test_unpaid_counter_submission_uses_pending_semantics_without_canonical_payment(): void
    {
        $product = $this->stockProduct('UI-UNPAID-1');

        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), [
                'branch_id' => $this->branch->id,
                'customer_name' => 'Pending Buyer',
                'customer_phone' => '9111222333',
                'payment_method' => CounterController::POS_PAYMENT_METHOD_UNPAID,
                'place_of_supply_state' => 'Delhi',
                'lines' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'serials' => 'SN-UI-UNPAID-1',
                ]],
            ])
            ->assertRedirect();

        $sale = InventorySale::query()->latest('id')->first();
        $this->assertNotNull($sale);
        $this->assertTrue(PosSalePaymentState::isPaymentPending($sale));
        $this->assertNull($sale->payment_method);
        $this->assertSame(0, CustomerPayment::query()->count());
        $this->assertSame(0, PaymentAllocation::query()->count());
    }

    public function test_paid_counter_submission_accepts_restricted_pos_methods(): void
    {
        $product = $this->stockProduct('UI-PAID-1');

        $this->actingAs($this->seller)
            ->post(route('pos.counter.store'), [
                'branch_id' => $this->branch->id,
                'customer_name' => 'Paid Buyer',
                'customer_phone' => '9222333444',
                'payment_method' => 'CASH',
                'place_of_supply_state' => 'Delhi',
                'lines' => [[
                    'product_id' => $product->id,
                    'qty' => 1,
                    'serials' => 'SN-UI-PAID-1',
                ]],
            ])
            ->assertRedirect();

        $sale = InventorySale::query()->latest('id')->first();
        $this->assertNotNull($sale);
        $this->assertFalse(PosSalePaymentState::isPaymentPending($sale));
        $this->assertSame('CASH', $sale->payment_method);
    }

    private function counterHtml(): string
    {
        return $this->actingAs($this->seller)
            ->get(route('pos.counter.create', ['branch_id' => $this->branch->id]))
            ->assertOk()
            ->getContent();
    }

    private function stockProduct(string $marker): InventoryProduct
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS110-'.$marker,
            'name' => 'Mantra MFS110 '.$marker,
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($product, $this->branch, ['SN-'.$marker], $this->seller);

        return $product;
    }
}
