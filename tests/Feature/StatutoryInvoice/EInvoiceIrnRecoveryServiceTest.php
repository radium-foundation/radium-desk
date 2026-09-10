<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\EInvoiceSubmitOutcome;
use App\Models\EInvoiceRecord;
use App\Models\OutboxEvent;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceIrnRecoveryService;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
use App\Services\StatutoryInvoice\NullEInvoiceGateway;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksEInvoiceGateway;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksIrnRecoveryGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\Support\FakeEInvoicePayloadMapper;
use Tests\TestCase;

class EInvoiceIrnRecoveryServiceTest extends TestCase
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
        $this->app->instance(EInvoiceIrnPayloadMapper::class, new FakeEInvoicePayloadMapper);
    }

    public function test_normal_issuance_stays_disabled_and_cannot_generate(): void
    {
        $this->assertFalse((bool) config('statutory_invoices.worker_may_mint'));
        $this->assertFalse((bool) config('statutory_invoices.auto_issue_on_pos_complete'));
        $this->assertSame('none', config('statutory_invoices.einvoice.provider'));
        $this->assertInstanceOf(NullEInvoiceGateway::class, app(EInvoiceGateway::class));
        $this->assertNotInstanceOf(WhitebooksEInvoiceGateway::class, app(EInvoiceGateway::class));

        Http::fake();
        $invoice = $this->makeHardwareTaxInvoice();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);
        $outbox = OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->firstOrFail();

        config([
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'whitebooks',
        ]);
        app(EInvoiceProcessor::class)->process($outbox);

        Http::assertNothingSent();
        $this->assertSame(
            EInvoiceRecordStatus::Skipped->value,
            EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('status'),
        );
    }

    public function test_recovery_uses_verified_get_irn_contract_and_persists_submitted(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response($this->successGetIrnBody(), 200),
        ]);
        $invoice = $this->makeTaxInvoice();

        $result = $this->recovery()->recover($invoice);

        $this->assertSame(EInvoiceSubmitOutcome::Success, $result->outcome);
        $this->assertSame(self::IRN, $result->irn);
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(self::IRN, $record?->irn);
        $this->assertSame('172621144003124', $record?->ack_no);
        $this->assertSame('signed-qr-from-provider', $record?->signed_qr);
        $this->assertSame(EInvoiceRecordStatus::Submitted->value, $record?->status);
        $this->assertSame('whitebooks', $record?->provider);
        $payload = is_array($result->payload) ? $result->payload : [];
        $this->assertTrue($payload['has_signed_invoice'] ?? false);
        $this->assertArrayNotHasKey('SignedInvoice', $payload);

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'GETIRNBYDOCDETAILS')) {
                return false;
            }
            parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);
            $header = fn (string $name): string => (string) ($request->header($name)[0] ?? '');

            return $request->method() === 'GET'
                && str_contains($request->url(), '/einvoice/type/GETIRNBYDOCDETAILS/version/V1_03')
                && ($query['param1'] ?? null) === 'INV'
                && ($query['email'] ?? null) === 'einvoice-test@example.test'
                && $header('docnum') === 'INV-EINV-1'
                && $header('docdate') === '10/09/2026'
                && $header('password') === '';
        });
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
        $this->assertSecretsAbsentFrom(json_encode($record?->response_payload));
    }

    public function test_recovery_does_not_overwrite_existing_irn(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response($this->successGetIrnBody(), 200),
        ]);
        $invoice = $this->makeTaxInvoice();
        EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'irn' => self::IRN,
            'ack_no' => 'ACK-KEEP',
            'signed_qr' => 'qr-keep',
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);

        $this->recovery()->recover($invoice);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(self::IRN, $record?->irn);
        $this->assertSame('ACK-KEEP', $record?->ack_no);
        $this->assertSame('qr-keep', $record?->signed_qr);
        $this->assertSame(1, EInvoiceRecord::query()->where('invoice_id', $invoice->id)->count());
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_recovery_2154_is_irn_not_found_and_does_not_generate(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response([
                'irp' => 'NIC',
                'status_cd' => '0',
                'status_desc' => json_encode([
                    ['errorCode' => '2154', 'errorMessage' => 'IRN details are not found'],
                ]),
            ], 200),
        ]);
        $invoice = $this->makeTaxInvoice();

        $result = $this->recovery()->recover($invoice);

        $this->assertSame(EInvoiceSubmitOutcome::IrnNotFound, $result->outcome);
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertNull($record?->irn);
        $this->assertSame(EInvoiceRecordStatus::IrnNotFound->value, $record?->status);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));

        $this->recovery()->recover($invoice);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
        $this->assertSame(EInvoiceRecordStatus::IrnNotFound->value, $record?->fresh()->status);
        $this->assertNull($record?->fresh()->irn);
    }

    public function test_recovery_http_404_remains_ambiguous(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response('', 404),
        ]);
        $result = $this->recovery()->recover($this->makeTaxInvoice());

        $this->assertSame(EInvoiceSubmitOutcome::Ambiguous, $result->outcome);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_recovery_get_irn_5xx_remains_temporary(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response(['error' => 'busy'], 503),
        ]);
        $result = $this->recovery()->recover($this->makeTaxInvoice());

        $this->assertSame(EInvoiceSubmitOutcome::TemporaryFailure, $result->outcome);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_recovery_unknown_error_remains_ambiguous(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response([
                'status_cd' => '0',
                'status_desc' => json_encode([['errorCode' => '9999', 'errorMessage' => 'unverified']]),
            ], 200),
        ]);
        $result = $this->recovery()->recover($this->makeTaxInvoice());

        $this->assertSame(EInvoiceSubmitOutcome::Ambiguous, $result->outcome);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_recovery_gateway_has_no_submit_method(): void
    {
        $this->assertFalse(method_exists(WhitebooksIrnRecoveryGateway::class, 'submit'));
        $this->assertFalse(method_exists(EInvoiceIrnRecoveryService::class, 'submit'));
        $this->assertInstanceOf(NullEInvoiceGateway::class, app(EInvoiceGateway::class));
    }

    private function recovery(): EInvoiceIrnRecoveryService
    {
        return app(EInvoiceIrnRecoveryService::class);
    }

    /**
     * @return array<string, mixed>
     */
    private function successGetIrnBody(): array
    {
        return [
            'irp' => 'NIC',
            'status_cd' => '1',
            'status_desc' => 'GSTR request succeeds',
            'data' => [
                'AckNo' => '172621144003124',
                'AckDt' => '2026-09-10 14:57:00',
                'Irn' => self::IRN,
                'SignedInvoice' => 'signed-invoice-must-not-be-stored',
                'SignedQRCode' => 'signed-qr-from-provider',
                'Status' => 'ACT',
            ],
        ];
    }

    private function configureWhitebooks(): void
    {
        config([
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.einvoice.provider' => 'none',
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

    private function assertSecretsAbsentFrom(?string $dump): void
    {
        $dump = (string) $dump;
        $this->assertStringNotContainsString(self::SECRET, $dump);
        $this->assertStringNotContainsString(self::TOKEN, $dump);
        $this->assertStringNotContainsString('delhi-gst-password', $dump);
    }
}
