<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\CommerceOrderStatus;
use App\Enums\InventoryMovementType;
use App\Enums\InventorySerialStatus;
use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Models\InventoryProduct;
use App\Models\InventorySaleSerial;
use App\Models\InventoryUserBranch;
use App\Models\ReturnExchangeCaseSerialReplacement;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\ReturnExchange\PosReturnRefundRequestService;
use App\Services\ReturnExchange\ReturnExchangeCaseGoodsReceiptService;
use App\Services\ReturnExchange\ReturnExchangeCaseIntakeService;
use App\Services\ReturnExchange\ReturnExchangeCaseSerialReplacementService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class ReturnExchangeCaseSerialReplacementTest extends ReturnExchangeCaseHttpTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceMasterDataSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_pos_equal_value_replacement_records_lineage_and_consumes_replacement_only(): void
    {
        [$case, $receiver, $returnedSerial, $replacementNo, $sale] = $this->inspectedSerializedPosCase();
        $product = $returnedSerial->product;
        $availableBefore = (int) $product->balances()->where('branch_id', $this->branchA->id)->value('available_qty');
        $saleSerialCountBefore = InventorySaleSerial::query()->where('sale_id', $sale->id)->count();

        $this->actingAs($receiver)
            ->post(route('return-cases.serial-replacement.store', $case), [
                'return_exchange_case_receipt_id' => $case->receipts()->firstOrFail()->id,
                'replacement_serial_number' => $replacementNo,
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $returnedSerial->refresh();
        $this->assertSame(InventorySerialStatus::Returned, $returnedSerial->status);
        $this->assertFalse($returnedSerial->status->isAssignable());

        $replacement = ReturnExchangeCaseSerialReplacement::query()->firstOrFail();
        $this->assertSame($returnedSerial->id, $replacement->returned_inventory_serial_id);
        $this->assertSame(InventorySerialStatus::Sold, $replacement->replacementSerial?->status);

        $this->assertSame($saleSerialCountBefore, InventorySaleSerial::query()->where('sale_id', $sale->id)->count());
        $this->assertSame($availableBefore - 1, (int) $product->fresh()->balances()->where('branch_id', $this->branchA->id)->value('available_qty'));
        $this->assertTrue(
            \App\Models\InventoryMovement::query()
                ->where('type', InventoryMovementType::ReturnCaseSerialReplace)
                ->where('serial_id', $replacement->replacement_inventory_serial_id)
                ->exists()
        );
    }

    public function test_wrong_product_replacement_is_blocked(): void
    {
        [$case, $receiver, , $replacementNo] = $this->inspectedSerializedPosCase();
        $other = InventoryProduct::query()->create([
            'sku' => 'OTHER-SKU',
            'name' => 'Other device',
            'gst_percentage' => 18,
            'unit_price' => 8000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        app(InventoryStockService::class)->stockInSerialized($other, $this->branchA, ['OTHER-SN-1'], $receiver);

        $this->actingAs($receiver)
            ->post(route('return-cases.serial-replacement.store', $case), [
                'return_exchange_case_receipt_id' => $case->receipts()->firstOrFail()->id,
                'replacement_serial_number' => 'OTHER-SN-1',
            ])
            ->assertSessionHasErrors('replacement_serial_number');

        $this->assertSame(0, ReturnExchangeCaseSerialReplacement::query()->count());
    }

    public function test_repair_hold_blocks_replacement(): void
    {
        [$case, $receiver, , $replacementNo] = $this->inspectedSerializedPosCase(repairHold: true);

        $this->actingAs($receiver)
            ->post(route('return-cases.serial-replacement.store', $case), [
                'return_exchange_case_receipt_id' => $case->receipts()->firstOrFail()->id,
                'replacement_serial_number' => $replacementNo,
            ])
            ->assertSessionHasErrors('return_exchange_case_receipt_id');
    }

    public function test_missing_tax_evidence_blocks_replacement(): void
    {
        [$case, $receiver, , $replacementNo] = $this->inspectedSerializedPosCase(withCancellation: false);

        $this->actingAs($receiver)
            ->post(route('return-cases.serial-replacement.store', $case), [
                'return_exchange_case_receipt_id' => $case->receipts()->firstOrFail()->id,
                'replacement_serial_number' => $replacementNo,
            ])
            ->assertSessionHasErrors('tax_evidence');
    }

    public function test_unavailable_replacement_serial_blocks(): void
    {
        [$case, $receiver, , $replacementNo] = $this->inspectedSerializedPosCase();
        \App\Models\InventorySerial::query()
            ->where('serial_number', $replacementNo)
            ->update(['status' => InventorySerialStatus::Sold]);

        $this->actingAs($receiver)
            ->post(route('return-cases.serial-replacement.store', $case), [
                'return_exchange_case_receipt_id' => $case->receipts()->firstOrFail()->id,
                'replacement_serial_number' => $replacementNo,
            ])
            ->assertSessionHasErrors('replacement_serial_number');
    }

    public function test_pos_refund_request_blocks_replacement(): void
    {
        [$case, $receiver, , $replacementNo] = $this->inspectedSerializedPosCase();
        app(PosReturnRefundRequestService::class)->submit($receiver, $case->fresh());

        $this->actingAs($receiver)
            ->post(route('return-cases.serial-replacement.store', $case), [
                'return_exchange_case_receipt_id' => $case->receipts()->firstOrFail()->id,
                'replacement_serial_number' => $replacementNo,
            ])
            ->assertSessionHasErrors('replacement');
    }

    public function test_duplicate_replacement_on_same_receipt_is_blocked(): void
    {
        [$case, $receiver, , $replacementNo] = $this->inspectedSerializedPosCase();
        $service = app(ReturnExchangeCaseSerialReplacementService::class);
        $receiptId = $case->receipts()->firstOrFail()->id;
        $service->perform($receiver, $case, [
            'return_exchange_case_receipt_id' => $receiptId,
            'replacement_serial_number' => $replacementNo,
        ]);

        $this->expectException(ValidationException::class);
        $service->perform($receiver, $case->fresh(), [
            'return_exchange_case_receipt_id' => $receiptId,
            'replacement_serial_number' => $replacementNo,
        ]);
    }

    public function test_idempotency_key_replays_without_duplicate_row(): void
    {
        [$case, $receiver, , $replacementNo] = $this->inspectedSerializedPosCase();
        $service = app(ReturnExchangeCaseSerialReplacementService::class);
        $payload = [
            'return_exchange_case_receipt_id' => $case->receipts()->firstOrFail()->id,
            'replacement_serial_number' => $replacementNo,
            'idempotency_key' => 'replace-idem-1',
        ];
        $first = $service->perform($receiver, $case, $payload);
        $second = $service->perform($receiver, $case->fresh(), $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, ReturnExchangeCaseSerialReplacement::query()->count());
    }

    public function test_online_fulfilment_case_is_held(): void
    {
        $central = $this->centralAdmin();
        $receiver = $this->hardwareReceiver();
        $order = $this->createOrder([
            'payment_amount' => 3500,
            'cashfree_payment_id' => 'cf_rep_hold',
        ]);
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-REP',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => (string) $order->order_id,
            'idempotency_key' => 'ec-rep',
            'payload_hash' => hash('sha256', 'ec-rep'),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);
        $fulfilment = HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => (string) $order->order_id,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => \App\Enums\HardwareFulfilmentState::Ingested,
            'support_order_id' => $order->id,
            'fulfilment_branch_id' => $this->branchA->id,
            'ingested_at' => now(),
        ]);
        $case = app(ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => ReturnExchangeCaseSourceKind::EcommerceFulfilment->value,
            'hardware_fulfilment_id' => $fulfilment->id,
            'reason' => 'Online replace hold',
            'lines' => [['description' => 'HW', 'qty' => 1]],
        ]);

        $this->expectException(ValidationException::class);
        app(ReturnExchangeCaseSerialReplacementService::class)->perform($receiver, $case, [
            'return_exchange_case_receipt_id' => 1,
            'replacement_serial_number' => 'ANY',
        ]);
    }

    /**
     * @return array{0: \App\Models\ReturnExchangeCase, 1: User, 2: \App\Models\InventorySerial, 3: string, 4: \App\Models\InventorySale}
     */
    private function inspectedSerializedPosCase(
        bool $withCancellation = true,
        bool $repairHold = false,
    ): array {
        $setup = User::factory()->create(['is_active' => true]);
        $setup->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $receiver = $this->hardwareReceiver();

        $product = InventoryProduct::query()->create([
            'sku' => 'REP-SKU-'.random_int(100, 999),
            'name' => 'Replacement test device',
            'gst_percentage' => 18,
            'unit_price' => 8000,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $returnedNo = 'RET-'.random_int(10000, 99999);
        $replacementNo = 'REP-'.random_int(10000, 99999);
        $stock = app(InventoryStockService::class);
        $stock->stockInSerialized($product, $this->branchA, [$returnedNo, $replacementNo], $setup);

        $sale = app(PosSaleService::class)->completeSale(
            branch: $this->branchA,
            customer: ['name' => 'Buyer', 'phone' => '9999900888'],
            lines: [['product_id' => $product->id, 'qty' => 1, 'serials' => [$returnedNo]]],
            paymentMethod: 'Cash',
            actor: $setup,
        );
        $invoice = $this->createPosInvoice($sale, 'INV-REP-'.random_int(100, 999));
        $sale->update(['statutory_invoice_id' => $invoice->id]);
        if ($withCancellation) {
            $this->attachCancellation($invoice, $setup);
        }

        $returnedSerial = $sale->serials()->firstOrFail()->serial;

        $case = app(ReturnExchangeCaseIntakeService::class)->create($setup, [
            'source_kind' => ReturnExchangeCaseSourceKind::PosSale->value,
            'inventory_sale_id' => $sale->id,
            'branch_id' => $this->branchA->id,
            'reason' => 'Serial replacement test',
            'lines' => [[
                'description' => $product->name,
                'qty' => 1,
                'serial_number' => $returnedNo,
                'inventory_sale_line_id' => $sale->lines()->value('id'),
            ]],
        ]);

        $line = $case->lines()->firstOrFail();
        $receipts = app(ReturnExchangeCaseGoodsReceiptService::class);
        $receipt = $receipts->receive($case, $receiver, [
            'return_exchange_case_line_id' => $line->id,
            'serial_number_scanned' => $returnedNo,
        ]);
        $receipts->inspect($case, $receipt, $receiver, [
            'product_match_passed' => true,
            'device_operates_ok' => true,
            'accessories_verified' => true,
            'repair_hold' => $repairHold,
        ]);

        return [$case->fresh(['receipts']), $receiver, $returnedSerial->fresh(), $replacementNo, $sale];
    }

    private function hardwareReceiver(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_HARDWARE_TEAM);
        InventoryUserBranch::query()->create([
            'user_id' => $user->id,
            'branch_id' => $this->branchA->id,
        ]);

        return $user;
    }

    private function createPosInvoice(\App\Models\InventorySale $sale, string $invoiceNumber): StatutoryInvoice
    {
        return StatutoryInvoice::query()->create([
            'invoice_number' => $invoiceNumber,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'inventory_sale_id' => $sale->id,
            'source_type' => StatutoryInvoiceSourceType::InventorySale,
            'source_id' => (string) $sale->id,
            'idempotency_key' => 'rep-'.$invoiceNumber,
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Seller',
            'buyer_name' => 'Buyer',
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address' => 'Addr',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => '8000.00',
            'discount' => '0.00',
            'tax_total' => '1440.00',
            'cgst' => '720.00',
            'sgst' => '720.00',
            'igst' => '0.00',
            'rounding' => '0.00',
            'invoice_value' => '9440.00',
            'issued_at' => now(),
        ]);
    }

    private function attachCancellation(StatutoryInvoice $invoice, User $actor): void
    {
        StatutoryInvoiceCancellation::query()->create([
            'statutory_invoice_id' => $invoice->id,
            'idempotency_key' => 'cancel-rep-'.$invoice->id,
            'actor_id' => $actor->id,
            'reason' => 'Test cancellation',
            'completed_at' => now(),
        ]);
    }
}
