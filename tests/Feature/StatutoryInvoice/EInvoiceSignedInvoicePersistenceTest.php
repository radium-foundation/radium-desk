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
use App\Services\StatutoryInvoice\EInvoiceSignedInvoiceStore;
use App\Services\StatutoryInvoice\NullEInvoiceGateway;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksEInvoiceGateway;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\Support\FakeEInvoicePayloadMapper;
use Tests\TestCase;

class EInvoiceSignedInvoicePersistenceTest extends TestCase
{
    use CreatesStatutoryInvoicesForEinvoice;
    use RefreshDatabase;

    private const SECRET = 'WB-TEST-SECRET-MUST-NOT-LOG';

    private const TOKEN = 'WB-TEST-AUTH-TOKEN-MUST-NOT-LOG';

    private const IRN = 'a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2c3d4e5f6a1b2';

    private const SIGNED_INVOICE = 'eyJhbGciOiJ0ZXN0In0.eyJ0ZXN0Ijoic2lnbmVkLWludm9pY2UifQ.test-signature';

    protected function setUp(): void
    {
        parent::setUp();

        Http::preventStrayRequests();
        Storage::fake('local');
        $this->configureWhitebooks();
        $this->app->instance(EInvoiceIrnPayloadMapper::class, new FakeEInvoicePayloadMapper);
    }

    public function test_production_disabled_configuration_remains_unchanged(): void
    {
        $this->assertFalse((bool) config('statutory_invoices.worker_may_mint'));
        $this->assertFalse((bool) config('statutory_invoices.auto_issue_on_pos_complete'));
        $this->assertSame('none', config('statutory_invoices.einvoice.provider'));
        $this->assertInstanceOf(NullEInvoiceGateway::class, app(EInvoiceGateway::class));
        $this->assertNotInstanceOf(WhitebooksEInvoiceGateway::class, app(EInvoiceGateway::class));
    }

