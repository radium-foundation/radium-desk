<?php

namespace App\Services\Oem\Morpho;

use App\Services\Oem\Morpho\Contracts\MorphoAuthenticationProvider;
use App\Services\Oem\Morpho\Contracts\MorphoSerialLookupPort;
use App\Services\Oem\Morpho\Contracts\MorphoTransport;
use App\Services\Oem\Morpho\Data\MorphoDeviceRegistrationResult;
use App\Services\Oem\Morpho\Data\MorphoOrderExpiryResult;
use App\Services\Oem\Morpho\Data\MorphoOutcome;
use App\Services\Oem\Morpho\Data\MorphoSerialValidationResult;
use Illuminate\Support\Facades\Log;

final class MorphoOtbsAdapterService implements MorphoSerialLookupPort
{
    private const DEVICE_VALIDATOR_PATH = '/apiconnector/DeviceValidator/';

    private const REGISTRATION_STATUS_PATH = '/apiconnector/GetRDServiceDeviceStatus';

    private const ORDER_EXPIRY_PATH = '/apiconnector/RDServiceShopifyOrderData';

    public function __construct(
        private readonly MorphoTransport $transport,
        private readonly MorphoAuthenticationProvider $authenticationProvider,
        private readonly MorphoSerialInputValidator $serialInputValidator,
        private readonly MorphoResponseParser $responseParser,
        private readonly MorphoAdapterConfiguration $configuration,
    ) {}

    public function validateSerial(string $serial): MorphoSerialValidationResult
    {
        $normalized = $this->serialInputValidator->normalize($serial);

        if ($normalized['ok'] !== true) {
            return MorphoSerialValidationResult::invalidFormat(
                $normalized['serial'],
                $normalized['message'],
            );
        }

        $canonical = $normalized['serial'];

        if (! $this->authenticationProvider->isAvailable()) {
            return new MorphoSerialValidationResult(
                outcome: MorphoOutcome::AuthenticationUnavailable,
                normalizedSerial: $canonical,
                message: 'Morpho authentication is not configured.',
            );
        }

        $path = self::DEVICE_VALIDATOR_PATH.$canonical;
        $headers = $this->authenticationProvider->headers();

        $this->logTransportRequest('GET', $path, $canonical, $headers);

        $response = $this->transport->get($path, $headers);

        return $this->responseParser->parseDeviceValidator($canonical, $response);
    }

    public function getDeviceRegistrationStatus(string $serial): MorphoDeviceRegistrationResult
    {
        $normalized = $this->serialInputValidator->normalize($serial);

        if ($normalized['ok'] !== true) {
            return new MorphoDeviceRegistrationResult(
                outcome: MorphoOutcome::Invalid,
                normalizedSerial: $normalized['serial'],
                statusMessage: $normalized['message'],
            );
        }

        $canonical = $normalized['serial'];

        if (! $this->authenticationProvider->isAvailable()) {
            return new MorphoDeviceRegistrationResult(
                outcome: MorphoOutcome::AuthenticationUnavailable,
                normalizedSerial: $canonical,
            );
        }

        $payload = [
            'serialNo' => $canonical,
            'modelId' => 'MSO1300E3L0SW',
            'complianceLevel' => 'L0',
        ];

        $headers = $this->authenticationProvider->headers();
        $this->logTransportRequest('POST', self::REGISTRATION_STATUS_PATH, $canonical, $headers);

        $response = $this->transport->post(self::REGISTRATION_STATUS_PATH, $payload, $headers);

        return $this->responseParser->parseRegistrationStatus($canonical, $response);
    }

    public function getOrderAndExpiryDetails(string $serial): MorphoOrderExpiryResult
    {
        $normalized = $this->serialInputValidator->normalize($serial);

        if ($normalized['ok'] !== true) {
            return new MorphoOrderExpiryResult(
                outcome: MorphoOutcome::Invalid,
                normalizedSerial: $normalized['serial'],
                message: $normalized['message'],
            );
        }

        $canonical = $normalized['serial'];

        if (! $this->authenticationProvider->isAvailable()) {
            return new MorphoOrderExpiryResult(
                outcome: MorphoOutcome::AuthenticationUnavailable,
                normalizedSerial: $canonical,
            );
        }

        $payload = [
            'serialnumbers' => '{"serialnumbers":"'.$canonical.'"}',
        ];

        $headers = $this->authenticationProvider->headers();
        $this->logTransportRequest('POST', self::ORDER_EXPIRY_PATH, $canonical, $headers);

        $response = $this->transport->post(self::ORDER_EXPIRY_PATH, $payload, $headers);

        return $this->responseParser->parseOrderExpiry($canonical, $response);
    }

    /**
     * @param  array<string, string>  $headers
     */
    private function logTransportRequest(string $method, string $path, string $serial, array $headers): void
    {
        if (! config('morpho_otbs_adapter.logging.redact_serials', true)) {
            Log::debug('Morpho adapter transport request', compact('method', 'path', 'serial', 'headers'));

            return;
        }

        Log::debug('Morpho adapter transport request', [
            'method' => $method,
            'path' => $path,
            'serial' => MorphoLogRedactor::redactSerial($serial),
            'headers' => MorphoLogRedactor::redactHeaders($headers),
            'driver' => $this->configuration->usesMockDriver() ? 'mock' : 'live',
        ]);
    }
}
