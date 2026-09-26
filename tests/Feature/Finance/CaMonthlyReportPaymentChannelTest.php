<?php

namespace Tests\Feature\Finance;

use App\Enums\CommerceOrderStatus;
use App\Enums\InventorySaleStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilmentPaymentEvidence;
use App\Models\InventoryBranch;
use App\Models\InventorySale;
use App\Models\Order;
use App\ReadModels\Finance\CaMonthlyStatutoryLineReadModel;
use App\Reports\CaMonthly\CaMonthlyReportPaymentChannelResolver;
use App\Support\Inventory\PosSalePaymentState;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Request;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\TestCase;

class CaMonthlyReportPaymentChannelTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private const RANGE = [
        'date_from' => '2026-09-01',
        'date_to' => '2026-09-21',
    ];

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
    }

    public function test_cashfree_gateway_with_upi_instrument_exports_channel_and_method_separately(): void
    {
        $supportOrder = Order::query()->create([
            'order_id' => 'RD-CH-UPI',
            'serial_number' => 'SN-CH-UPI',
            'product_name' => 'Service',
            'device_model' => 'Model',
            'customer_name' => 'Buyer',
            'payment_method' => 'UPI',
            'cashfree_payment_id' => 'cf_pay_upi_1',
            'status' => 'active',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'support_order_id' => $supportOrder->id,
            'source_order_id' => 'RD-CH-UPI',
            'payment_method' => 'cashfree',
            'payment_reference' => 'RD-CH-UPI',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CF, $row[21]);
        $this->assertSame('UPI', $row[22]);
        $this->assertSame('RD-CH-UPI', $row[23]);
    }

    public function test_cashfree_gateway_with_card_instrument(): void
    {
        $supportOrder = Order::query()->create([
            'order_id' => 'RD-CH-CARD',
            'serial_number' => 'SN-CH-CARD',
            'product_name' => 'Service',
            'device_model' => 'Model',
            'customer_name' => 'Buyer',
            'payment_method' => 'card',
            'cashfree_payment_id' => 'cf_pay_card_1',
            'status' => 'active',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'support_order_id' => $supportOrder->id,
            'payment_method' => 'cashfree',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CF, $row[21]);
        $this->assertSame('Card', $row[22]);
    }

    public function test_cashfree_gateway_with_net_banking_instrument(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'source_order_id' => 'RDE-CH-NB',
            'payment_method' => 'cashfree',
        ]);

        HardwareFulfilmentPaymentEvidence::query()->create([
            'source_id' => 'RDE-CH-NB',
            'cashfree_payment_id' => 'cf_pay_nb_1',
            'payment_status' => 'SUCCESS',
            'verified' => true,
            'payment_method' => 'netbanking',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CF, $row[21]);
        $this->assertSame('Net Banking', $row[22]);
        $this->assertSame('cf_pay_nb_1', $row[23]);
    }

    public function test_cashfree_gateway_with_wallet_instrument(): void
    {
        $supportOrder = Order::query()->create([
            'order_id' => 'RD-CH-WALLET',
            'serial_number' => 'SN-CH-WALLET',
            'product_name' => 'Service',
            'device_model' => 'Model',
            'customer_name' => 'Buyer',
            'payment_method' => 'wallet',
            'cashfree_payment_id' => 'cf_pay_wallet_1',
            'status' => 'active',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'support_order_id' => $supportOrder->id,
            'payment_method' => 'cashfree',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CF, $row[21]);
        $this->assertSame('Wallet', $row[22]);
    }

    public function test_hdfc_m_direct_channel(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'payment_method' => 'HDFC M',
            'payment_reference' => 'UTR-HDFC-M-1',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_HDFC_M, $row[21]);
        $this->assertSame('Hdfc M', $row[22]);
        $this->assertSame('UTR-HDFC-M-1', $row[23]);
    }

    public function test_hdfc_d_direct_channel(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'payment_method' => 'HDFC D',
            'payment_reference' => 'UTR-HDFC-D-1',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_HDFC_D, $row[21]);
        $this->assertSame('Hdfc D', $row[22]);
    }

    public function test_cash_direct_channel(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'payment_method' => 'Cash',
            'payment_reference' => 'CASH-1',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CASH, $row[21]);
        $this->assertSame('Cash', $row[22]);
    }

    public function test_indus_payment_evidence_is_not_mapped_to_a_primary_channel(): void
    {
        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'payment_method' => 'INDUS',
            'payment_reference' => 'INDUS-REF-1',
        ]);

        $preflight = app(CaMonthlyStatutoryLineReadModel::class)->preflight($this->request());
        $row = $this->firstRow();

        $this->assertSame('', $row[21]);
        $this->assertSame('Indus', $row[22]);
        $this->assertSame(1, $preflight->unclassifiedPaymentChannelCount);
    }

    public function test_unpaid_pos_sale_exports_unpaid_channel_without_internal_marker_reference(): void
    {
        $branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'gstin' => '07AAICP1128M1Z9',
            'is_active' => true,
        ]);

        $sale = InventorySale::query()->create([
            'sale_no' => 'POS-CH-UNPAID-1',
            'invoice_number' => 'INV-CH-UNPAID-1',
            'branch_id' => $branch->id,
            'status' => InventorySaleStatus::Completed,
            'subtotal' => 100,
            'discount' => 0,
            'tax' => 18,
            'total' => 118,
            'payment_method' => 'HDFC M',
            'payment_reference' => PosSalePaymentState::PAYMENT_PENDING_REFERENCE,
            'finance_handoff_status' => 'posted',
            'completed_at' => '2026-09-10 10:00:00',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'invoice_number' => 'INV-CH-UNPAID-1',
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'inventory_sale_id' => $sale->id,
            'payment_method' => null,
            'payment_reference' => null,
            'invoice_value' => '118.00',
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_UNPAID, $row[21]);
        $this->assertSame('', $row[22]);
        $this->assertSame('', $row[23]);
    }

    public function test_upi_without_cashfree_evidence_is_not_mapped_to_cf(): void
    {
        $supportOrder = Order::query()->create([
            'order_id' => 'RD-OTHER-UPI',
            'serial_number' => 'SN-OTHER',
            'product_name' => 'Service',
            'device_model' => 'Model',
            'customer_name' => 'Buyer',
            'payment_method' => 'UPI',
            'status' => 'active',
        ]);

        $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'support_order_id' => $supportOrder->id,
            'payment_method' => 'UPI',
            'payment_reference' => 'MANUAL-UPI-1',
        ]);

        $row = $this->firstRow();

        $this->assertSame('', $row[21]);
        $this->assertSame('UPI', $row[22]);
        $this->assertNotSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CF, $row[21]);
    }

    public function test_commerce_cashfree_snapshot_without_support_instrument_still_classifies_cf_channel(): void
    {
        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-09-10 10:00:00',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RB-CF-ONLY',
            'source_order_id' => 'RB-CF-ONLY',
            'payment_method' => 'cashfree',
            'payment_reference' => 'RB-CF-ONLY',
        ]);

        CommerceOrder::query()->create([
            'order_no' => 'CO-RB-CF-ONLY',
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RB-CF-ONLY',
            'source_order_id' => 'RB-CF-ONLY',
            'idempotency_key' => 'statutory:radiumbox_com:commerce_order:RB-CF-ONLY',
            'payload_hash' => hash('sha256', 'RB-CF-ONLY'),
            'status' => CommerceOrderStatus::Invoiced,
            'invoice_eligible' => true,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer_name' => 'Buyer',
            'billing_state' => 'Delhi',
            'billing_address' => '1 Test Street',
            'shipping_address' => '1 Test Street',
            'place_of_supply_state' => 'Delhi',
            'payment_method' => 'cashfree',
            'payment_reference' => 'RB-CF-ONLY',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'order_value' => 118.00,
            'ordered_at' => '2026-09-05 08:00:00',
            'received_at' => now(),
            'statutory_invoice_id' => $invoice->id,
        ]);

        $row = $this->firstRow();

        $this->assertSame(CaMonthlyReportPaymentChannelResolver::CHANNEL_CF, $row[21]);
        $this->assertSame('', $row[22]);
    }

    /**
     * @return list<string>
     */
    private function firstRow(): array
    {
        return app(CaMonthlyStatutoryLineReadModel::class)->exportRows($this->request())[0];
    }

    private function request(): Request
    {
        return Request::create('/finance/reports/ca-monthly', 'GET', self::RANGE);
    }
}
