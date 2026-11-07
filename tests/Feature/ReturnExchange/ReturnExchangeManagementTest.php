<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\ReturnExchangeReplacementCategory;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\InventoryProduct;
use App\Models\InventorySale;
use App\Models\InventorySaleSerial;
use App\Models\InventoryUserBranch;
use App\Models\ReturnExchangeCase;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use Database\Seeders\RolePermissionSeeder;

class ReturnExchangeManagementTest extends ReturnExchangeCaseHttpTestCase
{
    public function test_agent_cannot_access_management_index(): void
    {
        $this->actingAs($this->agentUser())
            ->get(route('return-exchange-management.index'))
            ->assertForbidden();
    }

    public function test_admin_can_view_demo_pos_6754_without_database_mutation(): void
    {
        $response = $this->actingAs($this->centralAdmin())
            ->get(route('return-exchange-management.demo.pos-6754'));

        $response->assertOk();
        $response->assertSee('LN2505154833', false);
        $response->assertSee('LN2505155964', false);
        $response->assertSee('INV-0767321', false);
    }

    public function test_hybrid_case_creation_records_category_and_blocks_duplicate_open_case(): void
    {
        [$sale, $returnSerial] = $this->fourSerialPosSale();

        $admin = $this->centralAdmin();
        InventoryUserBranch::query()->create([
            'user_id' => $admin->id,
            'branch_id' => $this->branchA->id,
        ]);

        $payload = [
            'inventory_sale_id' => $sale->id,
            'replacement_category' => ReturnExchangeReplacementCategory::CustomerRequestedExchange->value,
            'reason' => 'Customer requests USB to Type-C',
            'customer_request_summary' => 'Connector change',
            'requested_connector_change' => 'USB to Type-C',
            'lines' => [[
                'description' => 'Return one unit',
                'qty' => 1,
                'serial_number' => $returnSerial,
            ]],
        ];

        $this->actingAs($admin)
            ->post(route('return-exchange-management.store'), $payload)
            ->assertRedirect();

        $this->actingAs($admin)
            ->post(route('return-exchange-management.store'), $payload)
            ->assertSessionHasErrors();
    }

    public function test_planned_replacement_does_not_change_sale_serial_assignments(): void
    {
        [$sale, $returnSerial, $otherSerials, $replacementNo] = $this->fourSerialPosSale(withReplacementAvailable: true);

        $admin = $this->centralAdmin();
        InventoryUserBranch::query()->create([
            'user_id' => $admin->id,
            'branch_id' => $this->branchA->id,
        ]);

        $this->actingAs($admin)->post(route('return-exchange-management.store'), [
            'inventory_sale_id' => $sale->id,
            'replacement_category' => ReturnExchangeReplacementCategory::CustomerRequestedExchange->value,
            'reason' => 'Connector exchange',
            'lines' => [[
                'description' => 'Return',
                'qty' => 1,
                'serial_number' => $returnSerial,
            ]],
        ]);

        $case = ReturnExchangeCase::query()->latest('id')->firstOrFail();

        $before = InventorySaleSerial::query()->where('sale_id', $sale->id)->orderBy('serial_id')->pluck('serial_id')->all();

        $this->actingAs($admin)->post(route('return-exchange-management.planned-replacement', $case), [
            'planned_replacement_serial_number' => $replacementNo,
            'price_difference_amount' => '150.00',
        ])->assertRedirect();

        $after = InventorySaleSerial::query()->where('sale_id', $sale->id)->orderBy('serial_id')->pluck('serial_id')->all();

        $this->assertSame($before, $after);
        $this->assertCount(4, $after);
        $this->assertCount(3, $otherSerials);
    }

