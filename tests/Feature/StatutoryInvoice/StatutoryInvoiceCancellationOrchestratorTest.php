<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\InventorySaleStatus;
use App\Enums\InventorySerialStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\AuditLog;
use App\Models\EInvoiceRecord;
use App\Models\InventoryBranch;
use App\Models\InventoryMovement;
use App\Models\InventoryProduct;
use App\Models\StatutoryInvoice;
use App\Models\StatutoryInvoiceCancellation;
use App\Models\User;
use App\Services\Inventory\InventoryStockService;
use App\Services\Inventory\PosSaleService;
use App\Services\StatutoryInvoice\Data\EInvoiceCancelResult;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationOrchestrator;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksEInvoiceGateway;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeEInvoiceGateway;
use Tests\TestCase;

class StatutoryInvoiceCancellationOrchestratorTest extends TestCase
{
    use RefreshDatabase;

    private PosSaleService $sales;

    private InventoryStockService $stock;

    private StatutoryInvoiceService $invoices;

    private User $admin;

    private User $agent;

    private InventoryBranch $branch;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        $this->seed(RolePermissionSeeder::class);
        $this->seed(FinanceMasterDataSeeder::class);

        $this->sales = app(PosSaleService::class);
        $this->stock = app(InventoryStockService::class);
        $this->invoices = app(StatutoryInvoiceService::class);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->agent = User::factory()->create(['is_active' => true]);
        $this->agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->branch = InventoryBranch::query()->create([
            'code' => 'DELHI-RETAIL',
            'name' => 'Delhi Retail',
            'is_active' => true,
        ]);

