<?php

namespace Tests\Feature\StatutoryInvoice;

use App\Contracts\StatutoryInvoice\EInvoiceGateway;
use App\Enums\EInvoiceRecordStatus;
use App\Enums\EInvoiceSubmitOutcome;
use App\Models\EInvoiceRecord;
use App\Models\OutboxEvent;
use App\Services\StatutoryInvoice\Data\EInvoiceIrnPayload;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceOutboxWriter;
use App\Services\StatutoryInvoice\EInvoiceProcessor;
use App\Services\StatutoryInvoice\EInvoiceRecoveryRequiredException;
use App\Services\StatutoryInvoice\NullEInvoiceGateway;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksEInvoiceGateway;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksNicPayloadFactory;
use App\Services\StatutoryInvoice\Whitebooks\WhitebooksResponseMapper;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\Support\CreatesStatutoryInvoicesForEinvoice;
use Tests\Support\FakeEInvoicePayloadMapper;
use Tests\TestCase;

class WhitebooksEInvoiceGatewayTest extends TestCase
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
    }

    public function test_production_binding_stays_null_and_flags_off(): void
    {
        $this->assertFalse((bool) config('statutory_invoices.worker_may_mint'));
        $this->assertFalse((bool) config('statutory_invoices.auto_issue_on_pos_complete'));
        $this->assertSame('none', config('statutory_invoices.einvoice.provider'));
        $this->assertInstanceOf(NullEInvoiceGateway::class, app(EInvoiceGateway::class));
        Http::assertNothingSent();
    }

    public function test_authenticate_success_then_generate_persists_irn_fields(): void
    {
        $this->fakeAuthAndGenerate($this->successGenerateBody());
        $invoice = $this->makeTaxInvoice();
        $result = $this->gateway()->submit($invoice, $this->payload($invoice));

        $this->assertSame(EInvoiceSubmitOutcome::Success, $result->outcome);
        $this->assertSame(self::IRN, $result->irn);
        $this->assertSame('112345678901234', $result->ackNo);
        $this->assertSame('2026-09-10 10:15:00', $result->ackDate);
        $this->assertSame('signed-qr-from-provider', $result->signedQr);
        $this->assertArrayNotHasKey('SignedInvoice', is_array($result->payload) ? $result->payload : []);
        $this->assertAuthenticateThenGenerate();
        $this->assertSecretsAbsent($result);
    }

    public function test_authenticate_missing_token_is_permanent(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => []], 200),
        ]);
        $result = $this->gateway()->submit($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::PermanentFailure, $result->outcome);
        $this->assertSame('missing_auth_token', $result->payload['reason'] ?? null);
        Http::assertSentCount(1);
        $this->assertSecretsAbsent($result);
    }

    public function test_authentication_failure_is_permanent(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['message' => 'invalid'], 401),
        ]);
        $result = $this->gateway()->submit($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::PermanentFailure, $result->outcome);
        $this->assertSame('authenticate_unauthorized', $result->payload['reason'] ?? null);
        $this->assertSecretsAbsent($result);
    }

    public function test_authenticate_timeout_is_temporary_and_does_not_generate(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'authenticate')) {
                throw new ConnectionException('cURL error 28');
            }

            $this->fail('GENERATE must not run after authenticate timeout.');
        });
        $result = $this->gateway()->submit($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::TemporaryFailure, $result->outcome);
        $this->assertSame('authenticate_timeout', $result->payload['reason'] ?? null);
        $this->assertSecretsAbsent($result);
    }

    public function test_generate_validation_rejection_is_permanent(): void
    {
        $this->fakeAuthAndGenerate(['status_cd' => '0', 'status_desc' => 'Invalid HSN'], 400);
        $result = $this->gateway()->submit($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::PermanentFailure, $result->outcome);
        $this->assertSame('generate_validation_error', $result->payload['reason'] ?? null);
        $this->assertSecretsAbsent($result);
    }

    public function test_generate_5xx_is_ambiguous(): void
    {
        $this->fakeAuthAndGenerate(['status' => 'error'], 503);
        $result = $this->gateway()->submit($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::Ambiguous, $result->outcome);
        $this->assertSame('generate_provider_5xx', $result->payload['reason'] ?? null);
        $this->assertSecretsAbsent($result);
    }

    public function test_generate_429_is_ambiguous(): void
    {
        $this->fakeAuthAndGenerate(['status' => 'error'], 429);
        $result = $this->gateway()->submit($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::Ambiguous, $result->outcome);
        $this->assertSame('generate_rate_limited', $result->payload['reason'] ?? null);
        $this->assertSecretsAbsent($result);
    }

    public function test_authenticate_429_is_temporary_and_does_not_generate(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'authenticate')) {
                return Http::response(['message' => 'rate limited'], 429);
            }

            $this->fail('GENERATE must not run after authenticate 429.');
        });
        $result = $this->gateway()->submit($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::TemporaryFailure, $result->outcome);
        $this->assertSame('authenticate_rate_limited', $result->payload['reason'] ?? null);
        $this->assertSecretsAbsent($result);
    }

    public function test_generate_malformed_response_is_ambiguous(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GENERATE/*' => Http::response('not-json', 200),
        ]);
        $result = $this->gateway()->submit($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::Ambiguous, $result->outcome);
        $this->assertSame('malformed_generate_response', $result->payload['reason'] ?? null);
        $this->assertSecretsAbsent($result);
    }

    public function test_generate_timeout_is_ambiguous(): void
    {
        Http::fake(function ($request) {
            if (str_contains($request->url(), 'authenticate')) {
                return Http::response(['data' => ['AuthToken' => self::TOKEN]], 200);
            }
            throw new ConnectionException('cURL error 28');
        });
        $result = $this->gateway()->submit($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::Ambiguous, $result->outcome);
        $this->assertSame('generate_timeout', $result->payload['reason'] ?? null);
        $this->assertSecretsAbsent($result);
    }

    public function test_generate_2150_is_not_mapped_as_duplicate_without_whitebooks_proof(): void
    {
        $this->fakeAuthAndGenerate([
            'irp' => 'NIC',
            'status_cd' => '0',
            'status_desc' => json_encode([
                ['errorCode' => '2150', 'errorMessage' => 'Duplicate IRN'],
            ]),
        ]);
        $result = $this->gateway()->submit($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame([], WhitebooksResponseMapper::VERIFIED_GENERATE_DUPLICATE_ERROR_CODES);
        $this->assertSame(EInvoiceSubmitOutcome::PermanentFailure, $result->outcome);
        $this->assertSame('missing_irn', $result->payload['reason'] ?? null);
        $this->assertSecretsAbsent($result);
    }

    public function test_generate_payload_is_nic_b2b_without_dummy_blocks(): void
    {
        $this->fakeAuthAndGenerate($this->successGenerateBody());
        $invoice = $this->makeTaxInvoice();
        $this->gateway()->submit($invoice, $this->payload($invoice));

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'GENERATE')) {
                return false;
            }
            $body = $request->data();

            return ($body['Version'] ?? null) === '1.1'
                && ($body['TranDtls']['SupTyp'] ?? null) === 'B2B'
                && ($body['DocDtls']['Typ'] ?? null) === 'INV'
                && ($body['BuyerDtls']['Pos'] ?? null) === '07'
                && ($body['ItemList'][0]['IsServc'] ?? null) === 'Y'
                && ($body['ItemList'][0]['Unit'] ?? null) === 'NOS'
                && ! array_key_exists('EwbDtls', $body)
                && ! array_key_exists('ExpDtls', $body)
                && ! array_key_exists('PayDtls', $body)
                && ! array_key_exists('VehDtls', $body);
        });
    }

    public function test_unmapped_gstin_fails_closed_without_http(): void
    {
        $invoice = $this->makeTaxInvoice(['seller_gstin' => '27AAAAA0000A1Z5']);
        $result = $this->gateway()->submit($invoice, $this->payload($invoice));

        $this->assertSame(EInvoiceSubmitOutcome::PermanentFailure, $result->outcome);
        $this->assertContains('unmapped_seller_gstin', $result->payload['gaps'] ?? []);
        Http::assertNothingSent();
    }

    public function test_authenticate_uses_configured_ip_address(): void
    {
        $this->fakeAuthAndGenerate($this->successGenerateBody());
        $this->gateway()->submit($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'authenticate')) {
                return false;
            }
            $header = fn (string $name): string => (string) ($request->header($name)[0] ?? '');

            return $request->method() === 'GET'
                && str_contains($request->url(), '/einvoice/authenticate')
                && str_contains($request->url(), 'email=')
                && $header('ip_address') === '203.0.113.10'
                && $header('client_id') === 'wb-test-client'
                && $header('client_secret') === self::SECRET
                && $header('username') === 'delhi-gst-user'
                && $header('gstin') === '07AAICP1128M1Z9';
        });
    }

    public function test_missing_gsp_ip_fails_closed_without_http_or_fallback(): void
    {
        config(['statutory_invoices.einvoice.gsp_ip_address' => null]);
        request()->server->set('REMOTE_ADDR', '192.168.0.1');
        $invoice = $this->makeTaxInvoice();
        $result = $this->gateway()->fetchExisting($invoice, $this->payload($invoice));

        $this->assertSame(EInvoiceSubmitOutcome::PermanentFailure, $result->outcome);
        $this->assertContains('missing_gsp_ip_address', $result->payload['gaps'] ?? []);
        Http::assertNothingSent();
        $this->assertSecretsAbsent($result);
    }

    public function test_blank_gsp_ip_fails_closed_without_http(): void
    {
        config(['statutory_invoices.einvoice.gsp_ip_address' => '   ']);
        $result = $this->gateway()->fetchExisting($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::PermanentFailure, $result->outcome);
        $this->assertContains('missing_gsp_ip_address', $result->payload['gaps'] ?? []);
        Http::assertNothingSent();
    }

    /**
     * @return array<string, list<string>>
     */
    public static function invalidGspIpProvider(): array
    {
        return [
            'hostname' => ['localhost'],
            'malformed' => ['not-an-ip'],
            'incomplete' => ['203.0.113'],
            'ipv6' => ['2001:db8::1'],
        ];
    }

    #[DataProvider('invalidGspIpProvider')]
    public function test_invalid_gsp_ip_fails_closed_without_http(string $ip): void
    {
        config(['statutory_invoices.einvoice.gsp_ip_address' => $ip]);
        request()->server->set('REMOTE_ADDR', '192.168.0.1');
        $result = $this->gateway()->fetchExisting($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::PermanentFailure, $result->outcome);
        $this->assertContains('invalid_gsp_ip_address', $result->payload['gaps'] ?? []);
        $this->assertNotContains('missing_gsp_ip_address', $result->payload['gaps'] ?? []);
        Http::assertNothingSent();
        $this->assertSecretsAbsent($result);
    }

    public function test_get_irn_uses_verified_p194_request_shape(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response($this->successGetIrnBody(), 200),
        ]);
        $invoice = $this->makeTaxInvoice();
        $this->gateway()->fetchExisting($invoice, $this->payload($invoice));

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
                && ! array_key_exists('doctype', $query)
                && ! array_key_exists('docnum', $query)
                && ! array_key_exists('docdate', $query)
                && $header('docnum') === 'INV-EINV-1'
                && $header('docdate') === '10/09/2026'
                && $header('ip_address') === '203.0.113.10'
                && $header('client_id') === 'wb-test-client'
                && $header('client_secret') === self::SECRET
                && $header('username') === 'delhi-gst-user'
                && $header('auth-token') === self::TOKEN
                && $header('gstin') === '07AAICP1128M1Z9'
                && $header('password') === ''
                && $header('Password') === '';
        });
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_get_irn_uses_configured_ip_not_request_or_private_address(): void
    {
        config(['statutory_invoices.einvoice.gsp_ip_address' => '198.51.100.20']);
        request()->server->set('REMOTE_ADDR', '192.168.0.1');
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response($this->successGetIrnBody(), 200),
        ]);
        $this->gateway()->fetchExisting($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'authenticate')) {
                return false;
            }

            return (string) ($request->header('ip_address')[0] ?? '') === '198.51.100.20'
                && (string) ($request->header('ip_address')[0] ?? '') !== '192.168.0.1'
                && (string) ($request->header('ip_address')[0] ?? '') !== '127.0.0.1';
        });
        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'GETIRNBYDOCDETAILS')) {
                return false;
            }
            $header = fn (string $name): string => (string) ($request->header($name)[0] ?? '');

            return $header('ip_address') === '198.51.100.20'
                && $header('ip_address') !== '192.168.0.1'
                && $header('password') === '';
        });
    }

    public function test_missing_gsp_client_secret_fails_closed_without_http(): void
    {
        config(['statutory_invoices.einvoice.gsp_client_secret' => null]);
        $result = $this->gateway()->fetchExisting($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::PermanentFailure, $result->outcome);
        $this->assertContains('missing_gsp_client_secret', $result->payload['gaps'] ?? []);
        Http::assertNothingSent();
    }

    public function test_get_irn_uses_issuer_specific_gstin_and_username(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response($this->successGetIrnBody(), 200),
        ]);
        $invoice = $this->makeTaxInvoice(['seller_gstin' => '27AAICP1128M1Z7']);
        $this->gateway()->fetchExisting($invoice, $this->payload($invoice));

        Http::assertSent(function ($request): bool {
            if (! str_contains($request->url(), 'GETIRNBYDOCDETAILS')) {
                return false;
            }
            $header = fn (string $name): string => (string) ($request->header($name)[0] ?? '');

            return $header('gstin') === '27AAICP1128M1Z7'
                && $header('username') === 'mumbai-gst-user'
                && $header('username') !== 'delhi-gst-user'
                && $header('password') === '';
        });
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_get_irn_maps_p194_success_envelope(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response($this->successGetIrnBody(), 200),
        ]);
        $result = $this->gateway()->fetchExisting($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::Success, $result->outcome);
        $this->assertSame(self::IRN, $result->irn);
        $this->assertSame('172621144003124', $result->ackNo);
        $this->assertSame('2026-09-10 14:57:00', $result->ackDate);
        $this->assertSame('signed-qr-from-provider', $result->signedQr);
        $payload = is_array($result->payload) ? $result->payload : [];
        $this->assertSame('1', $payload['status_cd'] ?? null);
        $this->assertSame('GSTR request succeeds', $payload['status_desc'] ?? null);
        $this->assertSame('ACT', $payload['Status'] ?? null);
        $this->assertTrue($payload['has_signed_invoice'] ?? false);
        $this->assertArrayNotHasKey('SignedInvoice', $payload);
        $this->assertArrayNotHasKey('SignedQRCode', $payload);
        $this->assertArrayNotHasKey('EwbNo', $payload);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
        $this->assertSecretsAbsent($result);
    }

    public function test_get_irn_finds_nothing(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response([
                'status_cd' => '1',
                'status_desc' => 'GSTR request succeeds',
                'irp' => 'NIC',
                'data' => [],
            ], 200),
        ]);
        $result = $this->gateway()->fetchExisting($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::Ambiguous, $result->outcome);
        $this->assertSame('not_found', $result->payload['reason'] ?? null);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
        $this->assertSecretsAbsent($result);
    }

    public function test_get_irn_2154_without_data_is_confirmed_not_found(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response($this->notFound2154Body(), 200),
        ]);
        $result = $this->gateway()->fetchExisting($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::IrnNotFound, $result->outcome);
        $this->assertSame('irn_not_found', $result->payload['reason'] ?? null);
        $this->assertSame('2154', $result->payload['error_code'] ?? null);
        $this->assertSame('0', $result->payload['status_cd'] ?? null);
        $this->assertNull($result->irn);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
        $this->assertSecretsAbsent($result);
    }

    public function test_get_irn_2154_is_found_without_assuming_error_order(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response($this->notFound2154Body([
                ['errorCode' => '2148', 'errorMessage' => 'Requested IRN data is not available'],
                ['errorCode' => '2154', 'errorMessage' => 'IRN details are not found'],
            ]), 200),
        ]);
        $result = $this->gateway()->fetchExisting($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::IrnNotFound, $result->outcome);
        $this->assertSame('2154', $result->payload['error_code'] ?? null);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_get_irn_http_404_remains_ambiguous(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response('', 404),
        ]);
        $result = $this->gateway()->fetchExisting($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::Ambiguous, $result->outcome);
        $this->assertSame('not_found', $result->payload['reason'] ?? null);
        $this->assertSame(404, $result->payload['http_status'] ?? null);
        $this->assertNotSame(EInvoiceSubmitOutcome::IrnNotFound, $result->outcome);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
        $this->assertSecretsAbsent($result);
    }

    public function test_get_irn_unknown_error_code_remains_ambiguous(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response([
                'irp' => 'NIC',
                'status_cd' => '0',
                'status_desc' => json_encode([
                    ['errorCode' => '9999', 'errorMessage' => 'unverified code'],
                ]),
            ], 200),
        ]);
        $result = $this->gateway()->fetchExisting($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::Ambiguous, $result->outcome);
        $this->assertSame('not_found', $result->payload['reason'] ?? null);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
        $this->assertSecretsAbsent($result);
    }

    public function test_get_irn_malformed_response_remains_conservative(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response('not-json', 200),
        ]);
        $result = $this->gateway()->fetchExisting($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::TemporaryFailure, $result->outcome);
        $this->assertSame('malformed_get_irn_response', $result->payload['reason'] ?? null);
        $this->assertNotSame(EInvoiceSubmitOutcome::IrnNotFound, $result->outcome);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
        $this->assertSecretsAbsent($result);
    }

    public function test_get_irn_provider_failure_is_temporary(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response(['error' => 'busy'], 503),
        ]);
        $result = $this->gateway()->fetchExisting($this->makeTaxInvoice(), $this->payload($this->makeTaxInvoice()));

        $this->assertSame(EInvoiceSubmitOutcome::TemporaryFailure, $result->outcome);
        $this->assertSame('get_irn_provider_5xx', $result->payload['reason'] ?? null);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
        $this->assertSecretsAbsent($result);
    }

    public function test_processor_recovery_calls_get_irn_not_generate(): void
    {
        $generateCalls = 0;
        $getIrnCalls = 0;
        Http::fake(function ($request) use (&$generateCalls, &$getIrnCalls) {
            if (str_contains($request->url(), 'authenticate')) {
                return Http::response(['data' => ['AuthToken' => self::TOKEN]], 200);
            }
            if (str_contains($request->url(), 'GENERATE')) {
                $generateCalls++;

                return Http::response(['status' => 'error'], 503);
            }
            if (str_contains($request->url(), 'GETIRNBYDOCDETAILS')) {
                $getIrnCalls++;

                return Http::response($this->successGetIrnBody(), 200);
            }

            $this->fail('Unexpected WhiteBooks URL: '.$request->url());
        });
        $this->app->instance(EInvoiceGateway::class, $this->gateway());
        $this->app->instance(EInvoiceIrnPayloadMapper::class, new FakeEInvoicePayloadMapper);
        config([
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'whitebooks',
        ]);
        $invoice = $this->makeHardwareTaxInvoice();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);
        $outbox = OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->firstOrFail();
        $processor = app(EInvoiceProcessor::class);

        $this->processExpectingRecovery($processor, $outbox);
        $this->assertSame(1, $generateCalls);
        $this->assertSame(0, $getIrnCalls);
        $this->assertSame(EInvoiceRecordStatus::Ambiguous->value, EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('status'));

        $processor->process($outbox);
        $this->assertSame(1, $generateCalls);
        $this->assertSame(1, $getIrnCalls);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(self::IRN, $record?->irn);
        $this->assertSame('172621144003124', $record?->ack_no);
        $this->assertSame('signed-qr-from-provider', $record?->signed_qr);
        $this->assertSame(EInvoiceRecordStatus::Submitted->value, $record?->status);

        $processor->process($outbox);
        $this->assertSame(1, $generateCalls);
        $this->assertSame(1, $getIrnCalls);
        $this->assertSame(self::IRN, $record?->fresh()->irn);
        $this->assertSecretsAbsentFrom(json_encode($record?->response_payload));
    }

    public function test_processor_2154_after_ambiguous_generate_does_not_generate_again(): void
    {
        $generateCalls = 0;
        $getIrnCalls = 0;
        Http::fake(function ($request) use (&$generateCalls, &$getIrnCalls) {
            if (str_contains($request->url(), 'authenticate')) {
                return Http::response(['data' => ['AuthToken' => self::TOKEN]], 200);
            }
            if (str_contains($request->url(), 'GENERATE')) {
                $generateCalls++;

                return Http::response(['status' => 'error'], 503);
            }
            if (str_contains($request->url(), 'GETIRNBYDOCDETAILS')) {
                $getIrnCalls++;

                return Http::response($this->notFound2154Body(), 200);
            }

            $this->fail('Unexpected WhiteBooks URL: '.$request->url());
        });
        $this->app->instance(EInvoiceGateway::class, $this->gateway());
        $this->app->instance(EInvoiceIrnPayloadMapper::class, new FakeEInvoicePayloadMapper);
        config([
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'whitebooks',
        ]);
        $invoice = $this->makeHardwareTaxInvoice();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);
        $outbox = OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->firstOrFail();
        $processor = app(EInvoiceProcessor::class);

        $this->processExpectingRecovery($processor, $outbox);
        $this->assertSame(1, $generateCalls);
        $this->assertSame(0, $getIrnCalls);
        $this->assertSame(EInvoiceRecordStatus::Ambiguous->value, EInvoiceRecord::query()->where('invoice_id', $invoice->id)->value('status'));

        $processor->process($outbox);
        $this->assertSame(1, $generateCalls);
        $this->assertSame(1, $getIrnCalls);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertNull($record?->irn);
        $this->assertSame(EInvoiceRecordStatus::IrnNotFound->value, $record?->status);
        $this->assertSame(EInvoiceSubmitOutcome::IrnNotFound->value, $record?->response_payload['outcome'] ?? null);
        $this->assertSame('2154', $record?->response_payload['payload']['error_code'] ?? null);

        $processor->process($outbox);
        $this->assertSame(1, $generateCalls);
        $this->assertSame(1, $getIrnCalls);
        $this->assertNull($record?->fresh()->irn);
        $this->assertSame(EInvoiceRecordStatus::IrnNotFound->value, $record?->fresh()->status);
        $this->assertSecretsAbsentFrom(json_encode($record?->response_payload));
    }

    public function test_empty_get_irn_cannot_clear_issued_irn(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response(['data' => []], 200),
            'https://api.whitebooks.in/einvoice/type/GENERATE/*' => Http::response($this->successGenerateBody(), 200),
        ]);
        $this->app->instance(EInvoiceGateway::class, $this->gateway());
        $this->app->instance(EInvoiceIrnPayloadMapper::class, new FakeEInvoicePayloadMapper);
        config([
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'whitebooks',
        ]);
        $invoice = $this->makeHardwareTaxInvoice();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);
        EInvoiceRecord::query()->where('invoice_id', $invoice->id)->update([
            'irn' => self::IRN,
            'ack_no' => 'ACK-KEEP',
            'signed_qr' => 'qr-keep',
            'status' => EInvoiceRecordStatus::Submitted->value,
        ]);
        $outbox = OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->firstOrFail();

        app(EInvoiceProcessor::class)->process($outbox);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(self::IRN, $record?->irn);
        $this->assertSame('ACK-KEEP', $record?->ack_no);
        $this->assertSame('qr-keep', $record?->signed_qr);
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GETIRNBYDOCDETAILS'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_empty_get_irn_recovery_does_not_persist_blank_irn_or_generate(): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GETIRNBYDOCDETAILS/*' => Http::response(['data' => []], 200),
            'https://api.whitebooks.in/einvoice/type/GENERATE/*' => Http::response($this->successGenerateBody(), 200),
        ]);
        $this->app->instance(EInvoiceGateway::class, $this->gateway());
        $this->app->instance(EInvoiceIrnPayloadMapper::class, new FakeEInvoicePayloadMapper);
        config([
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'whitebooks',
        ]);
        $invoice = $this->makeHardwareTaxInvoice();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);
        EInvoiceRecord::query()->where('invoice_id', $invoice->id)->update([
            'irn' => null,
            'ack_no' => 'ACK-KEEP',
            'signed_qr' => 'qr-keep',
            'status' => EInvoiceRecordStatus::Ambiguous->value,
        ]);
        $outbox = OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->firstOrFail();

        $this->processExpectingRecovery(app(EInvoiceProcessor::class), $outbox);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertNull($record?->irn);
        $this->assertSame('ACK-KEEP', $record?->ack_no);
        $this->assertSame('qr-keep', $record?->signed_qr);
        $this->assertSame(EInvoiceRecordStatus::Ambiguous->value, $record?->status);
        Http::assertSent(fn ($request): bool => str_contains($request->url(), 'GETIRNBYDOCDETAILS'));
        Http::assertNotSent(fn ($request): bool => str_contains($request->url(), 'GENERATE'));
    }

    public function test_production_disabled_config_unchanged_after_recovery_tests(): void
    {
        config([
            'statutory_invoices.worker_may_mint' => false,
            'statutory_invoices.auto_issue_on_pos_complete' => false,
            'statutory_invoices.einvoice.provider' => 'none',
        ]);
        $this->assertFalse((bool) config('statutory_invoices.worker_may_mint'));
        $this->assertFalse((bool) config('statutory_invoices.auto_issue_on_pos_complete'));
        $this->assertSame('none', config('statutory_invoices.einvoice.provider'));
        $this->assertInstanceOf(NullEInvoiceGateway::class, app(EInvoiceGateway::class));
    }

    public function test_processor_persists_whitebooks_success_fields(): void
    {
        $this->fakeAuthAndGenerate($this->successGenerateBody());
        $this->app->instance(EInvoiceGateway::class, $this->gateway());
        $this->app->instance(EInvoiceIrnPayloadMapper::class, new FakeEInvoicePayloadMapper);
        config([
            'statutory_invoices.worker_may_mint' => true,
            'statutory_invoices.einvoice.provider' => 'whitebooks',
        ]);
        $invoice = $this->makeHardwareTaxInvoice();
        app(StatutoryInvoiceService::class)->queueEinvoiceIfEligible($invoice);
        $outbox = OutboxEvent::query()
            ->where('idempotency_key', EInvoiceOutboxWriter::idempotencyKeyForInvoice($invoice))
            ->firstOrFail();

        app(EInvoiceProcessor::class)->process($outbox);

        $record = EInvoiceRecord::query()->where('invoice_id', $invoice->id)->first();
        $this->assertSame(self::IRN, $record?->irn);
        $this->assertSame('112345678901234', $record?->ack_no);
        $this->assertSame('signed-qr-from-provider', $record?->signed_qr);
        $this->assertSame(EInvoiceRecordStatus::Submitted->value, $record?->status);
        $this->assertSecretsAbsentFrom(json_encode($record?->response_payload));
    }

    public function test_nic_factory_omits_dummy_admin_fields(): void
    {
        $invoice = $this->makeTaxInvoice();
        $body = (new WhitebooksNicPayloadFactory)->generateBody($this->payload($invoice));

        $this->assertIsArray($body);
        $this->assertArrayNotHasKey('EwbDtls', $body);
        $this->assertArrayNotHasKey('ExpDtls', $body);
        $this->assertArrayNotHasKey('PayDtls', $body);
        $this->assertSame('NOS', $body['ItemList'][0]['Unit'] ?? null);
        $this->assertNotSame('pcs', $body['ItemList'][0]['Unit'] ?? null);
    }

    private function processExpectingRecovery(EInvoiceProcessor $processor, OutboxEvent $outbox): void
    {
        try {
            $processor->process($outbox);
            $this->fail('Expected e-invoice recovery to remain required.');
        } catch (EInvoiceRecoveryRequiredException) {
        }
    }

    private function gateway(): WhitebooksEInvoiceGateway
    {
        return app(WhitebooksEInvoiceGateway::class);
    }

    private function payload($invoice): EInvoiceIrnPayload
    {
        return (new FakeEInvoicePayloadMapper)->map($invoice);
    }

    /**
     * @param  array<string, mixed>  $generateBody
     */
    private function fakeAuthAndGenerate(array $generateBody, int $generateStatus = 200): void
    {
        Http::fake([
            'https://api.whitebooks.in/einvoice/authenticate*' => Http::response(['data' => ['AuthToken' => self::TOKEN]], 200),
            'https://api.whitebooks.in/einvoice/type/GENERATE/*' => Http::response($generateBody, $generateStatus),
        ]);
    }

    /**
     * @return array<string, mixed>
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
                'SignedInvoice' => 'signed-invoice-must-not-be-stored',
            ],
        ];
    }

    /**
     * P-194 production GETIRNBYDOCDETAILS success envelope.
     *
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
                'EwbNo' => null,
                'EwbDt' => null,
                'EwbValidTill' => null,
                'Remarks' => null,
            ],
        ];
    }

    /**
     * P-196 production GETIRNBYDOCDETAILS not-found envelope. `data` is omitted.
     *
     * @param  list<array{errorCode: string, errorMessage: string}>|null  $errors
     * @return array<string, mixed>
     */
    private function notFound2154Body(?array $errors = null): array
    {
        return [
            'irp' => 'NIC',
            'status_cd' => '0',
            'status_desc' => json_encode($errors ?? [
                ['errorCode' => '2154', 'errorMessage' => 'IRN details are not found'],
            ]),
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
            'statutory_invoices.location_series.locations.mumbai.gstin' => '27AAICP1128M1Z7',
            'statutory_invoices.einvoice.issuers.delhi.gst_username' => 'delhi-gst-user',
            'statutory_invoices.einvoice.issuers.delhi.gst_password' => 'delhi-gst-password',
            'statutory_invoices.einvoice.issuers.mumbai.gst_username' => 'mumbai-gst-user',
            'statutory_invoices.einvoice.issuers.mumbai.gst_password' => 'mumbai-gst-password',
        ]);
    }

    private function assertAuthenticateThenGenerate(): void
    {
        $urls = [];
        Http::assertSent(function ($request) use (&$urls): bool {
            $urls[] = $request->url();

            return true;
        });
        $this->assertCount(2, $urls);
        $this->assertStringContainsString('/einvoice/authenticate', $urls[0]);
        $this->assertStringContainsString('/einvoice/type/GENERATE/version/V1_03', $urls[1]);
        $this->assertStringNotContainsString('/oauth/token', implode(' ', $urls));
        $this->assertStringNotContainsString('generate-irn', implode(' ', $urls));
        $this->assertStringNotContainsString('media.radiumbox.com', implode(' ', $urls));
    }

    private function assertSecretsAbsent(mixed $result): void
    {
        $this->assertSecretsAbsentFrom(json_encode($result));
    }

    private function assertSecretsAbsentFrom(?string $dump): void
    {
        $dump = (string) $dump;
        $this->assertStringNotContainsString(self::SECRET, $dump);
        $this->assertStringNotContainsString(self::TOKEN, $dump);
        $this->assertStringNotContainsString('delhi-gst-password', $dump);
        $this->assertStringNotContainsString('mumbai-gst-password', $dump);
    }
}
