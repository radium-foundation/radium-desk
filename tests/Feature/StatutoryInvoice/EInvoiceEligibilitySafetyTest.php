<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\OutboxEventStatus;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\EInvoiceRecord;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\Support\FakeEInvoiceGateway;
use Tests\Support\FakeEInvoicePayloadMapper;
use Tests\TestCase;

class EInvoiceEligibilitySafetyTest extends TestCase
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

    public function test_cancelled_before_queue_creates_no_outbox_and_does_not_generate(): void
    {
        $invoice = $this->makeTaxInvoice([
            'status' => StatutoryInvoiceStatus::Cancelled,
        ]);
        $fake = $this->bindLiveFake();

        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);

        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(
            'invoice_cancelled',
            EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('response_payload')['skip_reason'] ?? null,
        );
        Http::assertNothingSent();
    }

    public function test_cancelled_after_queue_skips_without_generate(): void
    {
        $invoice = $this->makeHardwareTaxInvoice();
        $event = $this->queue($invoice);
        $invoice->update([
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => 'test cancel after queue',
        ]);
        $fake = $this->bindLiveFake();

        app(OutboxProcessorService::class)->process(1);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(0, $fake->fetchCount);
        $this->assertSame(EInvoiceRecordStatus::Skipped->value, $record?->status);
        $this->assertSame('invoice_cancelled', $record?->response_payload['skip_reason'] ?? null);
        $this->assertSame(OutboxEventStatus::Completed, $event->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_cancelled_while_processing_recovers_and_does_not_generate(): void
    {
        $invoice = $this->makeHardwareTaxInvoice();
        $event = $this->queue($invoice);
        EInvoiceRecord::query()->where('invoice_id', $invoice->id)->update([
            'status' => EInvoiceRecordStatus::Processing->value,
        ]);
        $invoice->update([
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => 'test cancel while processing',
        ]);
        $recoveredIrn = 'f1a2b3c4d5e6f1a2b3c4d5e6f1a2b3c4d5e6f1a2b3c4d5e6f1a2b3c4d5e6f1a2';
        $fake = $this->bindLiveFake();
        $fake->withFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: $recoveredIrn,
            ackNo: 'ACK-CANCEL-RECOVER',
            ackDate: '2026-09-10 16:00:00',
            signedQr: 'cancelled-processing-qr',
            signedInvoice: 'eyJhbGciOiJ0ZXN0In0.eyJ0ZXN0IjoiY2FuY2VsbGVkIn0.sig',
        ));

        app(EInvoiceProcessor::class)->process($event);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(1, $fake->fetchCount);
        $this->assertSame($recoveredIrn, $record?->irn);
        $this->assertSame('ACK-CANCEL-RECOVER', $record?->ack_no);
        $this->assertSame(StatutoryInvoiceStatus::Cancelled, $invoice->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_issued_irn_survives_later_cancellation_without_generate(): void
    {
        $invoice = $this->makeHardwareTaxInvoice();
        $event = $this->queue($invoice);
        EInvoiceRecord::query()->where('invoice_id', $invoice->id)->update([
            'irn' => 'already-issued-irn',
            'ack_no' => 'ACK-KEEP',
            'ack_date' => '2026-09-10 09:00:00',
            'signed_qr' => 'qr-keep',
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);
        $invoice->update([
            'status' => StatutoryInvoiceStatus::Cancelled,
            'cancelled_at' => now(),
            'cancel_reason' => 'test cancel after IRN',
        ]);
        $fake = $this->bindLiveFake();

        app(EInvoiceProcessor::class)->process($event);
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice->fresh());

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(0, $fake->fetchCount);
        $this->assertSame('already-issued-irn', $record?->irn);
        $this->assertSame('ACK-KEEP', $record?->ack_no);
        $this->assertSame('qr-keep', $record?->signed_qr);
        $this->assertSame(EInvoiceRecordStatus::Submitted->value, $record?->status);
        Http::assertNothingSent();
    }

    public function test_credit_note_is_not_queued_and_does_not_generate(): void
    {
        $invoice = $this->makeTaxInvoice([
            'document_type' => StatutoryInvoiceDocumentType::CreditNote,
        ]);
        $fake = $this->bindLiveFake();

        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);

        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(
            'unsupported_document_type',
            EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('response_payload')['skip_reason'] ?? null,
        );
        Http::assertNothingSent();
    }

    public function test_debit_note_is_not_queued_and_does_not_generate(): void
    {
        $invoice = $this->makeTaxInvoice([
            'document_type' => StatutoryInvoiceDocumentType::DebitNote,
        ]);
        $fake = $this->bindLiveFake();

        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);

        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        Http::assertNothingSent();
    }

    public function test_historical_invoice_is_not_queued(): void
    {
        $invoice = $this->makeTaxInvoice([
            'issued_at' => '2026-08-31 10:00:00',
        ]);
        $fake = $this->bindLiveFake();

        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);

        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(
            'outside_invoice_scope',
            EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('response_payload')['skip_reason'] ?? null,
        );
        Http::assertNothingSent();
    }

    public function test_issued_tax_invoice_still_generates_once(): void
    {
        $invoice = $this->makeHardwareTaxInvoice();
        $event = $this->queue($invoice);
        $fake = $this->bindLiveFake();

        app(EInvoiceProcessor::class)->process($event);

        $this->assertSame(1, $fake->submitCount);
        $this->assertTrue(EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first()?->hasIssuedIrn());
        Http::assertNothingSent();
    }

    private function queue(StatutoryInvoice $invoice): OutboxEvent
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
        $this->app->forgetInstance(OutboxProcessorService::class);
        config([
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'fake',
        ]);

        return $fake;
    }
}
