<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceCancellationOperationStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationOrchestrator;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Tests\Support\FakeEInvoiceGateway;
use Tests\TestCase;

class StatutoryInvoiceCancellationIrnPendingRecoveryTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();

        config([
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.einvoice.irn_cancellation_window_hours' => 24,
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_reconcile_command_recovers_irn_pending_with_get_irn_only_when_active(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: str_repeat('a', 64),
            payload: ['Status' => 'ACT'],
        ));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice();
        $this->attachIrn($invoice);
        $operation = $this->strandedIrnPendingOperation($invoice);

        $exit = Artisan::call('desk:statutory-cancellation-reconcile', ['--invoice' => $invoice->id]);

        $this->assertSame(0, $exit);
        $this->assertSame(0, $fake->cancelCount);
        $this->assertSame(1, $fake->fetchCount);
        $operation->refresh();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::FailedRetryable, $operation->status);
        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);
    }

    public function test_reconcile_command_completes_when_provider_shows_cancelled_irn(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: str_repeat('a', 64),
            payload: ['Status' => 'CNL', 'CancelDate' => '2026-10-27'],
        ));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice();
        $this->attachIrn($invoice);
        $operation = $this->strandedIrnPendingOperation($invoice);

        Artisan::call('desk:statutory-cancellation-reconcile', ['--invoice' => $invoice->id]);

        $this->assertSame(0, $fake->cancelCount);
        $this->assertSame(1, $fake->fetchCount);
        $operation->refresh();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::Completed, $operation->status);
        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $invoice->fresh()->status);
    }

    public function test_irn_pending_unknown_moves_to_reconciliation_pending_without_cancel(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueFetch(EInvoiceSubmitResult::irnNotFound('fake', ['error_code' => '2154']));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice();
        $this->attachIrn($invoice);
        $operation = $this->strandedIrnPendingOperation($invoice);

        try {
            app(StatutoryInvoiceCancellationOrchestrator::class)->reconcileInFlightCancellation($operation);
            $this->fail('Expected validation');
        } catch (\Illuminate\Validation\ValidationException) {
        }

        $this->assertSame(0, $fake->cancelCount);
        $this->assertSame(1, $fake->fetchCount);
        $operation->refresh();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::IrnReconciliationPending, $operation->status);
    }

    public function test_reconcile_command_includes_irn_pending_in_selection(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: str_repeat('a', 64),
            payload: ['Status' => 'ACT'],
        ));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice();
        $this->attachIrn($invoice);
        $this->strandedIrnPendingOperation($invoice);

        Artisan::call('desk:statutory-cancellation-reconcile', ['--invoice' => $invoice->id]);

        $this->assertStringContainsString('Operation', Artisan::output());
        $this->assertSame(0, $fake->cancelCount);
        $this->assertSame(
            StatutoryInvoiceCancellationOperationStatus::FailedRetryable,
            StatutoryInvoiceCancellation::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail()->status,
        );
    }

    private function strandedIrnPendingOperation(StatutoryInvoice $invoice): StatutoryInvoiceCancellation
    {
        return StatutoryInvoiceCancellation::query()->create([
            'statutory_invoice_id' => $invoice->id,
            'idempotency_key' => StatutoryInvoiceCancellationOrchestrator::DEFAULT_IDEMPOTENCY_PREFIX.$invoice->id,
            'actor_id' => $this->admin->id,
            'reason' => 'Interrupted',
            'status' => StatutoryInvoiceCancellationOperationStatus::IrnPending,
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'attempt_count' => 1,
        ]);
    }

    private function issueB2bInvoice(): StatutoryInvoice
    {
        return StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-REC-'.uniqid(),
            'document_type' => \App\Enums\StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => \App\Enums\StatutoryInvoiceChannel::DeskPos,
            'source_type' => \App\Enums\StatutoryInvoiceSourceType::InventorySale,
            'source_id' => 'SALE-REC',
            'idempotency_key' => 'test:rec:'.uniqid(),
            'buyer_gstin' => '07AABCU9603R1ZM',
            'buyer_name' => 'B2B Buyer',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'invoice_value' => 118,
            'tax_total' => 18,
            'issued_at' => now(),
        ]);
    }

    private function attachIrn(StatutoryInvoice $invoice): void
    {
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'irn' => str_repeat('a', 64),
            'ack_no' => 'ACK-1',
            'ack_date' => now()->subHours(2),
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);
    }
}
