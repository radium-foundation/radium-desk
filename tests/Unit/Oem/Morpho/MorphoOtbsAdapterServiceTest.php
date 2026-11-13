<?php

namespace Tests\Unit\Oem\Morpho;

use App\Services\Oem\Morpho\Auth\ConfiguredMorphoAuthenticationProvider;
use App\Services\Oem\Morpho\Auth\MockMorphoAuthenticationProvider;
use App\Services\Oem\Morpho\Contracts\MorphoSerialLookupPort;
use App\Services\Oem\Morpho\Data\MorphoOutcome;
use App\Services\Oem\Morpho\MorphoAdapterConfiguration;
use App\Services\Oem\Morpho\MorphoOtbsAdapterService;
use App\Services\Oem\Morpho\MorphoResponseParser;
use App\Services\Oem\Morpho\MorphoSerialInputValidator;
use App\Services\Oem\Morpho\Transport\MockMorphoTransport;
use Illuminate\Support\Facades\Config;
use Tests\TestCase;

class MorphoOtbsAdapterServiceTest extends TestCase
{
    private function adapter(?MockMorphoTransport $transport = null): MorphoOtbsAdapterService
    {
        return new MorphoOtbsAdapterService(
            transport: $transport ?? new MockMorphoTransport,
            authenticationProvider: new MockMorphoAuthenticationProvider,
            serialInputValidator: new MorphoSerialInputValidator,
            responseParser: new MorphoResponseParser,
            configuration: MorphoAdapterConfiguration::fromConfig(),
        );
    }

    public function test_validate_serial_returns_valid_for_synthetic_success_fixture(): void
    {
        $result = $this->adapter()->validateSerial('0000i000001');

        $this->assertSame(MorphoOutcome::Valid, $result->outcome);
        $this->assertSame('0000I000001', $result->normalizedSerial);
        $this->assertSame('000', $result->oemResponseCode);
    }

    public function test_validate_serial_returns_invalid_for_synthetic_reject_fixture(): void
    {
        $result = $this->adapter()->validateSerial('0000I000099');

        $this->assertSame(MorphoOutcome::Invalid, $result->outcome);
    }

    public function test_validate_serial_rejects_bad_format_without_transport_call(): void
    {
        $transport = new MockMorphoTransport;
        $transport->registerGet('*', fn () => $this->fail('Transport should not be called for invalid format.'));

        $result = $this->adapter($transport)->validateSerial('SHORT');

        $this->assertSame(MorphoOutcome::Invalid, $result->outcome);
    }

    public function test_validate_serial_maps_authentication_failure(): void
    {
        $result = $this->adapter()->validateSerial('0000I000401');

        $this->assertSame(MorphoOutcome::AuthenticationUnavailable, $result->outcome);
    }

    public function test_validate_serial_maps_timeout(): void
    {
        $result = $this->adapter()->validateSerial('0000I000502');

        $this->assertSame(MorphoOutcome::ServiceUnavailable, $result->outcome);
    }

    public function test_validate_serial_maps_malformed_payload(): void
    {
        $result = $this->adapter()->validateSerial('0000I000888');

        $this->assertSame(MorphoOutcome::UnknownError, $result->outcome);
    }

    public function test_registration_status_whitelisted_fixture(): void
    {
        $result = $this->adapter()->getDeviceRegistrationStatus('0000I000002');

        $this->assertSame(MorphoOutcome::Whitelisted, $result->outcome);
        $this->assertSame('Device already whitelisted', $result->statusMessage);
    }

    public function test_registration_status_not_found_fixture(): void
    {
        $result = $this->adapter()->getDeviceRegistrationStatus('0000I000003');

        $this->assertSame(MorphoOutcome::NotRegistered, $result->outcome);
    }

    public function test_order_expiry_returns_rows_for_synthetic_serial(): void
    {
        $result = $this->adapter()->getOrderAndExpiryDetails('0000I000004');

        $this->assertSame(MorphoOutcome::ExpiryAvailable, $result->outcome);
        $this->assertCount(1, $result->rows);
        $this->assertSame('2026-01-01', $result->rows[0]['expiry_date']);
    }

    public function test_order_expiry_missing_when_no_rows(): void
    {
        $result = $this->adapter()->getOrderAndExpiryDetails('0000I000005');

        $this->assertSame(MorphoOutcome::ExpiryMissing, $result->outcome);
    }

    public function test_container_binds_mock_driver_by_default(): void
    {
        $this->assertSame('mock', config('morpho_otbs_adapter.driver'));

        $port = $this->app->make(MorphoSerialLookupPort::class);

        $this->assertInstanceOf(MorphoOtbsAdapterService::class, $port);
    }

    public function test_live_driver_without_credentials_fails_closed_on_auth_provider(): void
    {
        Config::set('morpho_otbs_adapter.driver', 'live');
        Config::set('morpho_otbs_adapter.live.enabled', false);
        Config::set('morpho_otbs_adapter.live.authorization', '');
        Config::set('morpho_otbs_adapter.live.security_stamp', '');

        $configuration = MorphoAdapterConfiguration::fromConfig();
        $service = new MorphoOtbsAdapterService(
            transport: new MockMorphoTransport,
            authenticationProvider: new ConfiguredMorphoAuthenticationProvider($configuration),
            serialInputValidator: new MorphoSerialInputValidator,
            responseParser: new MorphoResponseParser,
            configuration: $configuration,
        );

        $result = $service->validateSerial('0000I000001');

        $this->assertSame(MorphoOutcome::AuthenticationUnavailable, $result->outcome);
    }

    public function test_mock_auth_headers_are_marked_non_production(): void
    {
        $provider = new MockMorphoAuthenticationProvider;

        $this->assertStringContainsString('NOT-FOR-PRODUCTION', $provider->headers()['Authorization']);
    }
}
