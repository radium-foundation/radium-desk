<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Enums\StatutoryInvoiceCancelledRecoveryPaymentPath;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\CustomerPayment;
use App\Models\InventoryCustomer;
use App\Models\PaymentAllocation;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancelledRecoveryService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class StatutoryInvoiceCancelledRecoveryTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
    }

    public function test_assessment_not_applicable_for_issued_invoice(): void
    {
        $invoice = $this->cancelledInvoiceFactory(StatutoryInvoiceStatus::Issued);

        $assessment = app(StatutoryInvoiceCancelledRecoveryService::class)->assess($invoice);

        $this->assertFalse($assessment->applies);
        $this->assertSame(StatutoryInvoiceCancelledRecoveryPaymentPath::NotCancelled, $assessment->paymentPath);
    }

    public function test_cancelled_unpaid_invoice_shows_recovery_panel_and_blocks_replacement(): void
    {
        $invoice = $this->cancelledInvoiceFactory();
        $user = $this->financeViewer();

        $this->actingAs($user)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Cancelled invoice recovery', false)
            ->assertSee('statutory_invoices_source_unique', false);

        $assessment = app(StatutoryInvoiceCancelledRecoveryService::class)->assess($invoice);
        $this->assertTrue($assessment->applies);
        $this->assertSame(StatutoryInvoiceCancelledRecoveryPaymentPath::Unpaid, $assessment->paymentPath);
        $this->assertFalse($assessment->replacementMintAllowed);
        $this->assertNotEmpty($assessment->replacementBlockers);
        $this->assertFalse($assessment->walletToOpmSupportedOnMain);
    }

    public function test_cancelled_invoice_with_desk_allocation_classified_as_paid_path(): void
    {
        $invoice = $this->cancelledInvoiceFactory();
        $customer = InventoryCustomer::query()->create([
            'name' => 'Recovery Payer',
            'phone' => '9000000001',
        ]);
        $payment = CustomerPayment::query()->create([
            'payment_number' => 'CP-REC-1',
            'customer_id' => $customer->id,
            'amount' => '118.00',
            'method' => 'Bank Transfer',
            'payment_date' => now()->toDateString(),
        ]);
        PaymentAllocation::query()->create([
            'customer_payment_id' => $payment->id,
            'statutory_invoice_id' => $invoice->id,
            'amount' => '118.00',
            'allocated_at' => now(),
        ]);

        $assessment = app(StatutoryInvoiceCancelledRecoveryService::class)->assess($invoice->fresh());

        $this->assertSame(StatutoryInvoiceCancelledRecoveryPaymentPath::PaidDeskAllocations, $assessment->paymentPath);
        $this->assertGreaterThan(0, $assessment->deskAmountReceived);
    }

    public function test_find_by_source_returns_cancelled_invoice_blocking_replacement_mint(): void
    {
        $invoice = $this->cancelledInvoiceFactory();

        $sourceType = $invoice->source_type instanceof StatutoryInvoiceSourceType
            ? $invoice->source_type
            : StatutoryInvoiceSourceType::from((string) $invoice->source_type);

        $found = app(StatutoryInvoiceService::class)->findBySource(
            $invoice->channel,
            $sourceType,
            (string) $invoice->source_id,
        );

        $this->assertNotNull($found);
        $this->assertSame($invoice->id, $found->id);
        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $found->status);
    }

    private function cancelledInvoiceFactory(
        StatutoryInvoiceStatus $status = StatutoryInvoiceStatus::Cancelled,
    ): StatutoryInvoice {
        return StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-REC-'.uniqid(),
            'document_type' => \App\Enums\StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => $status,
            'channel' => \App\Enums\StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => \App\Enums\StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RB-REC-'.uniqid(),
            'idempotency_key' => 'test:rec:'.uniqid(),
            'buyer_gstin' => '07AABCU9603R1ZM',
            'buyer_name' => 'Recovery Buyer',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'invoice_value' => 118,
            'tax_total' => 18,
            'issued_at' => now()->subDay(),
            'cancelled_at' => $status === StatutoryInvoiceStatus::Cancelled ? now() : null,
        ]);
    }

    private function financeViewer(): User
    {
        $user = User::factory()->create(['is_active' => true]);
        $user->givePermissionTo(RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_VIEW);
        $user->givePermissionTo(RolePermissionSeeder::PERMISSION_FINANCE_VIEW);

        return $user;
    }
}
