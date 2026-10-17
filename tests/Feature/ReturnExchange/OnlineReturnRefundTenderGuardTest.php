<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\ApprovedRefundMethod;
use App\Enums\CommerceOrderStatus;
use App\Enums\RefundStatus;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\RefundRequest;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\PermissionRegistrar;

class OnlineReturnRefundTenderGuardTest extends ReturnExchangeCaseHttpTestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceMasterDataSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_split_wallet_tender_blocks_before_refund_request_or_case_link(): void
    {
        [$case, $branchUser, $order] = $this->inspectedFulfilmentCaseWithTaxCancellation();
        $commerce = $case->hardwareFulfilment?->commerceOrder;
        $this->assertNotNull($commerce);
        $commerce->update(['wallet_tender_amount' => '499.00']);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-request.store', $case))
            ->assertSessionHasErrors('tender_allocation');

        $case->refresh();
        $this->assertNull($case->refund_request_id);
        $this->assertSame(0, RefundRequest::query()->count());
    }

    public function test_wallet_tender_reference_without_amount_blocks(): void
    {
        [$case, $branchUser] = $this->inspectedFulfilmentCaseWithTaxCancellation();
        $commerce = $case->hardwareFulfilment?->commerceOrder;
        $this->assertNotNull($commerce);
        $commerce->update([
            'wallet_tender_amount' => null,
            'wallet_tender_reference' => 'wallet-ref-123',
        ]);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-request.store', $case))
            ->assertSessionHasErrors('tender_allocation');

        $this->assertSame(0, RefundRequest::query()->count());
    }

    public function test_prior_wallet_refund_blocks_submission(): void
    {
        [$case, $branchUser, $order] = $this->inspectedFulfilmentCaseWithTaxCancellation();
        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-PRIOR-W',
            'amount' => '500.00',
            'refund_amount' => '500.00',
            'reason' => 'Prior wallet refund',
            'status' => RefundStatus::Completed,
            'approved_refund_method' => ApprovedRefundMethod::Wallet,
            'requested_by' => $branchUser->id,
        ]);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-request.store', $case))
            ->assertSessionHasErrors('tender_allocation');

        $case->refresh();
        $this->assertNull($case->refund_request_id);
        $this->assertSame(1, RefundRequest::query()->count());
    }

    public function test_prior_refund_with_unknown_destination_blocks_submission(): void
    {
        [$case, $branchUser, $order] = $this->inspectedFulfilmentCaseWithTaxCancellation();
        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-PRIOR-U',
            'amount' => '100.00',
            'refund_amount' => '100.00',
            'reason' => 'Pending without approved method',
            'status' => RefundStatus::Pending,
            'approved_refund_method' => null,
            'requested_by' => $branchUser->id,
        ]);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-request.store', $case))
            ->assertSessionHasErrors('tender_allocation');

        $this->assertSame(1, RefundRequest::query()->count());
    }

    public function test_missing_external_payment_amount_blocks_when_refund_is_eligible(): void
    {
        [$case, $branchUser, $order] = $this->inspectedFulfilmentCaseWithTaxCancellation();
        $order->update([
            'payment_amount' => 0,
            'cashfree_payment_id' => null,
            'gateway_payment_id' => null,
        ]);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-request.store', $case))
            ->assertSessionHasErrors('tender_allocation');

        $case->refresh();
        $this->assertNull($case->refund_request_id);
        $this->assertSame(0, RefundRequest::query()->count());
    }

    public function test_prior_other_approved_method_blocks_submission(): void
    {
        [$case, $branchUser, $order] = $this->inspectedFulfilmentCaseWithTaxCancellation();
        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-PRIOR-O',
            'amount' => '50.00',
            'refund_amount' => '50.00',
            'reason' => 'Other method refund',
            'status' => RefundStatus::Completed,
            'approved_refund_method' => ApprovedRefundMethod::Other,
            'requested_by' => $branchUser->id,
        ]);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-request.store', $case))
            ->assertSessionHasErrors('tender_allocation');
    }

    public function test_single_external_tender_with_prior_cashfree_refund_still_submits_when_within_remainder(): void
    {
        [$case, $branchUser, $order] = $this->inspectedFulfilmentCaseWithTaxCancellation();
        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-PRIOR-CF',
            'amount' => '500.00',
            'refund_amount' => '500.00',
            'reason' => 'Prior external refund',
            'status' => RefundStatus::Completed,
            'approved_refund_method' => ApprovedRefundMethod::Cashfree,
            'requested_by' => $branchUser->id,
        ]);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-request.store', $case))
            ->assertRedirect(route('return-cases.show', $case));

        $case->refresh();
        $this->assertNotNull($case->refund_request_id);
        $this->assertSame(2, RefundRequest::query()->count());
        $this->assertSame('1770.00', (string) $case->refundRequest?->refund_amount);
    }

    public function test_eligible_amount_exceeding_external_remainder_blocks(): void
    {
        [$case, $branchUser, $order] = $this->inspectedFulfilmentCaseWithTaxCancellation();
        $order->update(['payment_amount' => 1000]);
        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-PRIOR-CF2',
            'amount' => '500.00',
            'refund_amount' => '500.00',
            'reason' => 'Prior external refund',
            'status' => RefundStatus::Completed,
            'approved_refund_method' => ApprovedRefundMethod::Cashfree,
            'requested_by' => $branchUser->id,
        ]);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-request.store', $case))
            ->assertSessionHasErrors('tender_allocation');

        $case->refresh();
        $this->assertNull($case->refund_request_id);
        $this->assertSame(1, RefundRequest::query()->count());
    }

    /**
     * @return array{0: \App\Models\ReturnExchangeCase, 1: \App\Models\User, 2: \App\Models\Order}
     */
    private function inspectedFulfilmentCaseWithTaxCancellation(): array
    {
        $branchUser = $this->branchAdminFor($this->branchA->id);
        $order = $this->createOrder([
            'order_id' => 'RB-TG-'.random_int(1000, 9999),
            'payment_amount' => 3500,
            'cashfree_payment_id' => 'cf_pay_tg_'.random_int(10000, 99999),
        ]);
        $fulfilment = $this->createFulfilment($order);
        $commerce = $fulfilment->commerceOrder;
        $this->assertNotNull($commerce);
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'HW-TG',
            'requires_shipping' => true,
            'shipping_line_kind' => 'product',
            'description' => 'Hardware',
            'qty' => 1,
            'unit_price' => 1500,
            'line_total' => '1770.00',
            'taxable_value' => '1500.00',
            'tax_total' => '270.00',
        ]);
        $invoice = $this->createOrderInvoice($order, $fulfilment, 'INV-TG-'.random_int(100, 999));
        $fulfilment->update(['statutory_invoice_id' => $invoice->id]);
        $this->attachCancellation($invoice, $branchUser);

        $central = $this->centralAdmin();
        $case = app(\App\Services\ReturnExchange\ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => \App\Enums\ReturnExchangeCaseSourceKind::EcommerceFulfilment->value,
            'hardware_fulfilment_id' => $fulfilment->id,
            'reason' => 'Tender guard test',
            'lines' => [['description' => 'Hardware', 'qty' => 1]],
        ]);

        $line = $case->lines()->firstOrFail();
        $receipts = app(\App\Services\ReturnExchange\ReturnExchangeCaseGoodsReceiptService::class);
        $receipt = $receipts->receive($case, $branchUser, [
            'return_exchange_case_line_id' => $line->id,
            'qty_received' => 1,
        ]);
        $receipts->inspect($case, $receipt, $branchUser, [
            'product_match_passed' => true,
            'device_operates_ok' => true,
            'accessories_verified' => true,
        ]);

        return [$case->fresh(['hardwareFulfilment.commerceOrder']), $branchUser, $order];
    }

    private function branchAdminFor(int $branchId): \App\Models\User
    {
        $user = \App\Models\User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        \App\Models\InventoryUserBranch::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
        ]);

        return $user;
    }

    private function createFulfilment(\App\Models\Order $order): \App\Models\HardwareFulfilment
    {
        $commerce = CommerceOrder::query()->create([
            'order_no' => 'CO-'.$order->order_id,
            'channel' => \App\Enums\StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => (string) $order->order_id,
            'idempotency_key' => 'ec-tg-'.$order->id,
            'payload_hash' => hash('sha256', (string) $order->id),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);

        return \App\Models\HardwareFulfilment::query()->create([
            'commerce_order_id' => $commerce->id,
            'channel' => \App\Enums\StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => (string) $order->order_id,
            'idempotency_key' => $commerce->idempotency_key,
            'state' => \App\Enums\HardwareFulfilmentState::Ingested,
            'support_order_id' => $order->id,
            'fulfilment_branch_id' => $this->branchA->id,
            'ingested_at' => now(),
        ]);
    }

    private function createOrderInvoice(
        \App\Models\Order $order,
        \App\Models\HardwareFulfilment $fulfilment,
        string $invoiceNumber,
    ): \App\Models\StatutoryInvoice {
        return \App\Models\StatutoryInvoice::query()->create([
            'invoice_number' => $invoiceNumber,
            'document_type' => \App\Enums\StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => \App\Enums\StatutoryInvoiceStatus::Issued,
            'channel' => \App\Enums\StatutoryInvoiceChannel::RadiumBoxCom,
            'support_order_id' => $order->id,
            'source_type' => \App\Enums\StatutoryInvoiceSourceType::CommerceOrder,
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

    private function attachCancellation(\App\Models\StatutoryInvoice $invoice, \App\Models\User $actor): void
    {
        \App\Models\StatutoryInvoiceCancellation::query()->create([
            'statutory_invoice_id' => $invoice->id,
            'idempotency_key' => 'cancel-tg-'.$invoice->id,
            'actor_id' => $actor->id,
            'reason' => 'Test cancellation',
            'completed_at' => now(),
        ]);
    }
}
