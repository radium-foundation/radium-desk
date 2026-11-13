<?php

namespace App\Services\Oem\Morpho;

use App\Services\Oem\Morpho\Data\MorphoDeviceRegistrationResult;
use App\Services\Oem\Morpho\Data\MorphoOrderExpiryResult;
use App\Services\Oem\Morpho\Data\MorphoOutcome;
use App\Services\Oem\Morpho\Data\MorphoRawResponse;
use App\Services\Oem\Morpho\Data\MorphoSerialValidationResult;

final class MorphoResponseParser
{
    public function parseDeviceValidator(string $serial, MorphoRawResponse $response): MorphoSerialValidationResult
    {
        if ($response->timedOut) {
            return new MorphoSerialValidationResult(
                outcome: MorphoOutcome::ServiceUnavailable,
                normalizedSerial: $serial,
                message: 'OEM validation request timed out.',
            );
        }

        if ($response->statusCode === 401 || $response->statusCode === 403) {
            return new MorphoSerialValidationResult(
                outcome: MorphoOutcome::AuthenticationUnavailable,
                normalizedSerial: $serial,
                message: 'OEM authentication rejected the request.',
            );
        }

        if (! $response->succeeded()) {
            return new MorphoSerialValidationResult(
                outcome: MorphoOutcome::ServiceUnavailable,
                normalizedSerial: $serial,
                message: 'OEM validation service returned HTTP '.$response->statusCode.'.',
            );
        }

        $content = $response->json['content'] ?? null;

        if (! is_array($content) || ! isset($content[0]) || ! is_array($content[0])) {
            return new MorphoSerialValidationResult(
                outcome: MorphoOutcome::UnknownError,
                normalizedSerial: $serial,
                message: 'Unexpected OEM validation response shape.',
            );
        }

        $code = (string) ($content[0]['responseCode'] ?? '');

        return match ($code) {
            '000' => new MorphoSerialValidationResult(
                outcome: MorphoOutcome::Valid,
                normalizedSerial: $serial,
                oemResponseCode: $code,
            ),
            '002' => new MorphoSerialValidationResult(
                outcome: MorphoOutcome::Valid,
                normalizedSerial: $serial,
                oemResponseCode: $code,
                message: 'Device reported as whitelisted in OEM validation flow.',
            ),
            default => new MorphoSerialValidationResult(
                outcome: MorphoOutcome::Invalid,
                normalizedSerial: $serial,
                oemResponseCode: $code !== '' ? $code : null,
            ),
        };
    }

    public function parseRegistrationStatus(string $serial, MorphoRawResponse $response): MorphoDeviceRegistrationResult
    {
        if ($response->timedOut || ! $response->succeeded()) {
            return new MorphoDeviceRegistrationResult(
                outcome: MorphoOutcome::ServiceUnavailable,
                normalizedSerial: $serial,
            );
        }

        $rows = $response->json['content'][0] ?? null;

        if (! is_array($rows) || $rows === []) {
            return new MorphoDeviceRegistrationResult(
                outcome: MorphoOutcome::NotRegistered,
                normalizedSerial: $serial,
            );
        }

        $first = $rows[0];

        if (! is_array($first)) {
            return new MorphoDeviceRegistrationResult(
                outcome: MorphoOutcome::UnknownError,
                normalizedSerial: $serial,
            );
        }

        $massage = (string) ($first['Massage'] ?? '');

        $outcome = match ($massage) {
            '000' => MorphoOutcome::Whitelisted,
            '001' => MorphoOutcome::Registered,
            '002' => MorphoOutcome::NotRegistered,
            default => MorphoOutcome::UnknownError,
        };

        return new MorphoDeviceRegistrationResult(
            outcome: $outcome,
            normalizedSerial: $serial,
            deviceType: isset($first['DeviceType']) ? (string) $first['DeviceType'] : null,
            statusCode: $massage !== '' ? $massage : null,
            statusMessage: $this->registrationMessageForCode($massage),
        );
    }

    public function parseOrderExpiry(string $serial, MorphoRawResponse $response): MorphoOrderExpiryResult
    {
        if ($response->timedOut || ! $response->succeeded()) {
            return new MorphoOrderExpiryResult(
                outcome: MorphoOutcome::ServiceUnavailable,
                normalizedSerial: $serial,
            );
        }

        $rows = $response->json['content'][0] ?? null;

        if (! is_array($rows) || $rows === []) {
            return new MorphoOrderExpiryResult(
                outcome: MorphoOutcome::ExpiryMissing,
                normalizedSerial: $serial,
                message: 'No OEM order or expiry rows returned.',
            );
        }

        $parsedRows = [];

        foreach ($rows as $row) {
            if (! is_array($row)) {
                continue;
            }

            $parsedRows[] = [
                'order_number' => isset($row['OrdersNumber']) ? (string) $row['OrdersNumber'] : null,
                'serial' => isset($row['SerialNo']) ? (string) $row['SerialNo'] : null,
                'whitelist_date' => isset($row['WLDateTime']) ? (string) $row['WLDateTime'] : null,
                'expiry_date' => isset($row['ExpiryDate']) ? (string) $row['ExpiryDate'] : null,
                'years' => isset($row['NumberofYear']) ? (string) $row['NumberofYear'] : null,
            ];
        }

        if ($parsedRows === []) {
            return new MorphoOrderExpiryResult(
                outcome: MorphoOutcome::UnknownError,
                normalizedSerial: $serial,
            );
        }

        $hasExpiry = collect($parsedRows)->contains(
            fn (array $row): bool => ($row['expiry_date'] ?? '') !== '',
        );

        return new MorphoOrderExpiryResult(
            outcome: $hasExpiry ? MorphoOutcome::ExpiryAvailable : MorphoOutcome::ExpiryMissing,
            normalizedSerial: $serial,
            rows: $parsedRows,
        );
    }

    private function registrationMessageForCode(string $code): ?string
    {
        return match ($code) {
            '000' => 'Device already whitelisted',
            '001' => 'Device is valid but not whitelisted',
            '002' => 'Device is not found',
            default => null,
        };
    }
}
