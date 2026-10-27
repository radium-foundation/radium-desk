<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\EInvoiceSubmitOutcome;
use App\Enums\StatutoryInvoiceCancellationOperationStatus;
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
use App\Services\StatutoryInvoice\Data\IrnCancellationReconciliationResult;
use App\Services\StatutoryInvoice\StatutoryInvoiceCancellationOrchestrator;
use App\Services\StatutoryInvoice\StatutoryInvoiceIrnCancellationReconciliationService;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Database\Seeders\FinanceMasterDataSeeder;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;
use Illuminate\Validation\ValidationException;
use Tests\Support\FakeEInvoiceGateway;
use Tests\TestCase;

class StatutoryInvoiceCancellationReliabilityTest extends TestCase
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
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.post_finance_journals' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.einvoice.irn_cancellation_window_hours' => 24,
        ]);

        $this->admin = User::factory()->create(['is_active' => true]);
        $this->admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);
    }

    public function test_provider_success_then_local_finalize_completes_operation(): void
    {
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-1');
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);

        $result = $this->orchestrator()->cancel(
            $invoice->fresh(['eInvoiceRecord', 'items']),
            $this->admin,
            'Within window',
            $this->cancelKey($invoice),
        );

        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $result->invoice->status);
        $this->assertSame(1, $fake->cancelCount);

        $operation = StatutoryInvoiceCancellation::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::Completed, $operation->status);
        $this->assertNotNull($operation->correlation_id);
        $this->assertNotNull($operation->completed_at);
    }

    public function test_provider_success_then_local_failure_leaves_recoverable_state(): void
    {
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-2');
        $this->attachSubmittedIrn($invoice, hoursAgo: 1);

        $this->mock(StatutoryInvoiceService::class, function ($mock): void {
            $mock->shouldReceive('cancel')->once()->andThrow(new \RuntimeException('simulated local failure'));
        });

        Log::spy();

        try {
            $this->orchestrator()->cancel(
                $invoice->fresh(['eInvoiceRecord', 'items']),
                $this->admin,
                'Fail local',
                $this->cancelKey($invoice),
            );
            $this->fail('Expected exception');
        } catch (\RuntimeException) {
            // expected
        }

        $operation = StatutoryInvoiceCancellation::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired, $operation->status);
        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);
        Log::shouldHaveReceived('warning')->with('statutory_invoice_cancellation.manual_review_required', \Mockery::any());
    }

    public function test_resume_after_irn_confirmed_completes_without_second_cancel(): void
    {
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-3');
        $this->attachSubmittedIrn($invoice, hoursAgo: 1);

        $operation = StatutoryInvoiceCancellation::query()->create([
            'statutory_invoice_id' => $invoice->id,
            'idempotency_key' => $this->cancelKey($invoice),
            'actor_id' => $this->admin->id,
            'reason' => 'Resume path',
            'status' => StatutoryInvoiceCancellationOperationStatus::IrnConfirmed,
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'irn_confirmed_at' => now(),
            'irn_action' => ['status' => 'success'],
            'attempt_count' => 1,
        ]);

        $result = $this->orchestrator()->reconcileInFlightCancellation($operation);

        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $result->invoice->status);
        $this->assertSame(0, $fake->cancelCount);
        $operation->refresh();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::Completed, $operation->status);
    }

    public function test_unknown_cancel_outcome_does_not_blindly_repeat_cancel(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::unknown('fake', ['reason' => 'timeout']));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-4');
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Unknown', $this->cancelKey($invoice));
            $this->fail('Expected validation');
        } catch (ValidationException) {
        }

        $this->assertSame(1, $fake->cancelCount);
        $operation = StatutoryInvoiceCancellation::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::IrnReconciliationPending, $operation->status);

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Unknown', $this->cancelKey($invoice));
            $this->fail('Expected validation');
        } catch (ValidationException) {
        }

        $this->assertSame(1, $fake->cancelCount);
        $operation->refresh();
        $this->assertContains($operation->status, [
            StatutoryInvoiceCancellationOperationStatus::IrnReconciliationPending,
            StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired,
        ]);
    }

    public function test_reconciliation_confirms_cancelled_irn_and_finalizes(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::unknown('fake', ['reason' => 'timeout']));
        $fake->queueFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            payload: ['Status' => 'CNL', 'CancelDate' => '2026-10-27'],
        ));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-5');
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);
        $key = $this->cancelKey($invoice);

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Reconcile', $key);
        } catch (ValidationException) {
        }

        $operation = StatutoryInvoiceCancellation::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail();
        $result = $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Reconcile', $key);

        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $result->invoice->status);
        $this->assertSame(1, $fake->cancelCount);
        $operation->refresh();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::Completed, $operation->status);
    }

    public function test_get_irn_2154_not_found_does_not_treat_irn_as_cancelled(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::unknown('fake', ['reason' => 'timeout']));
        $fake->queueFetch(EInvoiceSubmitResult::irnNotFound('fake', [
            'reason' => 'irn_not_found',
            'error_code' => '2154',
        ]));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-5B');
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);
        $key = $this->cancelKey($invoice);

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Reconcile', $key);
        } catch (ValidationException) {
        }

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Reconcile', $key);
            $this->fail('Expected validation after 2154 reconcile');
        } catch (ValidationException) {
        }

        $this->assertSame(1, $fake->cancelCount);
        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);
        $operation = StatutoryInvoiceCancellation::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired, $operation->status);
    }

    public function test_max_irn_cancel_dispatch_attempts_enforces_manual_review(): void
    {
        config(['statutory_invoices.einvoice.irn_cancellation_max_dispatch_attempts' => 2]);

        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::unknown('fake', ['reason' => 'timeout']));
        $fake->queueFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            payload: ['Status' => 'ACT'],
        ));
        $fake->queueCancel(EInvoiceCancelResult::unknown('fake', ['reason' => 'timeout']));
        $fake->queueFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            payload: ['Status' => 'ACT'],
        ));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-MAX');
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);
        $key = $this->cancelKey($invoice);
        $fresh = fn () => $invoice->fresh(['eInvoiceRecord', 'items']);

        try {
            $this->orchestrator()->cancel($fresh(), $this->admin, 'Max', $key);
        } catch (ValidationException) {
        }

        $this->assertSame(1, $fake->cancelCount);

        try {
            $this->orchestrator()->cancel($fresh(), $this->admin, 'Max', $key);
        } catch (ValidationException) {
        }

        $this->assertSame(2, $fake->cancelCount);
        $operation = StatutoryInvoiceCancellation::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(2, $operation->attempt_count);

        $operation->update(['status' => StatutoryInvoiceCancellationOperationStatus::FailedRetryable]);
        $fake->queueFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            payload: ['Status' => 'ACT'],
        ));

        Log::spy();

        try {
            $this->orchestrator()->cancel($fresh(), $this->admin, 'Max', $key);
            $this->fail('Expected validation after dispatch limit');
        } catch (ValidationException) {
        }

        $this->assertSame(2, $fake->cancelCount);
        $operation->refresh();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired, $operation->status);
        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);
        Log::shouldHaveReceived('warning')->with('statutory_invoice_cancellation.manual_review_required', \Mockery::on(
            fn (array $context): bool => ($context['reason'] ?? '') === 'irn_cancel_max_dispatch_attempts_exceeded'
        ));
    }

    public function test_manual_review_from_dispatch_limit_blocks_resume(): void
    {
        config(['statutory_invoices.einvoice.irn_cancellation_max_dispatch_attempts' => 1]);

        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::unknown('fake', ['reason' => 'timeout']));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-MAX-BLOCK');
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);
        $key = $this->cancelKey($invoice);

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Once', $key);
        } catch (ValidationException) {
        }

        $operation = StatutoryInvoiceCancellation::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail();
        $operation->update([
            'status' => StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired,
            'attempt_count' => 1,
        ]);

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Resume', $key);
            $this->fail('Expected manual review block');
        } catch (ValidationException $e) {
            $this->assertStringContainsString('manual review', strtolower($e->getMessage()));
        }

        $this->assertSame(1, $fake->cancelCount);
    }

    public function test_active_irn_after_cancellation_window_expired_requires_manual_review(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::unknown('fake', ['reason' => 'timeout']));
        $fake->queueFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            payload: ['Status' => 'ACT'],
        ));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-WINDOW');
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);
        $key = $this->cancelKey($invoice);

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Window', $key);
        } catch (ValidationException) {
        }

        $invoice->eInvoiceRecord?->update(['ack_date' => Carbon::now()->subHours(30)]);

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Window', $key);
            $this->fail('Expected policy manual review');
        } catch (ValidationException) {
        }

        $this->assertSame(1, $fake->cancelCount);
        $operation = StatutoryInvoiceCancellation::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::ManualReviewRequired, $operation->status);
        $this->assertSame(StatutoryInvoiceStatus::Issued, $invoice->fresh()->status);
    }

    public function test_failed_retryable_reconciles_before_dispatch_and_blocks_when_still_unknown(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::temporaryFailure('fake', ['reason' => 'timeout']));
        $fake->queueFetch(EInvoiceSubmitResult::irnNotFound('fake', ['error_code' => '2154']));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-FRT');
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);
        $key = $this->cancelKey($invoice);

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Temp fail', $key);
        } catch (ValidationException) {
        }

        $this->assertSame(1, $fake->cancelCount);

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Retry', $key);
            $this->fail('Expected ambiguous reconcile block');
        } catch (ValidationException) {
        }

        $this->assertSame(1, $fake->cancelCount);
        $this->assertSame(1, $fake->fetchCount);
        $operation = StatutoryInvoiceCancellation::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::IrnReconciliationPending, $operation->status);
    }

    public function test_failed_retryable_after_active_irn_reconcile_dispatches_with_fresh_reservation(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueCancel(EInvoiceCancelResult::temporaryFailure('fake', ['reason' => 'timeout']));
        $fake->queueFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            payload: ['Status' => 'ACT'],
        ));
        $fake->queueCancel(EInvoiceCancelResult::success('fake', 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2'));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-FRT-2');
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);
        $key = $this->cancelKey($invoice);

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Temp', $key);
        } catch (ValidationException) {
        }

        $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Retry', $key);

        $this->assertSame(2, $fake->cancelCount);
        $this->assertSame(1, $fake->fetchCount);
        $operation = StatutoryInvoiceCancellation::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::Completed, $operation->status);
    }

    public function test_rejected_dispatch_reservation_makes_no_provider_cancel_call(): void
    {
        config(['statutory_invoices.einvoice.irn_cancellation_max_dispatch_attempts' => 2]);

        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-RES-REJECT');
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);

        StatutoryInvoiceCancellation::query()->create([
            'statutory_invoice_id' => $invoice->id,
            'idempotency_key' => $this->cancelKey($invoice),
            'actor_id' => $this->admin->id,
            'reason' => 'At cap',
            'status' => StatutoryInvoiceCancellationOperationStatus::FailedRetryable,
            'correlation_id' => (string) \Illuminate\Support\Str::uuid(),
            'attempt_count' => 2,
        ]);

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'At cap', $this->cancelKey($invoice));
            $this->fail('Expected validation');
        } catch (ValidationException) {
        }

        $this->assertSame(0, $fake->cancelCount);
    }

    public function test_reserved_dispatch_slot_consumed_when_provider_call_aborts(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->beforeCancel = static function (): void {
            throw new \RuntimeException('simulated crash after reservation');
        };
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-RES-CRASH');
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);
        $key = $this->cancelKey($invoice);

        try {
            $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Crash', $key);
            $this->fail('Expected crash');
        } catch (\RuntimeException) {
        }

        $operation = StatutoryInvoiceCancellation::query()->where('statutory_invoice_id', $invoice->id)->firstOrFail();
        $this->assertSame(1, $operation->attempt_count);
        $this->assertSame(StatutoryInvoiceCancellationOperationStatus::IrnPending, $operation->status);
        $this->assertSame(0, $fake->cancelCount);
    }

    public function test_reconciliation_service_treats_2154_as_unknown_not_cancelled(): void
    {
        $fake = new FakeEInvoiceGateway(EInvoiceSubmitResult::skipped('fake'));
        $fake->queueFetch(EInvoiceSubmitResult::irnNotFound('fake', ['error_code' => '2154']));
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-5C');
        $this->attachSubmittedIrn($invoice, hoursAgo: 1);

        $result = app(StatutoryInvoiceIrnCancellationReconciliationService::class)->reconcile($invoice->fresh(['eInvoiceRecord']));

        $this->assertSame(IrnCancellationReconciliationResult::STATE_UNKNOWN, $result->state);
        $this->assertSame(1, $fake->fetchCount);
        $this->assertSame(0, $fake->cancelCount);
    }

    public function test_duplicate_idempotency_key_on_completed_operation(): void
    {
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);

        $invoice = $this->issueB2bInvoice('REL-6');
        $this->attachSubmittedIrn($invoice, hoursAgo: 2);
        $key = $this->cancelKey($invoice);

        $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Once', $key);
        $again = $this->orchestrator()->cancel($invoice->fresh(['eInvoiceRecord', 'items']), $this->admin, 'Once', $key);

        $this->assertTrue($again->idempotent);
        $this->assertSame(1, StatutoryInvoiceCancellation::query()->count());
        $this->assertSame(1, $fake->cancelCount);
    }

    public function test_historical_completed_row_without_status_enum_still_treated_as_completed(): void
    {
        $invoice = $this->issueB2bInvoice('REL-7');

        StatutoryInvoiceCancellation::query()->create([
            'statutory_invoice_id' => $invoice->id,
            'idempotency_key' => $this->cancelKey($invoice),
            'actor_id' => $this->admin->id,
            'reason' => 'Legacy',
            'status' => StatutoryInvoiceCancellationOperationStatus::Completed,
            'correlation_id' => null,
            'irn_action' => ['status' => 'not_required'],
            'inventory_action' => ['status' => 'not_applicable'],
            'credit_note_action' => ['status' => 'not_required'],
            'result_summary' => ['workflow' => 'b2c_cancel_invoice'],
            'completed_at' => now()->subDay(),
        ]);

        $invoice->update(['status' => StatutoryInvoiceStatus::Cancelled]);

        $result = $this->orchestrator()->cancel(
            $invoice->fresh(),
            $this->admin,
            'Legacy',
            $this->cancelKey($invoice),
        );

        $this->assertTrue($result->idempotent);
    }

    private function orchestrator(): StatutoryInvoiceCancellationOrchestrator
    {
        return app(StatutoryInvoiceCancellationOrchestrator::class);
    }

    private function cancelKey(StatutoryInvoice $invoice): string
    {
        return StatutoryInvoiceCancellationOrchestrator::DEFAULT_IDEMPOTENCY_PREFIX.$invoice->id;
    }

    private function issueB2bInvoice(string $suffix): StatutoryInvoice
    {
        $invoice = StatutoryInvoice::query()->create([
            'invoice_number' => 'INV-TEST-'.$suffix,
            'document_type' => StatutoryInvoiceDocumentType::TaxInvoice,
            'status' => StatutoryInvoiceStatus::Issued,
            'channel' => StatutoryInvoiceChannel::DeskPos,
            'source_type' => StatutoryInvoiceSourceType::InventorySale,
            'source_id' => 'SALE-'.$suffix,
            'idempotency_key' => 'test:'.$suffix,
            'buyer_gstin' => '07AABCU9603R1ZM',
            'buyer_name' => 'B2B Buyer',
            'place_of_supply_state' => 'Delhi',
            'taxable_value' => 100,
            'invoice_value' => 118,
            'tax_total' => 18,
            'issued_at' => now(),
        ]);

        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $invoice->id,
            'line_no' => 1,
            'description' => 'Item',
            'hsn_sac' => '8471',
            'qty' => 1,
            'unit_price' => 100,
            'taxable_value' => 100,
            'tax_total' => 18,
            'line_total' => 118,
            'gst_percentage' => 18,
        ]);

        return $invoice;
    }

    private function attachSubmittedIrn(StatutoryInvoice $invoice, int $hoursAgo): void
    {
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'fake',
            'irn' => 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2',
            'ack_no' => 'ACK-1',
            'ack_date' => Carbon::now()->subHours($hoursAgo),
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);
    }
}
