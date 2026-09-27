<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceSourceType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\AuditLog;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\StatutoryInvoiceItem;
use App\Models\User;
use App\Services\StatutoryInvoice\Data\EInvoiceCancelResult;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationOrchestrator;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksEInvoiceGateway;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeEInvoiceGateway;
use Tests\TestCase;

class StatutoryCancellationWorkflowTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);
        $this->configureLocationSellerIdentity();

        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
            'statutory_invoices.einvoice.irn_cancellation_window_hours' => 24,
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_b2b_within_window_successfully_cancels_irn_and_invoice(): void
    {
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('WF-1');
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);

        $result = $this->orchestrator()->cancel(
            invoice: $invoice->fresh(['eInvoiceRecord', 'inventorySale']),
            actor: $this->admin,
            reason: 'Within window cancel',
            idempotencyKey: $this->cancelKey($invoice),
        );

        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $result->invoice->status);
        $this->assertSame('success', $result->irnAction['status']);
        $this->assertSame('not_required', $result->creditNoteAction['status']);
        $this->assertSame(1, $fake->cancelCount);
    }

    public function test_b2b_within_window_irn_failure_blocks_local_cancellation(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::permanentFailure('fake', ['reason' => 'simulated']));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('WF-2');
        $this->attachSubmittedIrn($invoice, hoursAgo: 1);

        $this->expectException(ValidationException::class);
        $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord']), $this->admin, 'Fail IRN', $this->cancelKey($invoice));

        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame(0, StatutoryInvoiceCancellation::query()->count());
    }

    public function test_b2b_beyond_window_keeps_original_invoice_active(): void
    {
        $invoice = $this->issueB2bInvoice('WF-3');
        $this->attachSubmittedIrn($invoice, hoursAgo: 30);

        $result = $this->orchestrator()->cancel(
            invoice: $invoice->fresh(['eInvoiceRecord', 'items']),
            actor: $this->admin,
            reason: 'Beyond window adjustment',
            idempotencyKey: $this->cancelKey($invoice),
        );

        $this->assertSame(StatutoryInvoiceStatus::Issued, $result->invoice->status);
        $this->assertNull($result->invoice->cancelled_at);
    }

    public function test_b2b_beyond_window_issues_credit_note(): void
    {
        $invoice = $this->issueB2bInvoice('WF-4');
        $this->attachSubmittedIrn($invoice, hoursAgo: 48);

        $result = $this->orchestrator()->cancel(
            invoice: $invoice->fresh(['eInvoiceRecord', 'items']),
            actor: $this->admin,
            reason: 'Beyond window CN',
            idempotencyKey: $this->cancelKey($invoice),
        );

        $this->assertSame('issued', $result->creditNoteAction['status']);
        $creditNote = StatutoryInvoice::query()
            ->where('original_statutory_invoice_id', $invoice->id)
            ->where('document_type', StatutoryInvoiceDocumentType::CreditNote)
            ->first();
        $this->assertNotNull($creditNote);
        $this->assertSame((int) $result->creditNoteAction['credit_note_id'], $creditNote->id);
    }

    public function test_b2b_beyond_window_queues_credit_note_irn_when_eligible(): void
    {
        config(['statutory_invoices.einvoice.issuance_policy' => 'all_eligible_b2b']);
        $invoice = $this->issueB2bInvoice('WF-5');
        $this->attachSubmittedIrn($invoice, hoursAgo: 72);

        $this->orchestrator()->cancel(
            invoice: $invoice->fresh(['eInvoiceRecord', 'items']),
            actor: $this->admin,
            reason: 'CN IRN path',
            idempotencyKey: $this->cancelKey($invoice),
        );

        $creditNote = StatutoryInvoice::query()
            ->where('original_statutory_invoice_id', $invoice->id)
            ->firstOrFail();
        $record = EInvoiceRecord::query()->where('invoice_id', $creditNote->id)->first();
        $this->assertNotNull($record);
        $this->assertSame(EInvoiceRecordStatus::Queued->value, $record->status);
    }

    public function test_credit_note_is_linked_to_original_invoice(): void
    {
        $invoice = $this->issueB2bInvoice('WF-6');
        $this->attachSubmittedIrn($invoice, hoursAgo: 96);

        $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Link CN', $this->cancelKey($invoice));

        $creditNote = StatutoryInvoice::query()->where('original_statutory_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame($invoice->id, $creditNote->original_statutory_invoice_id);
        $this->assertSame($invoice->invoice_value, $creditNote->invoice_value);
    }

    public function test_credit_note_retry_is_idempotent(): void
    {
        $invoice = $this->issueB2bInvoice('WF-7');
        $this->attachSubmittedIrn($invoice, hoursAgo: 120);

        $key = $this->cancelKey($invoice);
        $first = $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'CN once', $key);
        $second = $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'CN once', $key);

        $this->assertFalse($first->idempotent);
        $this->assertTrue($second->idempotent);
        $this->assertSame(1, StatutoryInvoice::query()->where('document_type', StatutoryInvoiceDocumentType::CreditNote)->count());
    }

    public function test_duplicate_cancellation_request_is_idempotent(): void
    {
        $invoice = $this->issueB2cInvoice('WF-8');
        $key = $this->cancelKey($invoice);
        $this->orchestrator()->cancel($invoice, $this->admin, 'Once', $key);
        $again = $this->orchestrator()->cancel($invoice->fresh(), $this->admin, 'Once', $key);
        $this->assertTrue($again->idempotent);
        $this->assertSame(1, StatutoryInvoiceCancellation::query()->count());
    }

    public function test_whitebooks_unavailable_blocks_within_window_cancellation(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::providerNotImplemented('fake'));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('WF-9');
        $this->attachSubmittedIrn($invoice, hoursAgo: 3);

        $this->expectException(ValidationException::class);
        $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord']), $this->admin, 'Provider down', $this->cancelKey($invoice));
    }

    public function test_provider_timeout_is_recorded_as_unknown_without_local_cancel(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::unknown('fake', ['reason' => 'cancel_timeout']));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('WF-10');
        $this->attachSubmittedIrn($invoice, hoursAgo: 4);

        $this->expectException(ValidationException::class);
        $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord']), $this->admin, 'Timeout', $this->cancelKey($invoice));
        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);
    }

    public function test_no_irn_invoice_cancels_without_gateway(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('WF-11');
        $result = $this->orchestrator()->cancel($invoice, $this->admin, 'No IRN', $this->cancelKey($invoice));

        $this->assertSame(0, $fake->cancelCount);
        $this->assertSame('not_required', $result->irnAction['status']);
        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $result->invoice->status);
    }

    public function test_b2c_branch_cancels_without_irn_workflow(): void
    {
        $invoice = $this->issueB2cInvoice('WF-12');
        $result = $this->orchestrator()->cancel($invoice, $this->admin, 'B2C cancel', $this->cancelKey($invoice));

        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $result->invoice->status);
        $this->assertSame('not_required', $result->irnAction['status']);
        $this->assertSame('not_required', $result->creditNoteAction['status']);
    }

    public function test_cancellation_without_issued_invoice_is_rejected(): void
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-WF-13',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Cancelled,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::External->value,
            'source_id' => 'wf-cancelled-13',
            'idempotency_key' => 'statutory:wf-cancelled:13',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Retail Customer',
            'billing_address' => '1 Test Street, Delhi',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'cgst' => 9.00,
            'sgst' => 9.00,
            'igst' => 0.00,
            'rounding' => 0.00,
            'invoice_value' => 118.00,
            'issued_at' => now()->subDays(2),
            'cancelled_at' => now()->subDay(),
            'cancel_reason' => 'pre-cancelled',
        ]);

        $result = $this->orchestrator()->cancel($invoice->fresh(), $this->admin, 'Again', $this->cancelKey($invoice));
        $this->assertTrue($result->idempotent);
    }

    public function test_audit_is_complete_for_credit_note_adjustment(): void
    {
        $invoice = $this->issueB2bInvoice('WF-14');
        $this->attachSubmittedIrn($invoice, hoursAgo: 50);

        $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Audit CN', $this->cancelKey($invoice));

        $log = AuditLog::query()
            ->where('event', StatutoryInvoiceCancellationOrchestrator::EVENT_ADJUSTED)
            ->where('auditable_id', $invoice->id)
            ->first();
        $this->assertNotNull($log);
        $this->assertSame('issued', $log->new_values['status'] ?? null);
        $this->assertSame('issued', $log->new_values['credit_note_action']['status'] ?? null);
    }

    public function test_original_invoice_values_remain_unchanged_in_beyond_window_case(): void
    {
        $invoice = $this->issueB2bInvoice('WF-15');
        $this->attachSubmittedIrn($invoice, hoursAgo: 80);
        $before = $invoice->only(['invoice_number', 'taxable_value', 'invoice_value', 'status']);

        $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Preserve original', $this->cancelKey($invoice));

        $after = $invoice->fresh()->only(['invoice_number', 'taxable_value', 'invoice_value', 'status']);
        $this->assertSame($before, $after);
    }

    public function test_whitebooks_cancel_success_persists_only_confirmed_cancellation(): void
    {
        Http::preventStrayRequests();
        $this->configureWhitebooksForCancel();

        $invoice = $this->issueB2bInvoice('WF-16');
        $irn = str_repeat('e', 64);
        $this->attachSubmittedIrn($invoice, hoursAgo: 1, irn: $irn);

        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => 'WB-TOKEN']], 200),
            'https://api.whitebooks.in/einvoice/type/CANCEL/*' => Http::response([
                'status_cd' => '1',
                'status_desc' => 'GSTR request succeeds',
                'data' => ['Irn' => $irn, 'CancelDate' => '26-09-2026 12:00:00'],
            ], 200),
        ]);

        $this->app->instance(EInvoiceGateway::class, app(WhitebooksEInvoiceGateway::class));
        $result = $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord']), $this->admin, 'WB cancel', $this->cancelKey($invoice));

        $this->assertSame('success', $result->irnAction['status']);
        $this->assertTrue((bool) data_get($invoice->fresh('eInvoiceRecord')->eInvoiceRecord?->response_payload, 'irn_cancelled'));
    }

    public function test_inv_0767138_regression_already_cancelled_is_idempotent_without_cn(): void
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-0767138',
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Cancelled,
            'channel' => StatutoryInvoiceChannel::RadiumBoxCom,
            'source_type' => StatutoryInvoiceSourceType::CommerceOrder->value,
            'source_id' => 'RDE318338',
            'idempotency_key' => 'statutory:regression:inv-0767138',
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer Industries',
            'buyer_gstin' => '21AAOPA5417F1Z8',
            'billing_address' => '1 Test Street, Odisha',
            'place_of_supply_state' => 'Odisha',
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'cgst' => 0.00,
            'sgst' => 0.00,
            'igst' => 18.00,
            'rounding' => 0.00,
            'invoice_value' => 118.00,
            'issued_at' => now()->subDays(8),
            'cancelled_at' => now()->subDays(8),
            'cancel_reason' => 'Historical duplicate Desk fulfilment cancellation.',
        ]);
        $this->attachSubmittedIrn($invoice, hoursAgo: 200);

        $result = $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Must not mutate', $this->cancelKey($invoice));

        $this->assertTrue($result->idempotent);
        $this->assertSame(0, StatutoryInvoice::query()->where('document_type', StatutoryInvoiceDocumentType::CreditNote)->count());
        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $invoice->fresh()->status);
    }

    private function orchestrator(): StatutoryInvoiceCancellationOrchestrator
    {
        return app(StatutoryInvoiceCancellationOrchestrator::class);
    }

    private function cancelKey(StatutoryInvoice $invoice): string
    {
        return StatutoryInvoiceCancellationOrchestrator::DEFAULT_IDEMPOTENCY_PREFIX.$invoice->id;
    }

    private function issueB2bInvoice(string $marker): StatutoryInvoice
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-'.$marker,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::External->value,
            'source_id' => 'wf-'.$marker,
            'idempotency_key' => 'statutory:wf:'.$marker,
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Buyer Industries',
            'buyer_gstin' => '07AAAAA0000A1Z5',
            'billing_address' => '1 Test Street, Delhi',
            'billing_address_structured' => [
                'line1' => '1 Test Street',
                'city' => 'New Delhi',
                'state' => 'Delhi',
                'pincode' => '110001',
            ],
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'discount' => 0.00,
            'tax_total' => 18.00,
            'cgst' => 9.00,
            'sgst' => 9.00,
            'igst' => 0.00,
            'rounding' => 0.00,
            'invoice_value' => 118.00,
            'issued_at' => now()->subDays(10),
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'description' => 'Hardware Item',
            'hsn_sac' => '84716050',
            'qty' => 1,
            'unit_price' => 100.00,
            'discount' => 0.00,
            'gst_percentage' => 18.00,
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'cgst' => 9.00,
            'sgst' => 9.00,
            'igst' => 0.00,
            'line_total' => 118.00,
        ]);

        return $invoice->fresh(['items']) ?? $invoice;
    }

    private function issueB2cInvoice(string $marker): StatutoryInvoice
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-'.$marker,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::External->value,
            'source_id' => 'wf-b2c-'.$marker,
            'idempotency_key' => 'statutory:wf-b2c:'.$marker,
            'seller_gstin' => '07AAICP1128M1Z9',
            'seller_name' => 'Phil Technologies (P) Limited',
            'buyer_name' => 'Retail Customer',
            'buyer_gstin' => null,
            'billing_address' => '1 Test Street, Delhi',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100.00,
            'discount' => 0.00,
            'tax_total' => 18.00,
            'cgst' => 9.00,
            'sgst' => 9.00,
            'igst' => 0.00,
            'rounding' => 0.00,
            'invoice_value' => 118.00,
            'issued_at' => now()->subDays(2),
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'sku' => 'RBMFS110L1',
            'description' => 'Hardware Item',
            'hsn_sac' => '84716050',
            'qty' => 1,
            'unit_price' => 100.00,
            'discount' => 0.00,
            'gst_percentage' => 18.00,
            'taxable_value' => 100.00,
            'tax_total' => 18.00,
            'cgst' => 9.00,
            'sgst' => 9.00,
            'igst' => 0.00,
            'line_total' => 118.00,
        ]);

        return $invoice->fresh(['items']) ?? $invoice;
    }

    private function attachSubmittedIrn(StatutoryInvoice $invoice, int $hoursAgo, ?string $irn = null): void
    {
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => 'fake',
                'irn' => $irn ?? str_repeat('a', 64),
                'ack_no' => 'ACK-WF',
                'ack_date' => Carbon::now()->subHours($hoursAgo),
                'status' => EInvoiceRecordStatus::Submitted->value,
            ],
        );
    }

    private function configureWhitebooksForCancel(): void
    {
        config([
            'statutory_invoices.einvoice.provider' => 'whitebooks',
            'statutory_invoices.einvoice.gsp_client_id' => 'wb-client',
            'statutory_invoices.einvoice.gsp_client_secret' => 'wb-secret',
            'statutory_invoices.einvoice.gsp_email' => 'gsp@example.com',
            'statutory_invoices.einvoice.gsp_ip_address' => '203.0.113.10',
            'statutory_invoices.location_series.locations.delhi.gstin' => '07AAICP1128M1Z9',
            'statutory_invoices.location_series.locations.delhi.gst_username' => 'delhi-gst-user',
            'statutory_invoices.location_series.locations.delhi.gst_password' => 'delhi-gst-pass',
            'statutory_invoices.einvoice.issuers.delhi.gst_username' => 'delhi-gst-user',
            'statutory_invoices.einvoice.issuers.delhi.gst_password' => 'delhi-gst-pass',
        ]);
    }
}
