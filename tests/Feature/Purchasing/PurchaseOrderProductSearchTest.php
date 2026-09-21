<?php

namespace Tests\Feature\Purchasing;

use App\Models\InventoryBranch;
use App\Models\InventoryProduct;
use App\Models\PurchaseOrder;
use App\Models\User;
use App\Models\Vendor;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

class PurchaseOrderProductSearchTest extends TestCase
{
    use RefreshDatabase;

    private User $creator;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        $this->creator = User::factory()->create(['is_active' => true]);
        $this->creator->givePermissionTo([
            RolePermissionSeeder::PERMISSION_PURCHASE_VIEW,
            RolePermissionSeeder::PERMISSION_PURCHASE_CREATE,
        ]);
    }

    private function ensurePurchasingTables(): void
    {
        if (! Schema::hasTable('vendors')) {
            Schema::create('vendors', function (Blueprint $table): void {
                $table->id();
                $table->string('vendor_code')->nullable();
                $table->string('business_name');
                $table->boolean('is_active')->default(true);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchase_orders')) {
            Schema::create('purchase_orders', function (Blueprint $table): void {
                $table->id();
                $table->string('po_number')->unique();
                $table->foreignId('vendor_id');
                $table->foreignId('branch_id');
                $table->date('po_date');
                $table->date('expected_delivery_date')->nullable();
                $table->string('status');
                $table->text('notes')->nullable();
                $table->decimal('subtotal', 12, 2)->default(0);
                $table->decimal('tax_total', 12, 2)->default(0);
                $table->decimal('discount_total', 12, 2)->default(0);
                $table->decimal('grand_total', 12, 2)->default(0);
                $table->foreignId('created_by_user_id')->nullable();
                $table->foreignId('updated_by_user_id')->nullable();
                $table->timestamp('sent_at')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamp('cancelled_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchase_order_items')) {
            Schema::create('purchase_order_items', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('purchase_order_id');
                $table->foreignId('product_id');
                $table->foreignId('variant_id')->nullable();
                $table->string('sku');
                $table->unsignedInteger('quantity_ordered');
                $table->unsignedInteger('quantity_received')->default(0);
                $table->decimal('unit_cost', 12, 2);
                $table->decimal('tax_rate', 8, 2)->default(0);
                $table->decimal('discount_amount', 12, 2)->default(0);
                $table->decimal('line_total', 12, 2)->default(0);
                $table->timestamps();
            });
        }

        if (! Schema::hasTable('purchasing_audit_logs')) {
            Schema::create('purchasing_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->foreignId('user_id')->nullable();
                $table->string('event');
                $table->string('auditable_type');
                $table->unsignedBigInteger('auditable_id');
                $table->json('old_values')->nullable();
                $table->json('new_values')->nullable();
                $table->string('ip_address')->nullable();
                $table->text('user_agent')->nullable();
                $table->timestamp('created_at')->nullable();
            });
        }
    }

    /**
     * @return array{vendor: Vendor, branch: InventoryBranch}
     */
    private function createPurchasingFixtures(): array
    {
        $this->ensurePurchasingTables();

        return [
            'vendor' => Vendor::query()->create([
                'vendor_code' => 'V-PO-TEST',
                'business_name' => 'PO Test Vendor',
                'is_active' => true,
            ]),
            'branch' => InventoryBranch::query()->create([
                'code' => 'PO-TEST',
                'name' => 'PO Test Branch',
                'is_active' => true,
            ]),
        ];
    }

    public function test_create_page_shows_product_search_instead_of_preloaded_rows(): void
    {
        $this->ensurePurchasingTables();

        InventoryProduct::query()->create([
            'sku' => 'PO-HIDDEN-1',
            'name' => 'Hidden Product One',
            'gst_percentage' => 18,
            'unit_cost' => 100,
            'is_active' => true,
        ]);

        $this->actingAs($this->creator)
            ->get(route('purchasing.purchase-orders.create'))
            ->assertOk()
            ->assertSee('Search product / SKU')
            ->assertSee('No products added yet. Search above to add lines.')
            ->assertDontSee('PO-HIDDEN-1 — Hidden Product One');
    }

    public function test_create_page_exposes_keyboard_friendly_line_input_markup(): void
    {
        $this->ensurePurchasingTables();

        $response = $this->actingAs($this->creator)
            ->get(route('purchasing.purchase-orders.create'))
            ->assertOk();

        $html = $response->getContent();

        $this->assertStringContainsString('data-field="qty"', $html);
        $this->assertStringContainsString('data-field="unit_cost"', $html);
        $this->assertStringContainsString('data-field="tax_rate"', $html);
        $this->assertStringContainsString('data-field="discount"', $html);
        $this->assertStringContainsString('po-line-field', $html);
        $this->assertStringContainsString('inputmode="numeric"', $html);
        $this->assertStringContainsString('inputmode="decimal"', $html);
        $this->assertStringContainsString('id="po-create-submit"', $html);
        $this->assertStringContainsString('type="submit"', $html);
        $this->assertStringContainsString('updateLineFromInput', $html);
        $this->assertStringContainsString('focusLineField', $html);
        $this->assertStringContainsString('normalizeLineInput', $html);
        $this->assertStringContainsString("renderLines({ lineIndex: focusIndex, field: 'qty' });", $html);
        $this->assertMatchesRegularExpression(
            '/linesBody\.addEventListener\(\'input\'[\s\S]*?updateLineFromInput\(target\);[\s\S]*?\}\);/',
            $html,
        );
    }

    public function test_product_search_matches_sku(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'RBSEARCH01',
            'name' => 'Searchable Widget',
            'gst_percentage' => 18,
            'unit_cost' => 250,
            'is_active' => true,
        ]);

        $this->actingAs($this->creator)
            ->getJson(route('purchasing.products.search', ['q' => 'RBSEARCH01']))
            ->assertOk()
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.sku', 'RBSEARCH01');
    }

    public function test_product_search_matches_product_name(): void
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'RBNAME01',
            'name' => 'Acme Thermal Scanner',
            'gst_percentage' => 12,
            'unit_cost' => 999,
            'is_active' => true,
        ]);

        $this->actingAs($this->creator)
            ->getJson(route('purchasing.products.search', ['q' => 'Thermal Scanner']))
            ->assertOk()
            ->assertJsonPath('products.0.id', $product->id)
            ->assertJsonPath('products.0.name', $product->name);
    }

    public function test_product_search_returns_empty_for_no_match(): void
    {
        InventoryProduct::query()->create([
            'sku' => 'RBEXIST01',
            'name' => 'Existing Product',
            'gst_percentage' => 18,
            'unit_cost' => 100,
            'is_active' => true,
        ]);

        $this->actingAs($this->creator)
            ->getJson(route('purchasing.products.search', ['q' => 'ZZZ-NO-MATCH']))
            ->assertOk()
            ->assertJsonPath('products', []);
    }

    public function test_product_search_requires_create_permission(): void
    {
        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo(RolePermissionSeeder::PERMISSION_PURCHASE_VIEW);

        $this->actingAs($viewer)
            ->getJson(route('purchasing.products.search', ['q' => 'anything']))
            ->assertForbidden();
    }

    public function test_store_creates_purchase_order_with_multiple_search_selected_lines(): void
    {
        $fixtures = $this->createPurchasingFixtures();

        $first = InventoryProduct::query()->create([
            'sku' => 'PO-LINE-1',
            'name' => 'PO Line One',
            'gst_percentage' => 18,
            'unit_cost' => 100,
            'is_active' => true,
        ]);
        $second = InventoryProduct::query()->create([
            'sku' => 'PO-LINE-2',
            'name' => 'PO Line Two',
            'gst_percentage' => 5,
            'unit_cost' => 50,
            'is_active' => true,
        ]);

        $response = $this->actingAs($this->creator)
            ->post(route('purchasing.purchase-orders.store'), [
                'vendor_id' => $fixtures['vendor']->id,
                'branch_id' => $fixtures['branch']->id,
                'po_date' => now()->toDateString(),
                'lines' => [
                    [
                        'product_id' => $first->id,
                        'quantity' => 3,
                        'unit_cost' => 100,
                        'tax_rate' => 18,
                        'discount_amount' => 0,
                    ],
                    [
                        'product_id' => $second->id,
                        'quantity' => 2,
                        'unit_cost' => 55,
                        'tax_rate' => 5,
                        'discount_amount' => 10,
                    ],
                ],
            ]);

        $po = PurchaseOrder::query()->firstOrFail();
        $response->assertRedirect(route('purchasing.purchase-orders.show', $po));

        $this->assertDatabaseCount('purchase_orders', 1);
        $this->assertDatabaseCount('purchase_order_items', 2);

        $po->load('items.product');
        $this->assertSame('PO-LINE-1', $po->items[0]->product->sku);
        $this->assertSame(3, $po->items[0]->quantity_ordered);
        $this->assertSame('100.00', (string) $po->items[0]->unit_cost);
        $this->assertSame('18.00', (string) $po->items[0]->tax_rate);

        $this->assertSame('PO-LINE-2', $po->items[1]->product->sku);
        $this->assertSame(2, $po->items[1]->quantity_ordered);
        $this->assertSame('55.00', (string) $po->items[1]->unit_cost);
        $this->assertSame('5.00', (string) $po->items[1]->tax_rate);
        $this->assertSame('10.00', (string) $po->items[1]->discount_amount);
    }

    public function test_store_validation_requires_at_least_one_line(): void
    {
        $fixtures = $this->createPurchasingFixtures();

        $this->actingAs($this->creator)
            ->from(route('purchasing.purchase-orders.create'))
            ->post(route('purchasing.purchase-orders.store'), [
                'vendor_id' => $fixtures['vendor']->id,
                'branch_id' => $fixtures['branch']->id,
                'po_date' => now()->toDateString(),
                'lines' => [],
            ])
            ->assertSessionHasErrors('lines');

        $this->assertDatabaseCount('purchase_orders', 0);
    }
}
