<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\EInvoiceSubmitOutcome;
use App\Models\EInvoiceRecord;
use App\Models\OutboxEvent;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
use App\Services\StatutoryInvoice\EInvoiceRecoveryRequiredException;
use App\Services\StatutoryInvoice\EInvoiceTemporaryFailureException;
use App\Services\StatutoryInvoice\NullEInvoiceGateway;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\Support\FakeEInvoiceGateway;
use Tests\Support\FakeEInvoicePayloadMapper;
use Tests\TestCase;

class EInvoiceFoundationTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Http::fake();
        Storage::fake('local');
        config([
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);
    }

    public function test_production_binding_and_flags_remain_off(): void
    {
        $this->assertFalse((bool) config('statutory_invoices.worker_may_mint'));
        $this->assertFalse((bool) config('statutory_invoices.auto_issue_on_pos_complete'));
        $this->assertSame('none', config('statutory_invoices.einvoice.provider'));
        $this->assertInstanceOf(NullEInvoiceGateway::class, app(EInvoiceGateway::class));
        Http::assertNothingSent();
    }

    public function test_queue_does_not_null_an_existing_irn(): void
    {
        $invoice = $this->makeTaxInvoice();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);

        EInvoiceRecord::query()->where('invoice_id', $invoice->id)->update([
            'irn' => 'issued-irn-keep-me',
            'ack_no' => 'ACK-KEEP',
            'ack_date' => '2026-09-10 09:00:00',
            'signed_qr' => 'signed-qr-keep',
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);

        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice->fresh());
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();

        $this->assertSame('issued-irn-keep-me', $record?->irn);
        $this->assertSame('ACK-KEEP', $record?->ack_no);
        $this->assertSame('signed-qr-keep', $record?->signed_qr);
        $this->assertSame(EInvoiceRecordStatus::Submitted->value, $record?->status);
        Http::assertNothingSent();
    }

    public function test_b2c_queue_skips_without_writing_irn(): void
    {
        $invoice = $this->makeTaxInvoice(['buyer_gstin' => null]);
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();

        $this->assertSame(EInvoiceRecordStatus::Skipped->value, $record?->status);
        $this->assertSame('b2c_not_eligible', $record?->response_payload['skip_reason'] ?? null);
        $this->assertNull($record?->irn);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
    }

    public function test_processor_does_not_null_or_resubmit_existing_irn(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        EInvoiceRecord::query()->where('invoice_id', $invoice->id)->update([
            'irn' => 'already-issued-irn',
            'ack_no' => 'ACK-ORIG',
            'signed_qr' => 'qr-orig',
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);

        $fake = $this->bindLiveFake();
        app(EInvoiceProcessor::class)->process($event);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(0, $fake->submitCount);
        $this->assertSame('already-issued-irn', $record?->irn);
        $this->assertSame('ACK-ORIG', $record?->ack_no);
        $this->assertSame('qr-orig', $record?->signed_qr);
        Http::assertNothingSent();
    }

    public function test_duplicate_and_concurrent_process_submit_once(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $fake = $this->bindLiveFake();
        $processor = app(EInvoiceProcessor::class);

        $processor->process($event);
        $processor->process($event);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(1, $fake->submitCount);
        $this->assertTrue($record?->hasIssuedIrn());
        $this->assertSame(EInvoiceRecordStatus::Submitted->value, $record?->status);
        $this->assertSame('ACK-1001', $record?->ack_no);
        $this->assertSame('signed-qr-token', $record?->signed_qr);
        $this->assertTrue($record?->hasPersistedSignedInvoice());
        $this->assertSame('corr-1', $record?->response_payload['correlation_id'] ?? null);
        Http::assertNothingSent();
    }

    public function test_provider_timeout_is_ambiguous_and_not_retried(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $fake = $this->bindLiveFake(EInvoiceSubmitResult::ambiguous('fake', ['timeout' => true], 'corr-timeout'));
        $processor = app(EInvoiceProcessor::class);

        $this->processExpectingRecovery($processor, $event);
        $this->processExpectingRecovery($processor, $event);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(1, $fake->fetchCount);
        $this->assertSame(EInvoiceRecordStatus::Ambiguous->value, $record?->status);
        $this->assertNull($record?->irn);
        $this->assertSame(EInvoiceSubmitOutcome::Ambiguous->value, $record?->response_payload['outcome'] ?? null);
        Http::assertNothingSent();
    }

    public function test_ambiguous_recovery_persists_existing_irn_without_generate(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $recoveredIrn = 'b1c2d3e4f5a6b1c2d3e4f5a6b1c2d3e4f5a6b1c2d3e4f5a6b1c2d3e4f5a6b1c2';
        $fake = $this->bindLiveFake(EInvoiceSubmitResult::ambiguous('fake', ['timeout' => true], 'corr-timeout'));
        $fake->withFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: $recoveredIrn,
            ackNo: 'ACK-RECOVER',
            ackDate: '2026-09-10 12:00:00',
            signedQr: 'recovered-qr',
        ));
        $processor = app(EInvoiceProcessor::class);

        $this->processExpectingRecovery($processor, $event);
        $processor->process($event);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(1, $fake->fetchCount);
        $this->assertSame($recoveredIrn, $record?->irn);
        $this->assertSame('ACK-RECOVER', $record?->ack_no);
        $this->assertSame('recovered-qr', $record?->signed_qr);
        $this->assertSame(EInvoiceRecordStatus::Submitted->value, $record?->status);
        Http::assertNothingSent();
    }

    public function test_get_irn_provider_failure_does_not_generate_again(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $fake = $this->bindLiveFake(EInvoiceSubmitResult::ambiguous('fake', ['timeout' => true]));
        $fake->withFetch(EInvoiceSubmitResult::temporaryFailure('fake', ['reason' => 'get_irn_provider_5xx']));
        $processor = app(EInvoiceProcessor::class);

        $this->processExpectingRecovery($processor, $event);
        $this->processExpectingRecovery($processor, $event);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(1, $fake->fetchCount);
        $this->assertSame(EInvoiceRecordStatus::Ambiguous->value, $record?->status);
        $this->assertNull($record?->irn);

        Http::assertNothingSent();
    }

    public function test_temporary_failure_is_retryable_without_irn(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $fake = $this->bindLiveFake(EInvoiceSubmitResult::temporaryFailure('fake', ['busy' => true]));

        try {
            app(EInvoiceProcessor::class)->process($event);
            $this->fail('Expected a retryable temporary failure.');
        } catch (EInvoiceTemporaryFailureException) {
            $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
            $this->assertSame(1, $fake->submitCount);
            $this->assertSame(EInvoiceRecordStatus::TemporaryFailure->value, $record?->status);
            $this->assertNull($record?->irn);
        }

        Http::assertNothingSent();
    }

    public function test_permanent_provider_error_does_not_submit_again(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $fake = $this->bindLiveFake(EInvoiceSubmitResult::permanentFailure('fake', ['code' => 'INVALID']));
        $processor = app(EInvoiceProcessor::class);

        $processor->process($event);
        $processor->process($event);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(EInvoiceRecordStatus::PermanentFailure->value, $record?->status);
        $this->assertNull($record?->irn);
        Http::assertNothingSent();
    }

    public function test_flags_off_never_calls_gateway(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $fake = FakeEInvoiceGateway::succeeding();
        $this->app->instance(EInvoiceGateway::class, $fake);
        $this->app->instance(EInvoiceIrnPayloadMapper::class, new FakeEInvoicePayloadMapper);
        $this->app->forgetInstance(EInvoiceProcessor::class);
        config([
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);

        app(EInvoiceProcessor::class)->process($event);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(0, $fake->submitCount);
        $this->assertSame('worker_may_mint_off', $record?->response_payload['skip_reason'] ?? null);
        $this->assertNull($record?->irn);
        Http::assertNothingSent();
    }

    public function test_result_model_carries_ack_and_signed_qr_without_fabrication(): void
    {
        $result = EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: 'irn-token',
            ackNo: 'ACK-9',
            ackDate: '2026-09-10 11:00:00',
            signedQr: 'qr-token',
            correlationId: 'corr-9',
        );

        $this->assertSame(EInvoiceSubmitOutcome::Success, $result->outcome);
        $this->assertSame('irn-token', $result->irn);
        $this->assertSame('ACK-9', $result->ackNo);
        $this->assertSame('2026-09-10 11:00:00', $result->ackDate);
        $this->assertSame('qr-token', $result->signedQr);
        $this->assertSame('corr-9', $result->correlationId);
    }

    private function processExpectingRecovery(EInvoiceProcessor $processor, OutboxEvent $event): void
    {
        try {
            $processor->process($event);
            $this->fail('Expected e-invoice recovery to remain required.');
        } catch (EInvoiceRecoveryRequiredException) {
        }
    }

    private function queue($invoice): OutboxEvent
    {
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);

        return OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->firstOrFail();
    }

    private function bindLiveFake(?EInvoiceSubmitResult $result = null): FakeEInvoiceGateway
    {
        $fake = $result === null
            ? FakeEInvoiceGateway::succeeding()
            : (new FakeEInvoiceGateway($result));
        $this->app->instance(EInvoiceGateway::class, $fake);
        $this->app->instance(EInvoiceIrnPayloadMapper::class, new FakeEInvoicePayloadMapper);
        $this->app->forgetInstance(EInvoiceProcessor::class);
        config([
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'fake',
        ]);

        return $fake;
    }
}
