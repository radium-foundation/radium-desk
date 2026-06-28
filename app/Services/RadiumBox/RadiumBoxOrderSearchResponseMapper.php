<?php

namespace App\Services\RadiumBox;

use App\Services\RadiumBox\Exceptions\RadiumBoxInvalidResponseException;
use App\Services\RadiumBox\Exceptions\RadiumBoxOrderNotFoundException;

class RadiumBoxOrderSearchResponseMapper
{
    public function map(array $payload): RadiumBoxOrderEnrichment
    {
        $status = $payload['status'] ?? null;

        if ($status === 404) {
            throw new RadiumBoxOrderNotFoundException(
                is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'RadiumBox order not found.',
            );
        }

        if ($status !== 200) {
            throw new RadiumBoxInvalidResponseException(
                is_string($payload['message'] ?? null)
                    ? $payload['message']
                    : 'Unexpected RadiumBox response status.',
            );
        }

        $rdOrder = data_get($payload, 'data.rd_order');

        if (! is_array($rdOrder)) {
            throw new RadiumBoxInvalidResponseException('RadiumBox response is missing rd_order data.');
        }

        return new RadiumBoxOrderEnrichment(
            serialNumber: $this->normalizeSerialNumber(data_get($rdOrder, 'serial_no')),
            deviceModel: $this->normalizeDeviceModel(data_get($rdOrder, 'product_name')),
        );
    }

    private function normalizeSerialNumber(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $serialNumber = strtoupper(trim($value));

        return $serialNumber !== '' ? $serialNumber : null;
    }

    private function normalizeDeviceModel(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $deviceModel = trim($value);

        return $deviceModel !== '' ? $deviceModel : null;
    }
}
