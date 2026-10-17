<?php

namespace Tests\Feature\ReturnExchange;

use App\Enums\ApprovedRefundMethod;
use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\OnlineReturnRefundAllocationDestination;
use App\Enums\OnlineReturnRefundAllocationStatus;
use App\Enums\RefundStatus;
use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\HardwareFulfilmentSerial;
use App\Models\InventoryMovement;
use App\Models\InventoryUserBranch;
use App\Models\OnlineReturnRefundAllocation;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\Services\ReturnExchange\OnlineReturnRefundAllocationService;
use App\Services\ReturnExchange\ReturnExchangeCaseGoodsReceiptService;
use App\Services\ReturnExchange\ReturnExchangeCaseIntakeService;
use App\Services\ReturnExchange\ReturnExchangeCaseOnlineRefundSubmitService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use Spatie\Permission\PermissionRegistrar;

class OnlineReturnRefundAllocationTest extends ReturnExchangeCaseHttpTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->seed(FinanceMasterDataSeeder::class);
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    public function test_split_tender_allocation_submit_and_finance_approve_without_refund_request(): void
    {
        [$case, $branchUser, $order] = $this->inspectedSplitTenderCase();
        $movementsBefore = InventoryMovement::query()->count();

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-allocation.store', $case), [
                'lines' => [
                    ['destination' => OnlineReturnRefundAllocationDestination::Wallet->value, 'amount' => '499.00'],
                    ['destination' => OnlineReturnRefundAllocationDestination::Cashfree->value, 'amount' => '1271.00'],
                ],
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $allocation = $case->fresh()->onlineRefundAllocation;
        $this->assertNotNull($allocation);
        $this->assertSame(OnlineReturnRefundAllocationStatus::PendingFinanceReview, $allocation->status);
        $this->assertSame('1770.00', number_format((float) $allocation->requested_total, 2, '.', ''));
        $this->assertSame('1770.00', number_format((float) $allocation->returned_line_cap, 2, '.', ''));
        $this->assertSame('2499.00', number_format((float) $allocation->order_paid_total, 2, '.', ''));
        $this->assertSame(0, RefundRequest::query()->count());

        $central = $this->centralAdmin();
        $this->actingAs($central)
            ->post(route('online-return-refund-allocations.review', $allocation), [
                'decision' => 'approve',
                'review_reason' => 'Split tender OK',
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $this->assertSame(OnlineReturnRefundAllocationStatus::Approved, $allocation->fresh()->status);
        $this->assertSame($movementsBefore, InventoryMovement::query()->count());
        $this->assertNull($case->fresh()->refund_request_id);
    }

    public function test_direct_refund_request_still_blocked_by_tender_guard_on_split_tender(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-request.store', $case))
            ->assertSessionHasErrors('tender_allocation');

        $this->assertSame(0, RefundRequest::query()->count());
    }

    public function test_allocation_total_above_returned_line_cap_is_rejected(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-allocation.store', $case), [
                'lines' => [
                    ['destination' => OnlineReturnRefundAllocationDestination::Wallet->value, 'amount' => '1771.00'],
                ],
            ])
            ->assertSessionHasErrors('lines');

        $this->assertNull($case->fresh()->onlineRefundAllocation);
    }

    public function test_prior_refunds_reduce_order_remainder_cap(): void
    {
        [$case, $branchUser, $order] = $this->inspectedSplitTenderCase();
        RefundRequest::query()->create([
            'order_id' => $order->id,
            'reference_no' => 'REF-PRIOR-SPLIT',
            'amount' => '2000.00',
            'refund_amount' => '2000.00',
            'reason' => 'Prior refund',
            'status' => RefundStatus::Completed,
            'approved_refund_method' => ApprovedRefundMethod::Cashfree,
            'requested_by' => $branchUser->id,
        ]);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-allocation.store', $case), [
                'lines' => [
                    ['destination' => OnlineReturnRefundAllocationDestination::Wallet->value, 'amount' => '1770.00'],
                ],
            ])
            ->assertSessionHasErrors('lines');
    }

    public function test_multi_item_order_caps_allocation_to_returned_line_only(): void
    {
        $branchUser = $this->branchAdminFor($this->branchA->id);
        $order = $this->createOrder([
            'order_id' => 'RB-SPLIT-MULTI',
            'payment_amount' => 4000,
            'cashfree_payment_id' => 'cf_split_multi',
        ]);
        $fulfilment = $this->createFulfilmentWithSplitTender($order, walletAmount: '499.00', orderValue: '4499.00');
        $commerce = $fulfilment->commerceOrder;
        $this->assertNotNull($commerce);
        $returnedItem = CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'HW-RET-M',
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
            'sku' => 'HW-OTHER-M',
            'requires_shipping' => true,
            'shipping_line_kind' => 'product',
            'description' => 'Other device',
            'qty' => 1,
            'unit_price' => 2000,
            'line_total' => '2360.00',
            'taxable_value' => '2000.00',
            'tax_total' => '360.00',
        ]);
        HardwareFulfilmentSerial::query()->create([
            'hardware_fulfilment_id' => $fulfilment->id,
            'commerce_order_item_id' => $returnedItem->id,
            'line_no' => 1,
            'position' => 1,
            'serial_number' => 'SN-SPLIT-MULTI',
        ]);
        $invoice = $this->createOrderInvoice($order, $fulfilment, 'INV-SPLIT-MULTI');
        $fulfilment->update(['statutory_invoice_id' => $invoice->id]);
        $this->attachCancellation($invoice, $branchUser);
        $central = $this->centralAdmin();
        $case = app(ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => ReturnExchangeCaseSourceKind::EcommerceFulfilment->value,
            'hardware_fulfilment_id' => $fulfilment->id,
            'reason' => 'Split tender multi-item return',
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
            ->post(route('return-cases.online-refund-allocation.store', $case), [
                'lines' => [
                    ['destination' => OnlineReturnRefundAllocationDestination::Opm->value, 'amount' => '2360.00'],
                ],
            ])
            ->assertSessionHasErrors('lines');

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-allocation.store', $case), [
                'lines' => [
                    ['destination' => OnlineReturnRefundAllocationDestination::Opm->value, 'amount' => '1770.00'],
                ],
            ])
            ->assertRedirect(route('return-cases.show', $case));

        $cap = $case->fresh()->onlineRefundAllocation?->returned_line_cap;
        $this->assertSame('1770.00', number_format((float) $cap, 2, '.', ''));
    }

    public function test_non_split_tender_order_cannot_submit_allocation(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase(walletAmount: null);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-allocation.store', $case), [
                'lines' => [
                    ['destination' => OnlineReturnRefundAllocationDestination::Cashfree->value, 'amount' => '1770.00'],
                ],
            ])
            ->assertSessionHasErrors('allocation');
    }

    public function test_unreconciled_order_value_fails_closed(): void
    {
        [$case, $branchUser, , $commerce] = $this->inspectedSplitTenderCase(returnCase: false);
        $commerce->update(['order_value' => '9999.00']);
        $case = $this->intakeAndInspectSplitCase($commerce);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-allocation.store', $case), [
                'lines' => [
                    ['destination' => OnlineReturnRefundAllocationDestination::Wallet->value, 'amount' => '499.00'],
                ],
            ])
            ->assertSessionHasErrors('allocation');
    }

    public function test_agent_cannot_review_allocation(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        app(OnlineReturnRefundAllocationService::class)->submit($branchUser, $case, [
            'lines' => [
                ['destination' => OnlineReturnRefundAllocationDestination::Wallet->value, 'amount' => '499.00'],
                ['destination' => OnlineReturnRefundAllocationDestination::Cashfree->value, 'amount' => '1271.00'],
            ],
        ]);
        $allocation = $case->fresh()->onlineRefundAllocation;
        $agent = $this->agentUser();
        InventoryUserBranch::query()->create([
            'user_id' => $agent->id,
            'branch_id' => $this->branchA->id,
        ]);

        $this->actingAs($agent)
            ->post(route('online-return-refund-allocations.review', $allocation), [
                'decision' => 'approve',
            ])
            ->assertForbidden();
    }

    public function test_duplicate_allocation_submission_is_blocked(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $service = app(OnlineReturnRefundAllocationService::class);
        $service->submit($branchUser, $case, [
            'lines' => [
                ['destination' => OnlineReturnRefundAllocationDestination::Wallet->value, 'amount' => '499.00'],
                ['destination' => OnlineReturnRefundAllocationDestination::Cashfree->value, 'amount' => '1271.00'],
            ],
        ]);

        $this->expectException(ValidationException::class);
        $service->submit($branchUser, $case->fresh(), [
            'lines' => [
                ['destination' => OnlineReturnRefundAllocationDestination::Wallet->value, 'amount' => '100.00'],
            ],
        ]);
    }

    public function test_idempotency_key_replays_same_allocation(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $payload = [
            'lines' => [
                ['destination' => OnlineReturnRefundAllocationDestination::Wallet->value, 'amount' => '499.00'],
                ['destination' => OnlineReturnRefundAllocationDestination::Cashfree->value, 'amount' => '1271.00'],
            ],
            'idempotency_key' => 'ora-idem-1',
        ];
        $service = app(OnlineReturnRefundAllocationService::class);
        $first = $service->submit($branchUser, $case, $payload);
        $second = $service->submit($branchUser, $case->fresh(), $payload);

        $this->assertSame($first->id, $second->id);
        $this->assertSame(1, OnlineReturnRefundAllocation::query()->count());
    }

    public function test_missing_tax_evidence_blocks_allocation(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase(withCancellation: false);

        $this->actingAs($branchUser)
            ->post(route('return-cases.online-refund-allocation.store', $case), [
                'lines' => [
                    ['destination' => OnlineReturnRefundAllocationDestination::Wallet->value, 'amount' => '499.00'],
                ],
            ])
            ->assertSessionHasErrors('tax_evidence');
    }

    public function test_online_refund_submit_service_still_does_not_run_for_split_tender(): void
    {
        [$case, $branchUser] = $this->inspectedSplitTenderCase();
        $this->expectException(ValidationException::class);
        app(ReturnExchangeCaseOnlineRefundSubmitService::class)->submit(
            $branchUser,
            $case,
            [],
            Request::create('/'),
        );
    }

    /**
     * @return array{0: \App\Models\ReturnExchangeCase, 1: User, 2: Order, 3: CommerceOrder}
     */
    protected function inspectedSplitTenderCase(
        bool $withCancellation = true,
        bool $multiItem = false,
        ?string $walletAmount = '499.00',
        bool $returnCase = true,
    ): array {
        [$case, $branchUser, $order, $commerce, $invoice] = $this->splitTenderFulfilmentReady(
            multiItem: $multiItem,
            walletAmount: $walletAmount,
        );
        if ($withCancellation) {
            $this->attachCancellation($invoice, $branchUser);
        }
        if (! $returnCase) {
            return [$case, $branchUser, $order, $commerce];
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

        return [$case->fresh(), $branchUser, $order];
    }

    private function intakeAndInspectSplitCase(CommerceOrder $commerce): \App\Models\ReturnExchangeCase
    {
        $fulfilment = $commerce->hardwareFulfilment;
        $branchUser = $this->branchAdminFor($this->branchA->id);
        $central = $this->centralAdmin();
        $case = app(ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => ReturnExchangeCaseSourceKind::EcommerceFulfilment->value,
            'hardware_fulfilment_id' => $fulfilment->id,
            'reason' => 'Split tender',
            'lines' => [['description' => 'Hardware', 'qty' => 1]],
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

        return $case->fresh();
    }

    /**
     * @return array{0: \App\Models\ReturnExchangeCase, 1: User, 2: Order, 3: CommerceOrder, 4: StatutoryInvoice}
     */
    protected function splitTenderFulfilmentReady(
        bool $multiItem = false,
        ?string $walletAmount = '499.00',
    ): array {
        $branchUser = $this->branchAdminFor($this->branchA->id);
        $external = $multiItem ? 4000.00 : 2000.00;
        $wallet = $walletAmount !== null ? (float) $walletAmount : 0.0;
        $orderValue = $wallet > 0 ? $external + $wallet : $external;

        $order = $this->createOrder([
            'order_id' => 'RB'.random_int(10000, 99999),
            'payment_amount' => $external,
            'cashfree_payment_id' => 'cf_split_'.random_int(10000, 99999),
        ]);
        $commerce = $this->createCommerceOrderForSplitTender($order, $walletAmount, $orderValue);
        $fulfilment = HardwareFulfilment::query()->create([
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
        CommerceOrderItem::query()->create([
            'commerce_order_id' => $commerce->id,
            'line_no' => 1,
            'sku' => 'HW-RET',
            'requires_shipping' => true,
            'shipping_line_kind' => 'product',
            'description' => 'Returned hardware',
            'qty' => 1,
            'unit_price' => 1500,
            'line_total' => '1770.00',
            'taxable_value' => '1500.00',
            'tax_total' => '270.00',
        ]);
        if ($multiItem) {
            CommerceOrderItem::query()->create([
                'commerce_order_id' => $commerce->id,
                'line_no' => 2,
                'sku' => 'HW-OTHER',
                'requires_shipping' => true,
                'shipping_line_kind' => 'product',
                'description' => 'Other hardware',
                'qty' => 1,
                'unit_price' => 2000,
                'line_total' => '2360.00',
                'taxable_value' => '2000.00',
                'tax_total' => '360.00',
            ]);
        }
        $invoice = $this->createOrderInvoice($order, $fulfilment, 'INV-SPLIT-'.random_int(100, 999));
        $fulfilment->update(['statutory_invoice_id' => $invoice->id]);

        $central = $this->centralAdmin();
        $case = app(ReturnExchangeCaseIntakeService::class)->create($central, [
            'source_kind' => ReturnExchangeCaseSourceKind::EcommerceFulfilment->value,
            'hardware_fulfilment_id' => $fulfilment->id,
            'reason' => 'Split tender return',
            'lines' => [['description' => 'Returned hardware', 'qty' => 1]],
        ]);

        return [$case, $branchUser, $order, $commerce, $invoice];
    }

    private function createFulfilmentWithSplitTender(Order $order, ?string $walletAmount, string $orderValue): HardwareFulfilment
    {
        $commerce = $this->createCommerceOrderForSplitTender($order, $walletAmount, (float) $orderValue);

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

    protected function createCommerceOrderForSplitTender(Order $order, ?string $walletAmount, float $orderValue): CommerceOrder
    {
        return CommerceOrder::query()->create([
            'order_no' => 'CO-SPLIT-'.$order->order_id,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => 'commerce_order',
            'source_id' => (string) $order->order_id,
            'idempotency_key' => 'ec-split-'.$order->id,
            'payload_hash' => hash('sha256', (string) $order->id),
            'status' => CommerceOrderStatus::InvoicePending,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'order_value' => number_format($orderValue, 2, '.', ''),
            'wallet_tender_amount' => $walletAmount,
            'wallet_tender_reference' => $walletAmount !== null ? 'wallet-ref-'.$order->id : null,
            'received_at' => now(),
            'support_order_id' => $order->id,
        ]);
    }

    protected function branchAdminFor(int $branchId): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->assignRole(RolePermissionSeeder::ROLE_ADMIN);
        InventoryUserBranch::query()->create([
            'user_id' => $user->id,
            'branch_id' => $branchId,
        ]);

        return $user;
    }

    protected function createOrderInvoice(Order $order, HardwareFulfilment $fulfilment, string $invoiceNumber): StatutoryInvoice
    {
        return StatutoryInvoice::query()->create([
            'invoice_number' => $invoiceNumber,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'support_order_id' => $order->id,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => (string) $fulfilment->commerce_order_id,
            'idempotency_key' => 'inv-split-'.$invoiceNumber,
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

    protected function attachCancellation(StatutoryInvoice $invoice, User $actor): void
    {
        StatutoryInvoiceCancellation::query()->create([
            'statutory_invoice_id' => $invoice->id,
            'idempotency_key' => 'cancel-split-'.$invoice->id,
            'actor_id' => $actor->id,
            'reason' => 'Test cancellation',
            'completed_at' => now(),
        ]);
    }
}
