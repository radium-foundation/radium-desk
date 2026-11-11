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

    public function test_legacy_payment_method_without_allocations_classified_as_legacy_paid_path(): void
    {
        $invoice = $this->cancelledInvoiceFactory(paymentMethod: 'Cash');

        $assessment = app(StatutoryInvoiceCancelledRecoveryService::class)->assess($invoice);

        $this->assertSame(
            StatutoryInvoiceCancelledRecoveryPaymentPath::PaidLegacyInvoiceFields,
            $assessment->paymentPath,
        );
        $this->assertTrue($assessment->requiresExternalPaymentVerification());
    }

    public function test_zero_value_commerce_invoice_without_payment_fields_is_ambiguous(): void
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-REC-'.uniqid(),
            'document_type' => \App\Enums\StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Cancelled,
            'channel' => \App\Enums\StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => \App\Enums\StatutoryInvoiceSourceType::CommerceOrder,
            'source_id' => 'RB-REC-'.uniqid(),
            'idempotency_key' => 'test:rec:'.uniqid(),
            'buyer_gstin' => '07AABCU9603R1ZM',
            'buyer_name' => 'Recovery Buyer',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 0,
            'invoice_value' => 0,
            'tax_total' => 0,
            'issued_at' => now()->subDay(),
            'cancelled_at' => now(),
        ]);

        $assessment = app(StatutoryInvoiceCancelledRecoveryService::class)->assess($invoice);

        $this->assertSame(StatutoryInvoiceCancelledRecoveryPaymentPath::Ambiguous, $assessment->paymentPath);
    }

    public function test_panel_does_not_imply_replacement_mint_is_executable(): void
    {
        $invoice = $this->cancelledInvoiceFactory();
        $user = $this->financeViewer();

        $this->actingAs($user)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('guidance only', false)
            ->assertDontSee('Replacement mint is permitted', false);
    }

    public function test_user_without_finance_invoice_access_cannot_view_recovery_panel(): void
    {
        $invoice = $this->cancelledInvoiceFactory();
        $user = User::factory()->create(['is_active' => true]);

        $this->actingAs($user)
            ->get(route('finance.invoices.show', $invoice))
            ->assertForbidden();
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
        ?string $paymentMethod = null,
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
            'payment_method' => $paymentMethod,
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
