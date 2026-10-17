<?php

namespace Tests\Feature\ReturnExchange\Concerns;

use App\Enums\CommerceOrderStatus;
use App\Enums\HardwareFulfilmentState;
use App\Enums\ReturnExchangeCaseSourceKind;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderItem;
use App\Models\HardwareFulfilment;
use App\Models\InventoryUserBranch;
use App\Models\Order;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\Services\ReturnExchange\ReturnExchangeCaseGoodsReceiptService;
use App\Services\ReturnExchange\ReturnExchangeCaseIntakeService;
use Database\Seeders\RolePermissionSeeder;

trait PreparesSplitTenderOnlineReturnCases
{
    /**
     * @return array{0: \App\Models\ReturnExchangeCase, 1: User, 2: Order}
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