        $this->configureLocationSellerIdentity();
        config([
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.series_code' => '',
            'statutory_invoices.number_format' => '',
        ]);
    }

    public function test_admin_can_cancel_pos_statutory_invoice_through_orchestrator(): void
    {
        $invoice = $this->issuePosInvoice('CANCEL-TEST-1');

        $result = $this->orchestrator()->cancel(
            invoice: $invoice,
            actor: $this->admin,
            reason: 'Controlled cancellation regression test',
            idempotencyKey: $this->idempotencyKey($invoice),
        );

        $this->assertFalse($result->idempotent);
        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $result->invoice->status);
        $this->assertSame('INV-07671', $result->invoice->invoice_number);
        $this->assertSame('not_required_current_release', $result->creditNoteAction['status']);
    }

    public function test_unauthorized_user_cannot_cancel_via_http(): void
    {
        $invoice = $this->issuePosInvoice('CANCEL-TEST-2');

        $this->actingAs($this->agent)
            ->post(route('finance.invoices.cancel', $invoice), [
                'reason' => 'Should be rejected',
                'confirm' => '1',
            ])
            ->assertForbidden();
    }

    public function test_cancellation_reason_is_required(): void
    {
        $invoice = $this->issuePosInvoice('CANCEL-TEST-3');

        $this->expectException(ValidationException::class);
        $this->orchestrator()->cancel(
            invoice: $invoice,
            actor: $this->admin,
            reason: '  ',
            idempotencyKey: $this->idempotencyKey($invoice),
        );
    }

    public function test_already_cancelled_invoice_is_idempotent(): void
    {
        $invoice = $this->issuePosInvoice('CANCEL-TEST-4');
        $key = $this->idempotencyKey($invoice);

        $first = $this->orchestrator()->cancel($invoice, $this->admin, 'First cancel', $key);
        $second = $this->orchestrator()->cancel($first->invoice->fresh(), $this->admin, 'First cancel', $key);

        $this->assertFalse($first->idempotent);
        $this->assertTrue($second->idempotent);
        $this->assertSame(1, StatutoryInvoiceCancellation::query()->count());
    }

    public function test_invoice_number_is_retained_and_original_record_preserved(): void
    {
        $invoice = $this->issuePosInvoice('CANCEL-TEST-5');
        $invoiceId = $invoice->id;

        $result = $this->orchestrator()->cancel(
            invoice: $invoice,
            actor: $this->admin,
            reason: 'Retain number regression',
            idempotencyKey: $this->idempotencyKey($invoice),
        );

        $this->assertSame($invoiceId, $result->invoice->id);
        $this->assertSame('INV-07671', $result->invoice->invoice_number);
        $this->assertSame('Retain number regression', $result->invoice->cancel_reason);
        $this->assertNotNull($result->invoice->cancelled_at);
        $this->assertSame($this->admin->id, $result->invoice->cancelled_by);
    }

    public function test_no_irn_invoice_does_not_invoke_gateway_cancel(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issuePosInvoice('CANCEL-TEST-6');

        $this->orchestrator()->cancel(
            invoice: $invoice,
            actor: $this->admin,
            reason: 'No IRN cancel path',
            idempotencyKey: $this->idempotencyKey($invoice),
        );

        $this->assertSame(0, $fake->cancelCount);
    }

    public function test_submitted_irn_invokes_fake_gateway_cancel_once(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issuePosInvoice('CANCEL-TEST-7');
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => 'fake',
                'irn' => str_repeat('a', 64),
                'ack_no' => 'ACK-1',
                'status' => EInvoiceRecordStatus::Submitted->value,
            ],
        );

        $result = $this->orchestrator()->cancel(
            invoice: $invoice->fresh(['eInvoiceRecord']),
            actor: $this->admin,
            reason: 'Cancel with IRN',
            idempotencyKey: $this->idempotencyKey($invoice),
        );

        $this->assertSame(1, $fake->cancelCount);
        $this->assertSame('success', $result->irnAction['status']);
        $this->assertTrue((bool) data_get($invoice->fresh('eInvoiceRecord')->eInvoiceRecord?->response_payload, 'irn_cancelled'));
    }

    public function test_irn_provider_failure_does_not_complete_local_cancellation(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::permanentFailure('fake', ['reason' => 'simulated_failure']));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issuePosInvoice('CANCEL-TEST-8');
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => 'fake',
                'irn' => str_repeat('b', 64),
                'ack_no' => 'ACK-2',
                'status' => EInvoiceRecordStatus::Submitted->value,
            ],
        );

        try {
            $this->orchestrator()->cancel(
                invoice: $invoice->fresh(['eInvoiceRecord']),
                actor: $this->admin,
                reason: 'Should fail on IRN',
                idempotencyKey: $this->idempotencyKey($invoice),
            );
            $this->fail('Expected IRN cancellation failure.');
        } catch (ValidationException $exception) {
            $this->assertArrayHasKey('irn', $exception->errors());
        }

        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);
        $this->assertSame(0, StatutoryInvoiceCancellation::query()->count());
    }

    public function test_serial_inventory_is_restored_exactly_once_for_linked_pos_sale(): void
    {
        $invoice = $this->issuePosInvoice('CANCEL-TEST-9', serial: 'CANCEL-SERIAL-001');
        $sale = $invoice->inventorySale()->firstOrFail();

        $this->orchestrator()->cancel(
            invoice: $invoice,
            actor: $this->admin,
            reason: 'Restore serial once',
            idempotencyKey: $this->idempotencyKey($invoice),
        );

        $serial = $sale->fresh(['serials.serial'])->serials->first()?->serial;
        $this->assertNotNull($serial);
        $this->assertSame(InventorySerialStatus::Available, $serial->status);
        $this->assertSame(InventorySaleStatus::Cancelled, $sale->fresh()->status);
        $this->assertSame(1, InventoryMovement::query()->where('sale_id', $sale->id)->where('type', 'sale_cancel')->count());
    }

    public function test_repeat_cancellation_does_not_duplicate_inventory_or_irn_actions(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issuePosInvoice('CANCEL-TEST-10', serial: 'CANCEL-SERIAL-002');
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => 'fake',
                'irn' => str_repeat('c', 64),
                'ack_no' => 'ACK-3',
                'status' => EInvoiceRecordStatus::Submitted->value,
            ],
        );

        $key = $this->idempotencyKey($invoice);
        $orchestrator = $this->orchestrator();
        $orchestrator->cancel($invoice->fresh(['eInvoiceRecord', 'inventorySale']), $this->admin, 'Once only', $key);
        $orchestrator->cancel($invoice->fresh(['eInvoiceRecord', 'inventorySale']), $this->admin, 'Once only', $key);

        $this->assertSame(1, $fake->cancelCount);
        $sale = $invoice->inventorySale()->firstOrFail();
        $this->assertSame(1, InventoryMovement::query()->where('sale_id', $sale->id)->where('type', 'sale_cancel')->count());
    }

    public function test_audit_log_records_complete_cancellation_event(): void
    {
        $invoice = $this->issuePosInvoice('CANCEL-TEST-11');

        $this->orchestrator()->cancel(
            invoice: $invoice,
            actor: $this->admin,
            reason: 'Audit trail regression',
            idempotencyKey: $this->idempotencyKey($invoice),
        );

        $log = AuditLog::query()
            ->where('event', StatutoryInvoiceCancellationOrchestrator::EVENT_CANCELLED)
            ->where('auditable_id', $invoice->id)
            ->first();

        $this->assertNotNull($log);
        $this->assertSame($this->admin->id, $log->user_id);
        $this->assertSame('issued', $log->old_values['status'] ?? null);
        $this->assertSame('cancelled', $log->new_values['status'] ?? null);
    }

    public function test_ui_exposes_cancel_action_for_authorized_user_only(): void
    {
        $invoice = $this->issuePosInvoice('CANCEL-TEST-12');

        $this->actingAs($this->admin)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertSee('Cancel invoice', false)
            ->assertSee('cancelInvoiceModal', false);

        $viewer = User::factory()->create(['is_active' => true]);
        $viewer->givePermissionTo([
            RolePermissionSeeder::PERMISSION_FINANCE_VIEW,
            RolePermissionSeeder::PERMISSION_FINANCE_INVOICES_VIEW,
        ]);

        $this->actingAs($viewer)
            ->get(route('finance.invoices.show', $invoice))
            ->assertOk()
            ->assertDontSee('Cancel invoice', false);

        $this->actingAs($this->agent)
            ->get(route('finance.invoices.show', $invoice))
            ->assertForbidden();
    }

    public function test_http_cancel_requires_confirmation_and_reason(): void
    {
        $invoice = $this->issuePosInvoice('CANCEL-TEST-13');

        $this->actingAs($this->admin)
            ->from(route('finance.invoices.show', $invoice))
            ->post(route('finance.invoices.cancel', $invoice), [])
            ->assertRedirect(route('finance.invoices.show', $invoice))
            ->assertSessionHasErrors(['reason', 'confirm']);

        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);
    }

    public function test_whitebooks_provider_blocks_cancellation_when_irn_is_submitted(): void
    {
        $this->app->instance(EInvoiceGateway::class, app(WhitebooksEInvoiceGateway::class));

        $invoice = $this->issuePosInvoice('CANCEL-TEST-14');
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => 'whitebooks',
                'irn' => str_repeat('d', 64),
                'ack_no' => 'ACK-4',
                'status' => EInvoiceRecordStatus::Submitted->value,
            ],
        );

        $this->expectException(ValidationException::class);
        $this->orchestrator()->cancel(
            invoice: $invoice->fresh(['eInvoiceRecord']),
            actor: $this->admin,
            reason: 'WhiteBooks IRN block',
            idempotencyKey: $this->idempotencyKey($invoice),
        );
    }

    private function orchestrator(): StatutoryInvoiceCancellationOrchestrator
    {
        return app(StatutoryInvoiceCancellationOrchestrator::class);
    }

    private function issuePosInvoice(string $marker, ?string $serial = 'HUB-SERIAL-1'): StatutoryInvoice
    {
        $product = InventoryProduct::query()->create([
            'sku' => 'MFS110-'.$marker,
            'name' => 'Mantra MFS110 '.$marker,
            'hsn_code' => '84716050',
            'gst_percentage' => 18,
            'unit_price' => 100,
            'is_serialized' => true,
            'is_active' => true,
        ]);
        $this->stock->stockInSerialized($product, $this->branch, [$serial], $this->admin);

        $sale = $this->sales->completeSale(
            branch: $this->branch,
            customer: ['name' => 'Cancellation Test Customer', 'phone' => '9000001234'],
            lines: [[
                'product_id' => $product->id,
                'qty' => 1,
                'serials' => [$serial],
            ]],
            paymentMethod: 'Cash',
            actor: $this->admin,
            statutory: [
                'place_of_supply_state' => 'Delhi',
            ],
        );

        return $this->invoices->issueFromPosSale($sale, $this->admin);
    }

    private function idempotencyKey(StatutoryInvoice $invoice): string
    {
        return StatutoryInvoiceCancellationOrchestrator::DEFAULT_IDEMPOTENCY_PREFIX.$invoice->id;
    }
}
