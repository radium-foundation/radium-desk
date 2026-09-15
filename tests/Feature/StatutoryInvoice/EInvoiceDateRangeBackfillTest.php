<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\StatutoryInvoiceStatus;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\EInvoiceDateRangeBackfillService;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceIrnRecoveryService;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
use App\Services\StatutoryInvoice\EInvoiceReconciliationService;
use App\Services\StatutoryInvoice\NullEInvoiceGateway;
use App\Support\AppDateFormatter;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\Support\FakeEInvoiceGateway;
use Tests\Support\FakeEInvoicePayloadMapper;
use Tests\TestCase;

class EInvoiceDateRangeBackfillTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private const SECRET = 'WB-TEST-SECRET-MUST-NOT-LOG';

    private const TOKEN = 'WB-TEST-AUTH-TOKEN-MUST-NOT-LOG';

    private const IRN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('local');
        $this->configureWhitebooks();
        $this->configureLocationSellerIdentity();
    }

    public function test_inventory_is_read_only_and_never_generates(): void
    {
        Http::fake();
        $withIrn = $this->makeHardwareTaxInvoice(['invoice_number' => 'INV-BF-IRN', 'issued_at' => '2026-09-06 10:00:00']);
        $this->storeIrn($withIrn);
        $this->makeHardwareTaxInvoice(['invoice_number' => 'INV-BF-MISS', 'issued_at' => '2026-09-07 10:00:00']);
        $this->makeTaxInvoice([
            'invoice_number' => 'INV-BF-B2C',
            'buyer_gstin' => null,
            'issued_at' => '2026-09-08 10:00:00',
        ]);
        $this->makeHardwareTaxInvoice([
            'invoice_number' => 'INV-BF-CAN',
            'status' => StatutoryInvoiceStatus::Cancelled,
            'issued_at' => '2026-09-08 11:00:00',
        ]);
        $this->makeHardwareTaxInvoice(['invoice_number' => 'INV-BF-OLD', 'issued_at' => '2026-09-04 10:00:00']);

        $fake = $this->bindGenerateFake();
        $report = app(EInvoiceReconciliationService::class)->inventory(
            CarbonImmutable::parse('2026-09-05 00:00:00', AppDateFormatter::timezone()),
            CarbonImmutable::parse('2026-09-10 23:59:59', AppDateFormatter::timezone()),
        );

        $this->assertSame(4, $report['counts']['examined']);
        $this->assertSame(1, $report['counts']['b2b_irn_present']);
        $this->assertSame(1, $report['counts']['cancelled']);
        $this->assertSame(1, $report['counts']['b2c_not_eligible']);
        $this->assertSame(0, $fake->submitCount);
        Http::assertNothingSent();
        $this->assertSame(1, EInvoiceRecord::query()->whereNotNull('irn')->count());
    }

    public function test_existing_irn_never_generates_again(): void
    {
        $this->fakeGetIrnSuccess();
        $invoice = $this->makeHardwareTaxInvoice(['issued_at' => '2026-09-06 10:00:00']);
        $this->storeIrn($invoice);
        $fake = $this->bindGenerateFake();

        $row = app(EInvoiceDateRangeBackfillService::class)->processOne($invoice, true, true, false);

        $this->assertSame('already_had_irn', $row['result']);
        $this->assertFalse($row['generate_attempted']);
        $this->assertSame(0, $fake->submitCount);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
        $this->assertSame(self::IRN, $invoice->fresh('eInvoiceRecord')->eInvoiceRecord?->irn);
    }

    public function test_get_irn_recovery_prevents_generate(): void
    {
        $this->fakeGetIrnSuccess();
        $invoice = $this->makeHardwareTaxInvoice(['issued_at' => '2026-09-06 10:00:00']);
        $fake = $this->bindGenerateFake();

        $row = app(EInvoiceDateRangeBackfillService::class)->processOne($invoice, true, true, false);

        $this->assertSame('recovered', $row['result']);
        $this->assertTrue($row['recovery_attempted']);
        $this->assertFalse($row['generate_attempted']);
        $this->assertSame(0, $fake->submitCount);
        $this->assertSame(self::IRN, $invoice->fresh('eInvoiceRecord')->eInvoiceRecord?->irn);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_eligible_missing_irn_can_generate_after_2154(): void
    {
        $this->fakeGetIrnNotFound();
        $invoice = $this->makeHardwareTaxInvoice(['issued_at' => '2026-09-06 10:00:00']);
        $fake = $this->bindGenerateFake();

        $row = app(EInvoiceDateRangeBackfillService::class)->processOne($invoice, true, true, false);

        $this->assertTrue($row['recovery_attempted']);
        $this->assertTrue($row['generate_attempted']);
        $this->assertSame(1, $fake->submitCount);
        $this->assertTrue($invoice->fresh('eInvoiceRecord')->eInvoiceRecord?->hasIssuedIrn());
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'GETIRNBYDOCDETAILS'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_ambiguous_generate_does_not_generate_again(): void
    {
        $this->fakeGetIrnNotFound();
        $invoice = $this->makeHardwareTaxInvoice(['issued_at' => '2026-09-06 10:00:00']);
        $fake = $this->bindGenerateFake(EInvoiceSubmitResult::ambiguous('fake', ['timeout' => true]));

        $first = app(EInvoiceDateRangeBackfillService::class)->processOne($invoice, true, true, false);
        $this->assertTrue($first['generate_attempted']);
        $this->assertSame(EInvoiceRecordStatus::Ambiguous->value, $invoice->fresh('eInvoiceRecord')->eInvoiceRecord?->status);

        $second = app(EInvoiceDateRangeBackfillService::class)->processOne(
            $invoice->fresh(['items', 'eInvoiceRecord']),
            true,
            true,
            false,
        );
        $this->assertFalse($second['generate_attempted']);
        $this->assertSame(1, $fake->submitCount);
    }

    public function test_ineligible_b2b_and_b2c_and_cancelled_do_not_generate(): void
    {
        $this->fakeGetIrnNotFound();
        $fake = $this->bindGenerateFake();
        $b2c = $this->makeTaxInvoice(['buyer_gstin' => null, 'issued_at' => '2026-09-06 10:00:00']);
        $cancelled = $this->makeHardwareTaxInvoice([
            'status' => StatutoryInvoiceStatus::Cancelled,
            'issued_at' => '2026-09-06 11:00:00',
        ]);
        $cutoff = $this->makeHardwareTaxInvoice(['issued_at' => '2026-08-31 10:00:00']);

        $service = app(EInvoiceDateRangeBackfillService::class);
        $service->processOne($b2c, true, true, false);
        $service->processOne($cancelled, true, true, false);
        $service->processOne($cutoff, true, true, false);

        $this->assertSame(0, $fake->submitCount);
        $this->assertFalse((bool) $b2c->fresh('eInvoiceRecord')->eInvoiceRecord?->hasIssuedIrn());
        $this->assertFalse((bool) $cancelled->fresh('eInvoiceRecord')->eInvoiceRecord?->hasIssuedIrn());
        $this->assertFalse((bool) $cutoff->fresh('eInvoiceRecord')->eInvoiceRecord?->hasIssuedIrn());
    }

    public function test_idempotent_rerun_does_not_duplicate_irn(): void
    {
        $this->fakeGetIrnNotFound();
        $invoice = $this->makeHardwareTaxInvoice(['issued_at' => '2026-09-06 10:00:00']);
        $fake = $this->bindGenerateFake();
        $service = app(EInvoiceDateRangeBackfillService::class);

        $service->processOne($invoice, true, true, false);
        $service->processOne($invoice->fresh(['items', 'eInvoiceRecord']), true, true, false);

        $this->assertSame(1, $fake->submitCount);
        $this->assertSame(1, EInvoiceRecord::query()->where('invoice_id', $invoice->id)->whereNotNull('irn')->count());
    }

    public function test_inventory_command_does_not_call_whitebooks(): void
    {
        Http::fake();
        $this->makeHardwareTaxInvoice(['issued_at' => '2026-09-06 10:00:00']);

        $code = Artisan::call('desk:einvoice-backfill', [
            '--inventory' => true,
            '--from' => '2026-09-05 00:00:00',
            '--to' => '2026-09-10 23:59:59',
        ]);

        $this->assertSame(0, $code);
        Http::assertNothingSent();
        $this->assertStringContainsString('"examined"', Artisan::output());
    }

    public function test_issuance_gateway_remains_unused_during_inventory(): void
    {
        $this->assertInstanceOf(NullEInvoiceGateway::class, app(EInvoiceGateway::class));
        Http::fake();
        app(EInvoiceReconciliationService::class)->inventory(
            CarbonImmutable::parse('2026-09-05 00:00:00', AppDateFormatter::timezone()),
            CarbonImmutable::now(AppDateFormatter::timezone()),
        );
        Http::assertNothingSent();
    }

    private function bindGenerateFake(?EInvoiceSubmitResult $result = null): FakeEInvoiceGateway
    {
        $fake = $result === null
            ? FakeEInvoiceGateway::succeeding()
            : new FakeEInvoiceGateway($result);
        $this->app->instance(EInvoiceGateway::class, $fake);
        $this->app->instance(EInvoiceIrnPayloadMapper::class, new FakeEInvoicePayloadMapper);
        $this->app->forgetInstance(EInvoiceProcessor::class);
        $this->app->forgetInstance(EInvoiceDateRangeBackfillService::class);
        $this->app->forgetInstance(EInvoiceIrnRecoveryService::class);
        $this->app->forgetInstance(EInvoiceReconciliationService::class);

        return $fake;
    }

    private function storeIrn(StatutoryInvoice $invoice): void
    {
        EInvoiceRecord::query()->updateOrCreate(
            ['invoice_id' => $invoice->id],
            [
                'provider' => 'test',
                'irn' => self::IRN,
                'ack_no' => 'ACK-BF',
                'ack_date' => '2026-09-06 12:00:00',
                'signed_qr' => 'signed-qr-token',
                'status' => EInvoiceRecordStatus::Submitted->value,
                'response_payload' => ['ok' => true],
            ],
        );
    }

    private function fakeGetIrnSuccess(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response([
                'irp' => 'NIC',
                'status_cd' => '1',
                'status_desc' => 'GSTR request succeeds',
                'data' => [
                    'AckNo' => '172621144003124',
                    'AckDt' => '2026-09-10 14:57:00',
                    'Irn' => self::IRN,
                    'SignedInvoice' => 'signed-invoice-payload',
                    'SignedQRCode' => 'signed-qr-from-provider',
                    'Status' => 'ACT',
                ],
            ], 200),
        ]);
    }

    private function fakeGetIrnNotFound(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response([
                'status_cd' => '0',
                'status_desc' => json_encode([[
                    'errorCode' => '2154',
                    'errorMessage' => 'IRN details are not found',
                ]]),
            ], 200),
        ]);
    }

    private function configureWhitebooks(): void
    {
        config([
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.einvoice.provider' => 'none',
            'statutory_invoices.einvoice.issuance_policy' => 'all_eligible_b2b',
            'statutory_invoices.einvoice.gsp_base_url' => 'https://api.whitebooks.in',
            'statutory_invoices.einvoice.gsp_client_id' => 'wb-test-client',
            'statutory_invoices.einvoice.gsp_client_secret' => self::SECRET,
            'statutory_invoices.einvoice.gsp_email' => 'einvoice-test@example.test',
            'statutory_invoices.einvoice.gsp_ip_address' => '203.0.113.10',
            'statutory_invoices.location_series.locations.delhi.gstin' => '07AAICP1128M1Z9',
            'statutory_invoices.einvoice.issuers.delhi.gst_username' => 'delhi-gst-user',
            'statutory_invoices.einvoice.issuers.delhi.gst_password' => 'delhi-gst-password',
        ]);
    }
}
