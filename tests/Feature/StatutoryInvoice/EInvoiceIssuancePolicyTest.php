<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceIssuanceKind;
use App\Enums\EInvoiceIssuancePolicyMode;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceDocumentType;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\EInvoiceRecord;
use App\Models\OutboxEvent;
use App\Models\StatutoryInvoiceItem;
use App\Services\StatutoryInvoice\EInvoiceEligibility;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceIssuanceClassifier;
use App\Services\StatutoryInvoice\EInvoiceIssuancePolicy;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
use App\Services\StatutoryInvoice\EInvoiceServiceClassification;
use App\Services\StatutoryInvoice\NullEInvoiceGateway;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\Support\FakeEInvoiceGateway;
use Tests\Support\FakeEInvoicePayloadMapper;
use Tests\TestCase;

class EInvoiceIssuancePolicyTest extends TestCase
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
            'statutory_invoices.einvoice.issuance_policy' => EInvoiceIssuancePolicyMode::HardwareOnly->value,
        ]);
    }

    public function test_production_flags_and_policy_remain_hardware_only_and_off(): void
    {
        $this->assertFalse((bool) config('statutory_invoices.worker_may_mint'));
        $this->assertFalse((bool) config('statutory_invoices.auto_issue_on_pos_complete'));
        $this->assertSame('none', config('statutory_invoices.einvoice.provider'));
        $this->assertSame('hardware_only', config('statutory_invoices.einvoice.issuance_policy'));
        $this->assertSame(EInvoiceIssuancePolicyMode::HardwareOnly, app(EInvoiceIssuancePolicy::class)->mode());
        $this->assertInstanceOf(NullEInvoiceGateway::class, app(EInvoiceGateway::class));
        Http::assertNothingSent();
    }

    public function test_phase_a_allows_b2b_hardware_and_blocks_service(): void
    {
        $hardware = $this->makeHardwareTaxInvoice();
        $service = $this->makeTaxInvoice();

        $this->assertTrue(app(EInvoiceEligibility::class)->evaluate($hardware)->eligible);
        $this->assertSame(
            EInvoiceIssuanceKind::Hardware,
            app(EInvoiceIssuanceClassifier::class)->classify($hardware),
        );

        $serviceDecision = app(EInvoiceEligibility::class)->evaluate($service);
        $this->assertFalse($serviceDecision->eligible);
        $this->assertSame(EInvoiceIssuancePolicy::SKIP_SERVICE, $serviceDecision->reason);
        $this->assertSame(EInvoiceIssuanceKind::Service, app(EInvoiceIssuanceClassifier::class)->classify($service));
    }

    public function test_phase_a_blocks_b2c_hardware_and_b2c_service(): void
    {
        $b2cHardware = $this->makeHardwareTaxInvoice(['buyer_gstin' => null]);
        $b2cService = $this->makeTaxInvoice(['buyer_gstin' => null]);

        $this->assertSame('b2c_not_eligible', app(EInvoiceEligibility::class)->evaluate($b2cHardware)->reason);
        $this->assertSame('b2c_not_eligible', app(EInvoiceEligibility::class)->evaluate($b2cService)->reason);
    }

    public function test_phase_a_blocks_mixed_and_unknown_classification(): void
    {
        $mixed = $this->makeHardwareTaxInvoice([
            'taxable_value' => '200.00',
            'tax_total' => '36.00',
            'cgst' => '18.00',
            'sgst' => '18.00',
            'invoice_value' => '236.00',
        ]);
        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $mixed->id,
            'line_no' => 2,
            'sku' => 'RD-SVC',
            'description' => 'RD Service',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => '100.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '100.00',
            'tax_total' => '18.00',
            'cgst' => '9.00',
            'sgst' => '9.00',
            'igst' => '0.00',
            'line_total' => '118.00',
        ]);
        $unknown = $this->makeTaxInvoice([
            'channel' => StatutoryInvoiceChannel::RadiumSignCom,
        ], [
            'sku' => 'UNKNOWN-SKU',
            'hsn_sac' => '84716050',
        ]);

        $this->assertSame(EInvoiceIssuanceKind::Mixed, app(EInvoiceIssuanceClassifier::class)->classify($mixed->fresh(['items'])));
        $this->assertSame(EInvoiceIssuancePolicy::SKIP_MIXED, app(EInvoiceEligibility::class)->evaluate($mixed->fresh(['items']))->reason);
        $this->assertSame(EInvoiceIssuanceKind::Unknown, app(EInvoiceIssuanceClassifier::class)->classify($unknown));
        $this->assertSame(EInvoiceIssuancePolicy::SKIP_UNKNOWN, app(EInvoiceEligibility::class)->evaluate($unknown)->reason);
    }

    public function test_phase_a_keeps_cancelled_historical_and_document_guards(): void
    {
        $cancelled = $this->makeHardwareTaxInvoice(['status' => StatutoryInvoiceStatus::Cancelled]);
        $credit = $this->makeHardwareTaxInvoice(['document_type' => StatutoryInvoiceDocumentType::CreditNote]);
        $historical = $this->makeHardwareTaxInvoice(['issued_at' => '2026-08-31 10:00:00']);

        $this->assertSame('invoice_cancelled', app(EInvoiceEligibility::class)->evaluate($cancelled)->reason);
        $this->assertSame('unsupported_document_type', app(EInvoiceEligibility::class)->evaluate($credit)->reason);
        $this->assertSame('outside_invoice_scope', app(EInvoiceEligibility::class)->evaluate($historical)->reason);
    }

    public function test_phase_b_allows_eligible_b2b_service_without_requeueing_phase_a_skips(): void
    {
        $service = $this->makeTaxInvoice();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($service);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(
            EInvoiceIssuancePolicy::SKIP_SERVICE,
            EInvoiceRecord::query()->where('invoice_id', $service->id)->value('response_payload')['skip_reason'] ?? null,
        );

        config(['statutory_invoices.einvoice.issuance_policy' => EInvoiceIssuancePolicyMode::AllEligibleB2b->value]);
        $this->app->forgetInstance(EInvoiceIssuancePolicy::class);
        $this->app->forgetInstance(EInvoiceEligibility::class);
        $this->app->forgetInstance(StatutoryInvoiceService::class);

        $freshService = $this->makeTaxInvoice();
        $this->assertTrue(app(EInvoiceEligibility::class)->evaluate($freshService)->eligible);
        $this->assertTrue(app(EInvoiceEligibility::class)->evaluate($this->makeHardwareTaxInvoice())->eligible);
        $this->assertFalse(app(EInvoiceEligibility::class)->evaluate($this->makeTaxInvoice(['buyer_gstin' => null]))->eligible);

        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($service->fresh());
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(
            EInvoiceRecordStatus::Skipped->value,
            EInvoiceRecord::query()->where('invoice_id', $service->id)->value('status'),
        );
        $this->assertSame(
            EInvoiceIssuancePolicy::SKIP_SERVICE,
            EInvoiceRecord::query()->where('invoice_id', $service->id)->value('response_payload')['skip_reason'] ?? null,
        );
    }

    public function test_phase_a_service_cannot_generate_on_retry_or_restart(): void
    {
        $invoice = $this->makeTaxInvoice();
        $fake = $this->bindLiveFake();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);

        app(EInvoiceProcessor::class)->process(new OutboxEvent([
            'payload' => ['invoice_id' => $invoice->id],
        ]));
        app(EInvoiceProcessor::class)->process(new OutboxEvent([
            'payload' => ['invoice_id' => $invoice->id],
        ]));

        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(0, $fake->fetchCount);
        $this->assertSame(0, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        Http::assertNothingSent();
    }

    public function test_phase_a_hardware_can_reach_generate_path_when_worker_enabled_in_tests(): void
    {
        $invoice = $this->makeHardwareTaxInvoice();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);
        $event = OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->firstOrFail();
        $fake = $this->bindLiveFake();

        app(EInvoiceProcessor::class)->process($event);

        $this->assertSame(1, $fake->submitCount);
        $this->assertTrue(EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first()?->hasIssuedIrn());
        Http::assertNothingSent();
    }

    public function test_policy_does_not_change_hardware_payload_fields(): void
    {
        $invoice = $this->makeHardwareTaxInvoice([], ['uqc' => 'PCS', 'qty' => 2, 'unit_price' => '50.00', 'taxable_value' => '100.00']);
        $payload = app(EInvoiceIrnPayloadMapper::class)->map($invoice);

        $this->assertSame('84716050', $payload->items[0]['hsn_sac']);
        $this->assertSame('PCS', $payload->items[0]['unit']);
        $this->assertSame(2, (int) $payload->items[0]['qty']);
        $this->assertSame('50.00', $payload->items[0]['unit_price']);
        $this->assertSame('100.00', $payload->items[0]['taxable_value']);
        $this->assertSame('N', app(EInvoiceServiceClassification::class)->isServc($invoice, $invoice->items->first()));
        $this->assertNotContains('missing_uqc', $payload->gaps);
    }

    public function test_is_servc_is_not_the_issuance_policy(): void
    {
        $service = $this->makeTaxInvoice();
        $this->assertSame('Y', app(EInvoiceServiceClassification::class)->isServc($service, $service->items->first()));
        $this->assertFalse(app(EInvoiceIssuancePolicy::class)->evaluate($service)->permitted);
        $this->assertSame(
            EInvoiceIssuancePolicyMode::HardwareOnly,
            app(EInvoiceIssuancePolicy::class)->mode(),
        );
    }

    public function test_rdservice_in_and_empty_hsn_follow_fail_closed_kinds(): void
    {
        $rdIn = $this->makeTaxInvoice(['channel' => StatutoryInvoiceChannel::RdServiceIn]);
        $box = $this->makeHardwareTaxInvoice(['channel' => StatutoryInvoiceChannel::RadiumBoxCom]);
        $emptyHsn = $this->makeHardwareTaxInvoice([], ['hsn_sac' => '']);

        $this->assertSame(EInvoiceIssuanceKind::Service, app(EInvoiceIssuanceClassifier::class)->classify($rdIn));
        $this->assertFalse(app(EInvoiceEligibility::class)->evaluate($rdIn)->eligible);
        $this->assertTrue(app(EInvoiceEligibility::class)->evaluate($box)->eligible);
        $this->assertSame(EInvoiceIssuanceKind::Unknown, app(EInvoiceIssuanceClassifier::class)->classify($emptyHsn));
        $this->assertSame(EInvoiceIssuancePolicy::SKIP_UNKNOWN, app(EInvoiceEligibility::class)->evaluate($emptyHsn)->reason);
    }

    public function test_missing_statutory_gst_is_blocked_before_policy(): void
    {
        $invoice = $this->makeHardwareTaxInvoice(['cgst' => null], ['cgst' => null]);

        $this->assertSame('incomplete_gst', app(EInvoiceEligibility::class)->evaluate($invoice)->reason);
    }

    public function test_phase_b_allows_service_but_still_blocks_mixed_unknown_and_ineligible(): void
    {
        config(['statutory_invoices.einvoice.issuance_policy' => EInvoiceIssuancePolicyMode::AllEligibleB2b->value]);
        $this->app->forgetInstance(EInvoiceIssuancePolicy::class);
        $this->app->forgetInstance(EInvoiceEligibility::class);

        $this->assertTrue(app(EInvoiceEligibility::class)->evaluate($this->makeHardwareTaxInvoice())->eligible);
        $this->assertTrue(app(EInvoiceEligibility::class)->evaluate($this->makeTaxInvoice())->eligible);
        $this->assertSame('b2c_not_eligible', app(EInvoiceEligibility::class)->evaluate($this->makeHardwareTaxInvoice(['buyer_gstin' => null]))->reason);
        $this->assertSame('invoice_cancelled', app(EInvoiceEligibility::class)->evaluate($this->makeHardwareTaxInvoice(['status' => StatutoryInvoiceStatus::Cancelled]))->reason);
        $this->assertSame('outside_invoice_scope', app(EInvoiceEligibility::class)->evaluate($this->makeHardwareTaxInvoice(['issued_at' => '2026-08-31 10:00:00']))->reason);
        $this->assertSame(EInvoiceIssuancePolicy::SKIP_UNKNOWN, app(EInvoiceEligibility::class)->evaluate($this->makeTaxInvoice([
            'channel' => StatutoryInvoiceChannel::RadiumSignCom,
        ], [
            'sku' => 'UNKNOWN-SKU',
            'hsn_sac' => '84716050',
        ]))->reason);

        $mixed = $this->makeHardwareTaxInvoice([
            'taxable_value' => '200.00',
            'tax_total' => '36.00',
            'cgst' => '18.00',
            'sgst' => '18.00',
            'invoice_value' => '236.00',
        ]);
        StatutoryInvoiceItem::query()->create([
            'invoice_id' => $mixed->id,
            'line_no' => 2,
            'sku' => 'RD-SVC',
            'description' => 'RD Service',
            'hsn_sac' => '998313',
            'qty' => 1,
            'unit_price' => '100.00',
            'discount' => '0.00',
            'gst_percentage' => '18.00',
            'taxable_value' => '100.00',
            'tax_total' => '18.00',
            'cgst' => '9.00',
            'sgst' => '9.00',
            'igst' => '0.00',
            'line_total' => '118.00',
        ]);
        $this->assertSame(EInvoiceIssuancePolicy::SKIP_MIXED, app(EInvoiceEligibility::class)->evaluate($mixed->fresh(['items']))->reason);
    }

    public function test_already_issued_hardware_does_not_generate_again(): void
    {
        $invoice = $this->makeHardwareTaxInvoice();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);
        EInvoiceRecord::query()->where('invoice_id', $invoice->id)->update([
            'irn' => 'already-issued-irn',
            'ack_no' => 'ACK-KEEP',
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);
        $event = OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->firstOrFail();
        $fake = $this->bindLiveFake();

        app(EInvoiceProcessor::class)->process($event);
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice->fresh());

        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(0, $fake->fetchCount);
        $this->assertSame('already-issued-irn', EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('irn'));
        Http::assertNothingSent();
    }

    public function test_skipped_records_are_not_promoted_by_policy_or_worker_change(): void
    {
        $service = $this->makeTaxInvoice();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($service);
        $hardware = $this->makeHardwareTaxInvoice();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($hardware);
        $event = OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($hardware))
            ->firstOrFail();
        app(EInvoiceProcessor::class)->process($event);
        $this->assertSame(
            'worker_may_mint_off',
            EInvoiceRecord::query()->where('invoice_id', $hardware->id)->value('response_payload')['skip_reason'] ?? null,
        );

        config([
            'statutory_invoices.einvoice.issuance_policy' => EInvoiceIssuancePolicyMode::AllEligibleB2b->value,
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'fake',
        ]);
        $this->app->forgetInstance(EInvoiceIssuancePolicy::class);
        $this->app->forgetInstance(EInvoiceEligibility::class);
        $this->app->forgetInstance(StatutoryInvoiceService::class);
        $fake = $this->bindLiveFake();

        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($service->fresh());
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($hardware->fresh());
        app(EInvoiceProcessor::class)->process(new OutboxEvent([
            'payload' => ['invoice_id' => $service->id],
        ]));
        app(EInvoiceProcessor::class)->process($event);

        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(1, OutboxEvent::query()->where('event_type', EInvoiceOutboxWriter::EVENT_TYPE)->count());
        $this->assertSame(EInvoiceRecordStatus::Skipped->value, EInvoiceRecord::query()->where('invoice_id', $service->id)->value('status'));
        $this->assertSame(EInvoiceRecordStatus::Skipped->value, EInvoiceRecord::query()->where('invoice_id', $hardware->id)->value('status'));
        Http::assertNothingSent();
    }

    private function bindLiveFake(): FakeEInvoiceGateway
    {
        $fake = FakeEInvoiceGateway::succeeding();
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
