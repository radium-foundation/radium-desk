<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\OutboxEventStatus;
use App\Models\EInvoiceRecord;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoice;
use App\Services\Outbox\OutboxProcessorService;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
use App\Services\StatutoryInvoice\EInvoiceRecoveryRequiredException;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use RuntimeException;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\Support\FakeEInvoiceGateway;
use Tests\Support\FakeEInvoicePayloadMapper;
use Tests\TestCase;

class EInvoiceProcessorRecoveryBoundaryTest extends TestCase
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

    public function test_successful_generate_completes_outbox_once(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $fake = $this->bindLiveFake();

        app(OutboxProcessorService::class)->process(1);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $event->refresh();
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(0, $fake->fetchCount);
        $this->assertTrue($record?->hasIssuedIrn());
        $this->assertSame('ACK-1001', $record?->ack_no);
        $this->assertSame('signed-qr-token', $record?->signed_qr);
        $this->assertTrue($record?->hasPersistedSignedInvoice());
        $this->assertSame(OutboxEventStatus::Completed, $event->status);
        Http::assertNothingSent();
    }

    public function test_generate_timeout_stays_recoverable_and_does_not_complete_outbox(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $fake = $this->bindLiveFake(EInvoiceSubmitResult::ambiguous('fake', ['timeout' => true]));

        app(OutboxProcessorService::class)->process(1);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $event->refresh();
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(0, $fake->fetchCount);
        $this->assertSame(EInvoiceRecordStatus::Ambiguous->value, $record?->status);
        $this->assertSame(OutboxEventStatus::Pending, $event->status);
        $this->assertNotSame(OutboxEventStatus::Completed, $event->status);
        $this->assertNotSame(OutboxEventStatus::Failed, $event->status);

        $event->update(['available_at' => now()]);
        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(1, $fake->fetchCount);
        $this->assertSame(OutboxEventStatus::Pending, $event->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_generate_5xx_is_ambiguous_and_does_not_retry_generate(): void
    {
        $this->assertAmbiguousGenerateDoesNotRetry(
            EInvoiceSubmitResult::ambiguous('fake', ['reason' => 'generate_provider_5xx', 'http_status' => 503]),
        );
    }

    public function test_generate_429_is_ambiguous_and_does_not_retry_generate(): void
    {
        $this->assertAmbiguousGenerateDoesNotRetry(
            EInvoiceSubmitResult::ambiguous('fake', ['reason' => 'generate_rate_limited', 'http_status' => 429]),
        );
    }

    public function test_crash_after_generate_recovers_without_second_generate(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $recoveredIrn = 'c1d2e3f4a5b6c1d2e3f4a5b6c1d2e3f4a5b6c1d2e3f4a5b6c1d2e3f4a5b6c1d2';
        $fake = $this->bindLiveFake()->crashOnSubmit();
        $fake->withFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: $recoveredIrn,
            ackNo: 'ACK-CRASH',
            ackDate: '2026-09-10 13:00:00',
            signedQr: 'recovered-qr-after-crash',
            signedInvoice: 'eyJhbGciOiJ0ZXN0In0.eyJ0ZXN0IjoicmVjb3ZlcmVkIn0.sig',
        ));
        $processor = app(EInvoiceProcessor::class);

        $this->processExpectingRecovery($processor, $event);
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(0, $fake->fetchCount);
        $this->assertSame(
            EInvoiceRecordStatus::Ambiguous->value,
            EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('status'),
        );

        $fake->crashOnSubmit(false);
        $processor->process($event);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(1, $fake->fetchCount);
        $this->assertSame($recoveredIrn, $record?->irn);
        $this->assertSame('ACK-CRASH', $record?->ack_no);
        $this->assertTrue($record?->hasPersistedSignedInvoice());
        Http::assertNothingSent();
    }

    public function test_persistence_failure_after_successful_generate_uses_recovery(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $fake = $this->bindLiveFake();
        $shouldFailPersist = true;
        EInvoiceRecord::saving(function (EInvoiceRecord $record) use (&$shouldFailPersist): void {
            if ($shouldFailPersist && is_string($record->irn) && trim($record->irn) !== '') {
                throw new RuntimeException('simulated local persistence failure');
            }
        });
        $processor = app(EInvoiceProcessor::class);

        $this->processExpectingRecovery($processor, $event);
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(0, $fake->fetchCount);
        $this->assertNull(EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('irn'));
        $this->assertSame(
            EInvoiceRecordStatus::Ambiguous->value,
            EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('status'),
        );

        $shouldFailPersist = false;
        $fake->withFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: 'd1e2f3a4b5c6d1e2f3a4b5c6d1e2f3a4b5c6d1e2f3a4b5c6d1e2f3a4b5c6d1e2',
            ackNo: 'ACK-PERSIST',
            ackDate: '2026-09-10 14:00:00',
            signedQr: 'recovered-after-persist-fail',
            signedInvoice: 'eyJhbGciOiJ0ZXN0In0.eyJ0ZXN0IjoicGVyc2lzdC1mYWlsIn0.sig',
        ));
        app(EInvoiceProcessor::class)->process($event);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(1, $fake->fetchCount);
        $this->assertTrue($record?->hasIssuedIrn());
        $this->assertSame('ACK-PERSIST', $record?->ack_no);
        Http::assertNothingSent();
    }

    public function test_stale_processing_does_not_return_to_generate(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $fake = $this->bindLiveFake();
        $fake->withFetch(EInvoiceSubmitResult::success(
            provider: 'fake',
            irn: 'e1f2a3b4c5d6e1f2a3b4c5d6e1f2a3b4c5d6e1f2a3b4c5d6e1f2a3b4c5d6e1f2',
            ackNo: 'ACK-STALE',
            ackDate: '2026-09-10 15:00:00',
            signedQr: 'stale-recovered-qr',
            signedInvoice: 'eyJhbGciOiJ0ZXN0In0.eyJ0ZXN0Ijoic3RhbGUifQ.sig',
        ));

        EInvoiceRecord::query()->where('invoice_id', $invoice->id)->update([
            'status' => EInvoiceRecordStatus::Processing->value,
        ]);
        OutboxEvent::query()->whereKey($event->id)->update([
            'status' => OutboxEventStatus::Processing->value,
            'updated_at' => now()->subMinutes(6),
        ]);

        app(OutboxProcessorService::class)->process(1);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(1, $fake->fetchCount);
        $this->assertSame('ACK-STALE', $record?->ack_no);
        $this->assertTrue($record?->hasIssuedIrn());
        $this->assertSame(OutboxEventStatus::Completed, $event->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_concurrent_worker_during_generate_does_not_generate_twice(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $fake = $this->bindLiveFake();
        $processor = app(EInvoiceProcessor::class);
        $nested = false;
        $fake->beforeSubmit(function () use (&$nested, $processor, $event): void {
            if ($nested) {
                return;
            }
            $nested = true;
            try {
                $processor->process($event);
            } catch (EInvoiceRecoveryRequiredException) {
                // Worker B must Get-IRN only; GENERATE is still in flight on A.
            }
        });

        $processor->process($event);

        $this->assertSame(1, $fake->submitCount);
        $this->assertGreaterThanOrEqual(1, $fake->fetchCount);
        $this->assertTrue(EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first()?->hasIssuedIrn());
        Http::assertNothingSent();
    }

    public function test_existing_irn_never_generates(): void
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

        app(OutboxProcessorService::class)->process(1);

        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(0, $fake->fetchCount);
        $this->assertSame('already-issued-irn', EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('irn'));
        $this->assertSame(OutboxEventStatus::Completed, $event->fresh()->status);
        Http::assertNothingSent();
    }

    public function test_recovery_2154_stays_irn_not_found_without_generate(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $fake = $this->bindLiveFake(EInvoiceSubmitResult::ambiguous('fake', ['timeout' => true]));
        $fake->withFetch(EInvoiceSubmitResult::irnNotFound('fake', ['error_code' => '2154']));
        $processor = app(EInvoiceProcessor::class);

        $this->processExpectingRecovery($processor, $event);
        $processor->process($event);
        $processor->process($event);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(1, $fake->fetchCount);
        $this->assertSame(EInvoiceRecordStatus::IrnNotFound->value, $record?->status);
        $this->assertNull($record?->irn);
        Http::assertNothingSent();
    }

    public function test_recovery_required_never_fails_outbox_at_max_attempts(): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $this->bindLiveFake(EInvoiceSubmitResult::ambiguous('fake', ['timeout' => true]));
        $event->update(['attempts' => 4, 'available_at' => now()]);

        app(OutboxProcessorService::class)->process(1);

        $event->refresh();
        $this->assertSame(OutboxEventStatus::Pending, $event->status);
        $this->assertNotSame(OutboxEventStatus::Failed, $event->status);
        $this->assertGreaterThanOrEqual(5, $event->attempts);
        Http::assertNothingSent();
    }

    private function assertAmbiguousGenerateDoesNotRetry(EInvoiceSubmitResult $generateResult): void
    {
        $invoice = $this->makeTaxInvoice();
        $event = $this->queue($invoice);
        $fake = $this->bindLiveFake($generateResult);
        $processor = app(EInvoiceProcessor::class);

        $this->processExpectingRecovery($processor, $event);
        $this->processExpectingRecovery($processor, $event);

        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(1, $fake->fetchCount);
        $this->assertSame(
            EInvoiceRecordStatus::Ambiguous->value,
            EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('status'),
        );
        Http::assertNothingSent();
    }

    private function processExpectingRecovery(EInvoiceProcessor $processor, OutboxEvent $event): void
    {
        try {
            $processor->process($event);
            $this->fail('Expected e-invoice recovery to remain required.');
        } catch (EInvoiceRecoveryRequiredException) {
        }
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
