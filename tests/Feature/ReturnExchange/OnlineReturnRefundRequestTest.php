<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\RefundStatutoryAdjustmentStatus;
use App\Enums\RefundStatus;
use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\ReturnExchangeCaseStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Events\Finance\RefundCompleted;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryUserBranch;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\RefundStatutoryAdjustment;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\Services\ReturnExchange\ReturnExchangeCaseGoodsReceiptService;
use App\Services\ReturnExchange\ReturnExchangeCaseIntakeService;
use App\Services\ReturnExchange\ReturnExchangeCaseOnlineRefundSubmitService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class OnlineReturnRefundRequestTest extends ReturnExchangeCaseHttpTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceMasterDataSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_inspected_fulfilment_case_creates_one_linked_refund_request(): void
    {
        [$case, $branchUser, $order] = $this->inspectedFulfilmentCaseWithTaxCancellation();

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-request.store', $case), [
                'requester_remarks' => 'Online return',
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $case->refresh();
        $this->assertNotNull($case->refund_request_id);
        $this->assertSame(1, RefundRequest::query()->count());
        $refund = $case->refundRequest;
        $this->assertSame($order->id, $refund->order_id);
        $this->assertSame(RefundStatus::Pending, $refund->status);
        $this->assertNotNull($case->recorded_tax_statutory_invoice_id);
        $this->assertSame('1770.00', (string) $refund->refund_amount);
        $this->assertNotSame((string) $order->payment_amount, (string) $refund->refund_amount);
    }

    public function test_multi_item_order_refund_capped_to_returned_fulfilment_line_only(): void
    {
        $branchUser = $this->branchAdminFor($this->branchA->id);
        $order = $this->createOrder([
            'order_id' => 'RB-MULTI-1',
            'payment_amount' => 3500,
            'cashfree_payment_id' => 'cf_pay_multi',
        ]);
        $fulfilment = $this->createFulfilment($order);
        $commerce = $fulfilment->commerceOrder;
        $this->assertNotNull($commerce);

        $returnedItem = CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'HW-RETURNED',
            'requires_shipping' => true,
            'shipping_line_kind' => 'product',
            'description' => 'Returned device',
            'qty' => 1,
            'unit_price' => 1500,
            'line_total' => '1770.00',
            'taxable_value' => '1500.00',
            'tax_total' => '270.00',
        ]);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 2,
            'sku' => 'HW-OTHER',
            'requires_shipping' => true,
            'shipping_line_kind' => 'product',
            'description' => 'Other device on same order',
            'qty' => 1,
            'unit_price' => 1000,
            'line_total' => '1180.00',
            'taxable_value' => '1000.00',
            'tax_total' => '180.00',
        ]);

        $invoice = $this->createOrderInvoice($order, $fulfilment, 'INV-MULTI-1');
        $fulfilment->update(['statutory_invoice_id' => $invoice->id]);
        $this->attachCancellation($invoice, $branchUser);

        HardwareFulfilmentSerial::query()->create([
            'hardware_fulfilment_id' => $fulfilment->id,
            'commerce_order_item_id' => $returnedItem->id,
            'line_no' => 1,
            'position' => 1,
            'serial_number' => 'SN-MULTI-1',
        ]);

        $central = $this->centralAdmin();
        $case = app(ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => ReturnExchangeCaseSourceKind::EcommerceFulfilment->value,
            'hardware_fulfilment_id' => $fulfilment->id,
            'reason' => 'Return one fulfilment from multi-item order',
            'lines' => [['description' => 'Returned device', 'qty' => 1]],
        ]);

        $line = $case->lines()->firstOrFail();
        $receipts = app(ReturnExchangeCaseGoodsReceiptService::class);
        $receipt = $receipts->receive($case, $branchUser, [
            'return_exchange_case_line_id' => $line->id,
            'qty_received' => 1,
        ]);
        $receipts->inspect($case, $receipt, $branchUser, [
            'product_match_passed' => true,
            'device_operates_ok' => true,
            'accessories_verified' => true,
        ]);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-request.store', $case), [])
            ->assertRedirect(route('return-cases.show', $case));

        $refund = $case->fresh()->refundRequest;
        $this->assertNotNull($refund);
        $this->assertSame('1770.00', (string) $refund->refund_amount);
        $this->assertLessThan((float) $order->payment_amount, (float) $refund->refund_amount);
    }

    public function test_missing_tax_evidence_blocks_submission(): void
    {
        [$case, $branchUser] = $this->inspectedFulfilmentCaseWithTaxCancellation(linkCancellation: false);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-request.store', $case))
            ->assertSessionHasErrors('tax_evidence');

        $this->assertSame(0, RefundRequest::query()->count());
    }

    public function test_mismatched_order_on_fulfilment_case_blocks_at_intake(): void
    {
        $orderA = $this->createOrder(['order_id' => 'RB-ON-1']);
        $orderB = $this->createOrder(['order_id' => 'RB-ON-2']);
        $fulfilment = $this->createFulfilment($orderA);

        $central = $this->centralAdmin();
        $this->expectException(ValidationException::class);
        app(ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => ReturnExchangeCaseSourceKind::EcommerceFulfilment->value,
            'hardware_fulfilment_id' => $fulfilment->id,
            'order_id' => $orderB->id,
            'reason' => 'Mismatch',
            'lines' => [['description' => 'Device', 'qty' => 1]],
        ]);
    }

    public function test_duplicate_submission_blocked(): void
    {
        [$case, $branchUser] = $this->inspectedFulfilmentCaseWithTaxCancellation();
        app(ReturnExchangeCaseOnlineRefundSubmitService::class)->submit(
            $branchUser,
            $case,
            [],
            request(),
        );

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-request.store', $case))
            ->assertSessionHasErrors('refund_request');
    }

    public function test_cross_branch_submission_forbidden(): void
    {
        [$case, ,] = $this->inspectedFulfilmentCaseWithTaxCancellation();
        $other = $this->branchAdminFor($this->branchB->id);

        $this->actingAs($other)
            ->post(route('return-cases.online-refund-request.store', $case))
            ->assertForbidden();
    }

    public function test_agent_cannot_submit_online_refund(): void
    {
        [$case,] = $this->inspectedFulfilmentCaseWithTaxCancellation();
        $agent = $this->agentUser();
        InventoryUserBranch::query()->create([
            'user_id' => $agent->id,
            'branch_id' => $this->branchA->id,
        ]);

        $this->actingAs($agent)
            ->post(route('return-cases.online-refund-request.store', $case))
            ->assertForbidden();
    }

    public function test_case_linked_refund_skips_post_refund_statutory_adjustment(): void
    {
        [$case, $branchUser] = $this->inspectedFulfilmentCaseWithTaxCancellation();
        $refund = app(ReturnExchangeCaseOnlineRefundSubmitService::class)->submit(
            $branchUser,
            $case,
            [],
            request(),
        );

        $refund->update(['status' => RefundStatus::Completed]);
        config(['refunds.statutory_adjustment.enabled' => true]);

        RefundCompleted::dispatch($refund->fresh(['order']), $branchUser);

        $adjustment = RefundStatutoryAdjustment::query()->where('refund_request_id', $refund->id)->first();
        $this->assertNotNull($adjustment);
        $this->assertSame(RefundStatutoryAdjustmentStatus::NotApplicable, $adjustment->status);
        $this->assertSame('return_case_tax_outcome_already_recorded', $adjustment->skip_reason);
    }

    public function test_unlinked_fulfilment_serial_receipt_does_not_require_inventory_serial_on_receipt(): void
    {
        [$case, $branchUser] = $this->fulfilmentCaseReadyForReceipt(linkSerial: false);
        $line = $case->lines()->firstOrFail();
        $receipts = app(ReturnExchangeCaseGoodsReceiptService::class);
        $receipt = $receipts->receive($case, $branchUser, [
            'return_exchange_case_line_id' => $line->id,
            'qty_received' => 1,
        ]);
        $this->assertNull($receipt->inventory_serial_id);
    }

    /**
     * @return array{0: \App\Models\ReturnExchangeCase, 1: User, 2: Order}
     */
    private function inspectedFulfilmentCaseWithTaxCancellation(bool $linkCancellation = true): array
    {
        [$case, $branchUser, $order, $invoice] = $this->fulfilmentCaseReadyForReceipt();
        if ($linkCancellation) {
            $this->attachCancellation($invoice, $branchUser);
        }
        $line = $case->lines()->firstOrFail();
        $receipts = app(ReturnExchangeCaseGoodsReceiptService::class);
        $receipt = $receipts->receive($case, $branchUser, [
            'return_exchange_case_line_id' => $line->id,
            'qty_received' => 1,
        ]);
        $receipts->inspect($case, $receipt, $branchUser, [
            'product_match_passed' => true,
            'device_operates_ok' => true,
            'accessories_verified' => true,
        ]);
        $this->assertSame(ReturnExchangeCaseStatus::Inspected, $case->fresh()->status);

        return [$case->fresh(), $branchUser, $order];
    }

    /**
     * @return array{0: \App\Models\ReturnExchangeCase, 1: User, 2: Order, 3: StatutoryInvoice}
     */
    private function fulfilmentCaseReadyForReceipt(bool $linkSerial = false): array
    {
        $branchUser = $this->branchAdminFor($this->branchA->id);
        $order = $this->createOrder([
            'order_id' => 'RB-EC-'.random_int(1000, 9999),
            'payment_amount' => 3500,
            'cashfree_payment_id' => 'cf_pay_'.random_int(10000, 99999),
        ]);
        $fulfilment = $this->createFulfilment($order);
        $commerce = $fulfilment->commerceOrder;
        if ($commerce !== null) {
            CommerceOrderItem::query()->create([
                'commerce_order_id' => $commerce->id,
                'line_no' => 1,
                'sku' => 'HW-SINGLE',
                'requires_shipping' => true,
                'shipping_line_kind' => 'product',
                'description' => 'Hardware',
                'qty' => 1,
                'unit_price' => 1500,
                'line_total' => '1770.00',
                'taxable_value' => '1500.00',
                'tax_total' => '270.00',
            ]);
        }
        $invoice = $this->createOrderInvoice($order, $fulfilment, 'INV-EC-'.random_int(100, 999));
        $fulfilment->update(['statutory_invoice_id' => $invoice->id]);

        $central = $this->centralAdmin();
        $case = app(ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => ReturnExchangeCaseSourceKind::EcommerceFulfilment->value,
            'hardware_fulfilment_id' => $fulfilment->id,
            'reason' => 'Ecommerce return',
            'lines' => [['description' => 'Hardware', 'qty' => 1]],
        ]);

        return [$case, $branchUser, $order, $invoice];
    }

    private function createFulfilment(Order $order): HardwareFulfilment
    {
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$order->order_id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => (string) $order->order_id,
            'idempotency_key' => 'ec-'.$order->id,
            'payload_hash' => hash('sha256', (string) $order->id),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        return HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => (string) $order->order_id,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => HardwareFulfilmentState::Ingested,
            'support_order_id' => $order->id,
            'fulfilment_branch_id' => $this->branchA->id,
            'ingested_at' => now(),
        ]);
    }

    private function createOrderInvoice(Order $order, HardwareFulfilment $fulfilment, string $invoiceNumber): StatutoryInvoice
    {
        return StatutoryInvoice::query()->create([
            'invoice_number' => $invoiceNumber,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'support_order_id' => $order->id,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => (string) $fulfilment->commerce_order_id,
            'idempotency_key' => 'inv-'.$invoiceNumber,
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Seller',
            'buyer_name' => 'Buyer',
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address' => 'Addr',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => '1500.00',
            'discount' => '0.00',
            'tax_total' => '270.00',
            'cgst' => '135.00',
            'sgst' => '135.00',
            'igst' => '0.00',
            'rounding' => '0.00',
            'invoice_value' => '1770.00',
            'issued_at' => now(),
        ]);
    }

    private function attachCancellation(StatutoryInvoice $invoice, User $actor): void
    {
        StatutoryInvoiceCancellation::query()->create([
            'statutory_invoice_id' => $invoice->id,
            'idempotency_key' => 'cancel-ec-'.$invoice->id,
            'actor_id' => $actor->id,
            'reason' => 'Test cancellation',
            'completed_at' => now(),
        ]);
    }

    private function branchAdminFor(int $branchId): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        InventoryUserBranch::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
        ]);

        return $user;
    }
}