    public function test_generate_success_persists_signed_invoice(): void
    {
        $this->enableIssuanceForTest();
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GENERATE/*' => Http::response($this->successGenerateBody(), 200),
        ]);
        $this->app->instance(EInvoiceGateway::class, app(WhitebooksEInvoiceGateway::class));
        $invoice = $this->makeHardwareTaxInvoice();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);
        $outbox = OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->firstOrFail();

        app(EInvoiceProcessor::class)->process($outbox);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertPersistedSignedInvoice($record);
        $this->assertSame(self::IRN, $record?->irn);
        $this->assertSame('signed-qr-from-provider', $record?->signed_qr);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GETIRNBYDOCDETAILS'));
    }

    public function test_get_irn_recovery_persists_signed_invoice_without_generate(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response($this->successGetIrnBody(), 200),
        ]);
        $invoice = $this->makeTaxInvoice();

        $result = app(EInvoiceIrnRecoveryService::class)->recover($invoice);

        $this->assertSame(EInvoiceSubmitOutcome::Success, $result->outcome);
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertPersistedSignedInvoice($record);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_repeated_recovery_is_idempotent_and_does_not_overwrite_irn_or_qr(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response($this->successGetIrnBody(), 200),
        ]);
        $invoice = $this->makeTaxInvoice();
        app(EInvoiceIrnRecoveryService::class)->recover($invoice);
        $first = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $path = (string) $first?->signed_invoice_path;
        $hash = (string) $first?->signed_invoice_sha256;
        $persistedAt = (string) $first?->signed_invoice_persisted_at;

        app(EInvoiceIrnRecoveryService::class)->recover($invoice);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(self::IRN, $record?->irn);
        $this->assertSame('172621144003124', $record?->ack_no);
        $this->assertSame('signed-qr-from-provider', $record?->signed_qr);
        $this->assertSame($path, $record?->signed_invoice_path);
        $this->assertSame($hash, $record?->signed_invoice_sha256);
        $this->assertSame($persistedAt, (string) $record?->signed_invoice_persisted_at);
        $this->assertSame(1, EInvoiceRecord::query()->where('invoice_id', $invoice->id)->count());
        $this->assertSame(self::SIGNED_INVOICE, app(EInvoiceSignedInvoiceStore::class)->read($record));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_null_and_empty_signed_invoice_do_not_overwrite_existing(): void
    {
        $invoice = $this->makeTaxInvoice();
        $record = EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'irn' => self::IRN,
            'ack_no' => 'ACK-KEEP',
            'signed_qr' => 'qr-keep',
            'status' => EInvoiceRecordStatus::Submitted->value,
            'response_payload' => ['payload' => ['has_signed_invoice' => true]],
        ]);
        $store = app(EInvoiceSignedInvoiceStore::class);
        $this->assertTrue($store->persist($record, self::SIGNED_INVOICE));
        $record->refresh();
        $hash = $record->signed_invoice_sha256;

        $this->assertTrue($store->persist($record->fresh(), null));
        $this->assertTrue($store->persist($record->fresh(), ''));
        $this->assertTrue($store->persist($record->fresh(), '   '));

        $fresh = $record->fresh();
        $this->assertSame(self::IRN, $fresh?->irn);
        $this->assertSame('qr-keep', $fresh?->signed_qr);
        $this->assertSame($hash, $fresh?->signed_invoice_sha256);
        $this->assertSame(self::SIGNED_INVOICE, $store->read($fresh));
        $this->assertTrue($fresh?->hasPersistedSignedInvoice());
        $this->assertTrue($fresh?->response_payload['payload']['has_signed_invoice'] ?? false);
    }

    public function test_existing_historical_record_without_signed_invoice_remains_valid(): void
    {
        $invoice = $this->makeTaxInvoice();
        $record = EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'irn' => self::IRN,
            'ack_no' => 'ACK-HIST',
            'signed_qr' => 'qr-hist',
            'status' => EInvoiceRecordStatus::Submitted->value,
            'response_payload' => ['payload' => ['has_signed_invoice' => true]],
        ]);

        $this->assertTrue($record->hasIssuedIrn());
        $this->assertFalse($record->hasPersistedSignedInvoice());
        $this->assertNull($record->signed_invoice_path);
        $this->assertSame(self::IRN, $record->irn);
        $this->assertSame('qr-hist', $record->signed_qr);
        $this->assertNull(app(EInvoiceSignedInvoiceStore::class)->publicUrl($record));
    }

    public function test_recovery_fills_missing_signed_invoice_without_changing_irn(): void
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

        app(EInvoiceIrnRecoveryService::class)->recover($invoice);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(self::IRN, $record?->irn);
        $this->assertSame('ACK-KEEP', $record?->ack_no);
        $this->assertSame('qr-keep', $record?->signed_qr);
        $this->assertPersistedSignedInvoice($record);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_2154_does_not_create_signed_invoice(): void
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

        $result = app(EInvoiceIrnRecoveryService::class)->recover($invoice);

        $this->assertSame(EInvoiceSubmitOutcome::IrnNotFound, $result->outcome);
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(EInvoiceRecordStatus::IrnNotFound->value, $record?->status);
        $this->assertNull($record?->irn);
        $this->assertFalse((bool) $record?->hasPersistedSignedInvoice());
        $this->assertNull($record?->signed_invoice_path);
        $this->assertFalse(Storage::disk('local')->exists('statutory-einvoice/'.$invoice->id.'/signed-invoice.txt'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_storage_failure_does_not_mark_signed_invoice_persisted_or_clear_irn(): void
    {
        $root = sys_get_temp_dir().'/si-fail-'.bin2hex(random_bytes(4));
        file_put_contents($root, 'not-a-directory');
        config([
            'filesystems.disks.si_fail' => [
                'driver' => 'local',
                'root' => $root,
                'throw' => false,
            ],
        ]);
        $invoice = $this->makeTaxInvoice();
        $record = EInvoiceRecord::query()->create([
            'invoice_id' => $invoice->id,
            'provider' => 'whitebooks',
            'irn' => self::IRN,
            'ack_no' => 'ACK-KEEP',
            'signed_qr' => 'qr-keep',
            'status' => EInvoiceRecordStatus::Submitted->value,
            'response_payload' => ['payload' => ['has_signed_invoice' => false]],
        ]);
        $store = new EInvoiceSignedInvoiceStore('si_fail');

        $ok = $store->persist($record, self::SIGNED_INVOICE);

        $fresh = $record->fresh();
        $this->assertFalse($ok);
        $this->assertSame(self::IRN, $fresh?->irn);
        $this->assertSame('qr-keep', $fresh?->signed_qr);
        $this->assertFalse((bool) $fresh?->hasPersistedSignedInvoice());
        $this->assertTrue($fresh?->response_payload['payload']['signed_invoice_persist_failed'] ?? false);
        $this->assertFalse($fresh?->response_payload['payload']['has_signed_invoice'] ?? true);
        $this->assertStringNotContainsString(self::SIGNED_INVOICE, (string) json_encode($fresh?->response_payload));
        @unlink($root);
    }

    public function test_signed_invoice_is_not_logged_or_publicly_addressable(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response($this->successGetIrnBody(), 200),
        ]);
        $invoice = $this->makeTaxInvoice();
        app(EInvoiceIrnRecoveryService::class)->recover($invoice);
        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->firstOrFail();

        $this->assertNull(app(EInvoiceSignedInvoiceStore::class)->publicUrl($record));
        $this->assertSame('local', $record->signed_invoice_disk);
        $this->assertNotSame('public', $record->signed_invoice_disk);
        $this->assertStringStartsWith('statutory-einvoice/', (string) $record->signed_invoice_path);
        $this->assertStringNotContainsString('http', (string) $record->signed_invoice_path);
        $this->assertStringNotContainsString('/storage/', (string) $record->signed_invoice_path);
        $this->assertStringNotContainsString(self::SIGNED_INVOICE, (string) json_encode($record->response_payload));
        $this->assertStringNotContainsString(self::SECRET, (string) json_encode($record->response_payload));
        $this->assertStringNotContainsString(self::TOKEN, (string) json_encode($record->response_payload));
    }

    public function test_signed_invoice_belongs_to_the_correct_record(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response($this->successGetIrnBody(), 200),
        ]);
        $first = $this->makeTaxInvoice();
        $second = $this->makeTaxInvoice();
        app(EInvoiceIrnRecoveryService::class)->recover($first);

        $firstRecord = EInvoiceRecord::query()->where('invoice_id', $first->id)->first();
        $secondRecord = EInvoiceRecord::query()->where('invoice_id', $second->id)->first();
        $this->assertNotNull($firstRecord);
        $this->assertNull($secondRecord);
        $this->assertSame('statutory-einvoice/'.$first->id.'/signed-invoice.txt', $firstRecord?->signed_invoice_path);
        $this->assertFalse(Storage::disk('local')->exists('statutory-einvoice/'.$second->id.'/signed-invoice.txt'));
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function successGenerateBody(): array
    {
        return [
            'status_cd' => '1',
            'status_desc' => 'GSTR request succeeds',
            'data' => [
                'Status' => 'ACT',
                'Irn' => self::IRN,
                'AckNo' => '112345678901234',
                'AckDt' => '2026-09-10 10:15:00',
                'SignedQRCode' => 'signed-qr-from-provider',
                'SignedInvoice' => self::SIGNED_INVOICE,
            ],
        ];
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
                'SignedInvoice' => self::SIGNED_INVOICE,
                'SignedQRCode' => 'signed-qr-from-provider',
                'Status' => 'ACT',
            ],
        ];
    }

    private function enableIssuanceForTest(): void
    {
        config([
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'whitebooks',
        ]);
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

    private function assertPersistedSignedInvoice(?EInvoiceRecord $record): void
    {
        $this->assertNotNull($record);
        $this->assertTrue($record->hasPersistedSignedInvoice());
        $this->assertSame('local', $record->signed_invoice_disk);
        $this->assertSame('statutory-einvoice/'.$record->invoice_id.'/signed-invoice.txt', $record->signed_invoice_path);
        $this->assertSame(strlen(self::SIGNED_INVOICE), (int) $record->signed_invoice_bytes);
        $this->assertSame(hash('sha256', self::SIGNED_INVOICE), $record->signed_invoice_sha256);
        $this->assertNotNull($record->signed_invoice_persisted_at);
        $this->assertTrue($record->response_payload['payload']['has_signed_invoice'] ?? false);
        $this->assertArrayNotHasKey('SignedInvoice', $record->response_payload['payload'] ?? []);
        $this->assertStringNotContainsString(self::SIGNED_INVOICE, (string) json_encode($record->response_payload));
        $stored = app(EInvoiceSignedInvoiceStore::class)->read($record);
        $this->assertSame(self::SIGNED_INVOICE, $stored);
        $this->assertSame(self::SIGNED_INVOICE, Storage::disk('local')->get((string) $record->signed_invoice_path));
        $this->assertNull(app(EInvoiceSignedInvoiceStore::class)->publicUrl($record));
    }
}
