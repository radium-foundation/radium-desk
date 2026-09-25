<?php

namespace Tests\Feature\Pos;

use App\Enums\InventorySaleStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InventoryBranch;
use App\Models\InventoryCustomer;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\Pos\PosCustomerIdentityResolver;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class PosCustomerIdentityTest extends TestCase
{
    use RefreshDatabase;

    private PosSaleService $sales;

    private InventoryStockService $stock;

    private User $actor;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->sales = app(PosSaleService::class);
        $this->stock = app(InventoryStockService::class);
        $this->actor = User::factory()->create(['is_active' => true]);
        $this->branch = InventoryBranch::query()->create([
            'code' => 'HQ',
            'name' => 'Head Office',
            'is_active' => true,
        ]);
    }

    public function test_repeat_sale_with_same_legal_identity_updates_contact_without_conflict(): void
    {
        $product = $this->serializedProduct('POS-ID-A', 'Identity A');
        $this->stock->stockInSerialized($product, $this->branch, ['POS-ID-A-1'], $this->actor);

        $this->sales->completeSale(
            branch: $this->branch,
            customer: [
                'name' => 'I K Enterprises',
                'phone' => '8847638343',
                'email' => 'first@example.com',
                'gstin' => '03BPDPK2984N1ZK',
            ],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['POS-ID-A-1']]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: $this->b2bStatutory('03BPDPK2984N1ZK'),
        );

        $this->stock->stockInSerialized($product, $this->branch, ['POS-ID-A-2'], $this->actor);

        $second = $this->sales->completeSale(
            branch: $this->branch,
            customer: [
                'name' => 'I K Enterprises',
                'phone' => '8847638343',
                'email' => 'repeat@example.com',
                'gstin' => '03BPDPK2984N1ZK',
            ],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['POS-ID-A-2']]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: $this->b2bStatutory('03BPDPK2984N1ZK'),
        );

        $customer = InventoryCustomer::query()->where('phone', '8847638343')->firstOrFail();
        $this->assertSame('I K Enterprises', $customer->name);
        $this->assertSame('03BPDPK2984N1ZK', $customer->gstin);
        $this->assertSame('repeat@example.com', $customer->email);
        $this->assertSame('I K Enterprises', $second->buyer_name);
    }

    public function test_different_company_name_on_existing_phone_requires_explicit_resolution(): void
    {
        InventoryCustomer::query()->create([
            'name' => 'I K Enterprises',
            'phone' => '8847638343',
            'gstin' => '03BPDPK2984N1ZK',
        ]);

        $product = $this->serializedProduct('POS-ID-B', 'Identity B');
        $this->stock->stockInSerialized($product, $this->branch, ['POS-ID-B-1'], $this->actor);

        try {
            $this->sales->completeSale(
                branch: $this->branch,
                customer: [
                    'name' => 'INAAYAT COMMUNICATIONS',
                    'phone' => '8847638343',
                    'gstin' => '03BPDPK2984N1ZK',
                ],
                lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['POS-ID-B-1']]],
                paymentMethod: 'Cash',
                actor: $this->actor,
                statutory: $this->b2bStatutory('03BPDPK2984N1ZK'),
            );
            $this->fail('Expected customer identity conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_identity_conflict', $exception->errors());
        }

        $this->assertSame('I K Enterprises', InventoryCustomer::query()->where('phone', '8847638343')->value('name'));
    }

    public function test_different_gstin_on_existing_phone_requires_explicit_resolution(): void
    {
        InventoryCustomer::query()->create([
            'name' => 'I K Enterprises',
            'phone' => '8847638343',
            'gstin' => '03BPDPK2984N1ZK',
        ]);

        $product = $this->serializedProduct('POS-ID-C', 'Identity C');
        $this->stock->stockInSerialized($product, $this->branch, ['POS-ID-C-1'], $this->actor);

        try {
            $this->sales->completeSale(
                branch: $this->branch,
                customer: [
                    'name' => 'I K Enterprises',
                    'phone' => '8847638343',
                    'gstin' => '03EDPPS6488J1ZN',
                ],
                lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['POS-ID-C-1']]],
                paymentMethod: 'Cash',
                actor: $this->actor,
                statutory: $this->b2bStatutory('03EDPPS6488J1ZN'),
            );
            $this->fail('Expected customer identity conflict.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('customer_identity_conflict', $exception->errors());
        }

        $this->assertSame('03BPDPK2984N1ZK', InventoryCustomer::query()->where('phone', '8847638343')->value('gstin'));
    }

    public function test_sale_only_resolution_preserves_master_and_snapshots_sale_identity(): void
    {
        InventoryCustomer::query()->create([
            'name' => 'I K Enterprises',
            'phone' => '8847638343',
            'gstin' => '03BPDPK2984N1ZK',
        ]);

        $product = $this->serializedProduct('POS-ID-D', 'Identity D');
        $this->stock->stockInSerialized($product, $this->branch, ['POS-ID-D-1'], $this->actor);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: [
                'name' => 'INAAYAT COMMUNICATIONS',
                'phone' => '8847638343',
                'gstin' => '03EDPPS6488J1ZN',
            ],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['POS-ID-D-1']]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: $this->b2bStatutory('03EDPPS6488J1ZN'),
            customerIdentityResolution: PosCustomerIdentityResolver::RESOLUTION_SALE_ONLY,
        );

        $customer = InventoryCustomer::query()->where('phone', '8847638343')->firstOrFail();
        $this->assertSame('I K Enterprises', $customer->name);
        $this->assertSame('03BPDPK2984N1ZK', $customer->gstin);
        $this->assertSame('INAAYAT COMMUNICATIONS', $sale->buyer_name);
        $this->assertSame('03EDPPS6488J1ZN', $sale->buyer_gstin);
    }

    public function test_update_master_resolution_replaces_customer_master_identity(): void
    {
        InventoryCustomer::query()->create([
            'name' => 'I K Enterprises',
            'phone' => '8847638343',
            'gstin' => '03BPDPK2984N1ZK',
        ]);

        $product = $this->serializedProduct('POS-ID-E', 'Identity E');
        $this->stock->stockInSerialized($product, $this->branch, ['POS-ID-E-1'], $this->actor);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: [
                'name' => 'INAAYAT COMMUNICATIONS',
                'phone' => '8847638343',
                'gstin' => '03EDPPS6488J1ZN',
            ],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['POS-ID-E-1']]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: $this->b2bStatutory('03EDPPS6488J1ZN'),
            customerIdentityResolution: PosCustomerIdentityResolver::RESOLUTION_UPDATE_MASTER,
        );

        $customer = InventoryCustomer::query()->where('phone', '8847638343')->firstOrFail();
        $this->assertSame('INAAYAT COMMUNICATIONS', $customer->name);
        $this->assertSame('03EDPPS6488J1ZN', $customer->gstin);
        $this->assertSame('INAAYAT COMMUNICATIONS', $sale->buyer_name);
    }

    public function test_historical_sale_displays_statutory_buyer_after_master_identity_changes(): void
    {
        $customer = InventoryCustomer::query()->create([
            'name' => 'INAAYAT COMMUNICATIONS',
            'phone' => '8847638343',
            'email' => 'harmanjot21@gmail.com',
            'gstin' => '03EDPPS6488J1ZN',
        ]);

        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-076769',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'channel' => StatutoryInvoiceChannel::DeskPos->value,
            'source_type' => StatutoryInvoiceSourceType::InventorySale->value,
            'source_id' => '11',
            'idempotency_key' => 'inv-076769-regression',
            'buyer_name' => 'I K Enterprises',
            'buyer_phone' => '8847638343',
            'buyer_gstin' => '03BPDPK2984N1ZK',
            'billing_address' => '3rd Floor, Office no 351, Medallion 68, Sec 68, Mohali,',
            'status' => StatutoryInvoiceStatus::Issued->value,
            'issued_at' => now(),
            'invoice_value' => 2950,
            'taxable_value' => 2500,
            'tax_total' => 450,
        ]);

        $sale = InventorySale::query()->create([
            'sale_no' => 'POS-000011',
            'invoice_number' => 'INV-HQ-2026-00010',
            'statutory_invoice_id' => $invoice->id,
            'branch_id' => $this->branch->id,
            'customer_id' => $customer->id,
            'buyer_gstin' => '03BPDPK2984N1ZK',
            'billing_address' => '3rd Floor, Office no 351, Medallion 68, Sec 68, Mohali,',
            'place_of_supply_state' => 'Punjab',
            'status' => InventorySaleStatus::Completed,
            'subtotal' => 2500,
            'discount' => 0,
            'tax' => 450,
            'total' => 2950,
            'payment_method' => 'Bank Transfer',
            'finance_handoff_status' => 'posted',
            'completed_at' => now()->subDays(14),
        ]);

        $this->assertSame('I K Enterprises', $sale->displayBuyerName());
        $this->assertSame('I K Enterprises', $invoice->buyer_name);
        $this->assertSame('INAAYAT COMMUNICATIONS', $customer->name);
    }

    public function test_statutory_mint_uses_sale_buyer_name_not_live_customer_master(): void
    {
        $this->branch->update(['code' => 'DELHI-RETAIL']);
        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
        ]);

        InventoryCustomer::query()->create([
            'name' => 'I K Enterprises',
            'phone' => '8847638343',
            'gstin' => '03BPDPK2984N1ZK',
        ]);

        $product = $this->serializedProduct('POS-ID-F', 'Identity F', '84716050');
        $this->stock->stockInSerialized($product, $this->branch, ['POS-ID-F-1'], $this->actor);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: [
                'name' => 'INAAYAT COMMUNICATIONS',
                'phone' => '8847638343',
                'gstin' => '03EDPPS6488J1ZN',
            ],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['POS-ID-F-1']]],
            paymentMethod: 'Cash',
            actor: $this->actor,
            statutory: $this->b2bStatutory('03EDPPS6488J1ZN'),
            customerIdentityResolution: PosCustomerIdentityResolver::RESOLUTION_SALE_ONLY,
        );

        $invoice = app(StatutoryInvoiceService::class)->issueFromPosSale($sale->fresh(['customer']), $this->actor);

        $this->assertSame('INAAYAT COMMUNICATIONS', $invoice->buyer_name);
        $this->assertSame('03EDPPS6488J1ZN', $invoice->buyer_gstin);
        $this->assertSame('I K Enterprises', $sale->customer?->name);
    }

    public function test_new_customer_creation_still_works(): void
    {
        $product = $this->serializedProduct('POS-ID-G', 'Identity G');
        $this->stock->stockInSerialized($product, $this->branch, ['POS-ID-G-1'], $this->actor);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Fresh Buyer', 'phone' => '9000000001'],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => ['POS-ID-G-1']]],
            paymentMethod: 'Cash',
            actor: $this->actor,
        );

        $this->assertDatabaseHas('inventory_customers', [
            'name' => 'Fresh Buyer',
            'phone' => '9000000001',
        ]);
        $this->assertSame('Fresh Buyer', $sale->buyer_name);
    }

    /**
     * @return array<string, string>
     */
    private function b2bStatutory(string $gstin): array
    {
        return [
            'buyer_gstin' => $gstin,
            'billing_address' => '3rd Floor, Office no 351, Medallion 68, Sec 68, Mohali',
            'billing_city' => 'Mohali',
            'billing_state' => 'Punjab',
            'billing_pincode' => '160062',
            'place_of_supply_state' => 'Punjab',
        ];
    }

    private function serializedProduct(string $sku, string $name, ?string $hsnCode = null): InventoryProduct
    {
        return InventoryProduct::query()->create([
            'sku' => $sku,
            'name' => $name,
            'hsn_code' => $hsnCode,
            'gst_percentage' => 18,
            'unit_price' => 2500,
            'is_serialized' => true,
            'is_active' => true,
        ]);
    }
}