    public function test_completion_attempt_is_blocked_with_message(): void
    {
        [$sale, $returnSerial] = $this->fourSerialPosSale();
        $admin = $this->centralAdmin();
        InventoryUserBranch::query()->create(['user_id' => $admin->id, 'branch_id' => $this->branchA->id]);

        $this->actingAs($admin)->post(route('return-exchange-management.store'), [
            'inventory_sale_id' => $sale->id,
            'replacement_category' => ReturnExchangeReplacementCategory::Warranty->value,
            'reason' => 'Should not assume warranty',
            'lines' => [[
                'description' => 'Return',
                'qty' => 1,
                'serial_number' => $returnSerial,
            ]],
        ]);

        $case = ReturnExchangeCase::query()->latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->post(route('return-exchange-management.complete', $case))
            ->assertSessionHasErrors('completion');
    }

    public function test_statutory_readiness_blocks_without_credit_note_on_issued_invoice(): void
    {
        [$sale, $returnSerial] = $this->fourSerialPosSale(issuedInvoice: true);
        $admin = $this->centralAdmin();
        InventoryUserBranch::query()->create(['user_id' => $admin->id, 'branch_id' => $this->branchA->id]);

        $this->actingAs($admin)->post(route('return-exchange-management.store'), [
            'inventory_sale_id' => $sale->id,
            'replacement_category' => ReturnExchangeReplacementCategory::CustomerRequestedExchange->value,
            'reason' => 'Exchange',
            'lines' => [[
                'description' => 'Return',
                'qty' => 1,
                'serial_number' => $returnSerial,
            ]],
        ]);

        $case = ReturnExchangeCase::query()->latest('id')->firstOrFail();

        $this->actingAs($admin)
            ->get(route('return-exchange-management.show', $case))
            ->assertOk()
            ->assertSee('Statutory evidence', false);
    }

    /**
     * @return array{0: InventorySale, 1: string, 2?: list<int>, 3?: string}
     */
    protected function fourSerialPosSale(bool $withReplacementAvailable = false, bool $issuedInvoice = false): array
    {
        $setup = User::factory()->create(['is_active' => true]);
        $setup->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        InventoryUserBranch::query()->create([
            'user_id' => $setup->id,
            'branch_id' => $this->branchA->id,
        ]);

        $product = InventoryProduct::query()->create([
            'sku' => 'HYB-TEST-'.random_int(1000, 9999),
            'name' => 'Hybrid test product',
            'is_serialized' => true,
            'is_active' => true,
        ]);

        $serials = [];
        for ($i = 0; $i < 4; $i++) {
            $serials[] = 'HYB'.random_int(100000, 999999);
        }
        $replacementNo = 'HYB'.random_int(100000, 999999);

        $stock = app(InventoryStockService::class);
        $stock->stockInSerialized($product, $this->branchA, $serials, $setup);
        if ($withReplacementAvailable) {
            $stock->stockInSerialized($product, $this->branchA, [$replacementNo], $setup);
        }

        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branchA,
            customer: ['name' => 'Buyer', 'phone' => '9999900777'],
            lines: [['product_id' => $product->id, 'qty' => 4, 'serials' => $serials]],
            paymentMethod: 'Cash',
            actor: $setup,
        );

        if ($issuedInvoice) {
            $invoice = StatutoryInvoice::query()->create([
                'invoice_number' => 'INV-HYB-'.random_int(1000, 9999),
                'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
                'status' => StatutoryInvoiceStatus::Issued,
                'channel' => StatutoryInvoiceChannel::DeskPos,
                'inventory_sale_id' => $sale->id,
                'source_type' => StatutoryInvoiceSourceType::InventorySale,
                'source_id' => (string) $sale->id,
                'idempotency_key' => 'hyb-'.random_int(1, 99999),
                'seller_gstin' => '07AAICP1128M1Z9',
                'seller_name' => 'Seller',
                'buyer_name' => 'Buyer',
                'billing_address' => 'Addr',
                'place_of_supply_state' => 'Delhi',
                'taxable_value' => '1000.00',
                'tax_total' => '180.00',
                'invoice_value' => '1180.00',
                'issued_at' => now(),
            ]);
            $sale->update(['statutory_invoice_id' => $invoice->id]);
        }

        if ($withReplacementAvailable) {
            return [$sale, $serials[0], array_slice($sale->serials()->pluck('serial_id')->all(), 1), $replacementNo];
        }

        return [$sale, $serials[0]];
    }
}
