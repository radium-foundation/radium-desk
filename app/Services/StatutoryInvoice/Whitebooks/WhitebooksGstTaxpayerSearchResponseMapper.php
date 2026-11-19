<?php

namespace App\Services\StatutoryInvoice\Whitebooks;

use App\Enums\BuyerGstVerificationStatus;
use App\Services\StatutoryInvoice\BuyerGstin;
use App\Services\StatutoryInvoice\Data\BuyerGstinVerificationResult;
use App\Support\Finance\GstStateCodes;

/**
 * Maps WhiteBooks GET /public/search (Search Taxpayer) responses to Desk verification records.
 * OpenAPI: whitebooksdev openapi/gst/public.json (operation gst-public-search-get).
 */
final class WhitebooksGstTaxpayerSearchResponseMapper
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function map(string $requestedGstin, array $body, ?string $correlationId = null): BuyerGstinVerificationResult
    {
        $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));

        $error = $this->string($body['error'] ?? $body['message'] ?? null);
        if ($error !== null) {
            return new BuyerGstinVerificationResult(
                status: BuyerGstVerificationStatus::Failed,
                gstin: BuyerGstin::normalize($requestedGstin),
                reviewReason: 'provider_error',
                correlationId: $correlationId,
                providerPayloadHash: $hash,
            );
        }

        $payload = is_array($body['data'] ?? null) ? $body['data'] : $body;
        $gstin = BuyerGstin::normalize($this->string($payload['gstin'] ?? null) ?? $requestedGstin);
        if ($gstin === null || ! BuyerGstin::isValid($gstin)) {
            return new BuyerGstinVerificationResult(
                status: BuyerGstVerificationStatus::Failed,
                gstin: BuyerGstin::normalize($requestedGstin),
                reviewReason: 'invalid_gstin_response',
                correlationId: $correlationId,
                providerPayloadHash: $hash,
            );
        }

        $legalName = $this->string($payload['lgnm'] ?? $payload['tradeNam'] ?? null);
        $registrationStatus = $this->string($payload['sts'] ?? null);
        $structured = $this->structuredAddress($payload);

        if ($registrationStatus === null || $legalName === null || $structured === null) {
            return new BuyerGstinVerificationResult(
                status: BuyerGstVerificationStatus::ReviewRequired,
                gstin: $gstin,
                legalName: $legalName,
                registrationStatus: $registrationStatus,
                verifiedAddressStructured: $structured,
                reviewReason: 'incomplete_registration_snapshot',
                correlationId: $correlationId,
                providerPayloadHash: $hash,
            );
        }

        $active = in_array(strtolower($registrationStatus), ['active', 'provisional'], true);
        if (! $active) {
            return new BuyerGstinVerificationResult(
                status: BuyerGstVerificationStatus::ReviewRequired,
                gstin: $gstin,
                legalName: $legalName,
                registrationStatus: $registrationStatus,
                verifiedAddressStructured: $structured,
                reviewReason: 'inactive_registration',
                correlationId: $correlationId,
                providerPayloadHash: $hash,
            );
        }

        return new BuyerGstinVerificationResult(
            status: BuyerGstVerificationStatus::Verified,
            gstin: $gstin,
            legalName: $legalName,
            registrationStatus: $registrationStatus,
            verifiedAddressStructured: $structured,
            correlationId: $correlationId,
            providerPayloadHash: $hash,
        );
    }

    /**
     * @param  array<string, mixed>  $payload
     * @return array<string, string>|null
     */
    private function structuredAddress(array $payload): ?array
    {
        $pradr = is_array($payload['pradr'] ?? null) ? $payload['pradr'] : null;
        $addr = is_array($pradr['addr'] ?? null) ? $pradr['addr'] : null;
        if ($addr === null) {
            return null;
        }

        $parts = array_filter([
            $this->string($addr['bno'] ?? null),
            $this->string($addr['bnm'] ?? null),
            $this->string($addr['flno'] ?? null),
            $this->string($addr['st'] ?? null),
            $this->string($addr['loc'] ?? null),
        ], static fn (?string $v): bool => $v !== null && $v !== '');

        $stateName = GstStateCodes::nameForCode(BuyerGstin::stateCode($this->string($payload['gstin'] ?? '') ?? ''))
            ?? $this->string($addr['stcd'] ?? null);
        $pincode = $this->string($addr['pncd'] ?? null);
        $city = $this->string($addr['dst'] ?? null) ?? $this->string($addr['loc'] ?? null);

        if ($stateName === null || $pincode === null || $city === null || $parts === []) {
            return null;
        }

        return [
            'line1' => implode(', ', $parts),
            'city' => $city,
            'state' => $stateName,
            'pincode' => $pincode,
        ];
    }

    private function string(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
