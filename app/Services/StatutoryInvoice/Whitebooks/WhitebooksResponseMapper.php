<?php

namespace App\Services\StatutoryInvoice\Whitebooks;

use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\EInvoiceIrnGuard;

/**
 * Maps WhiteBooks GENERATE / GETIRN envelopes onto the Phase A result model.
 * Does not fabricate IRN, AckNo, AckDt, or signed QR.
 */
final class WhitebooksResponseMapper
{
    /**
     * @param  array<string, mixed>  $body
     */
    public function mapGenerate(array $body, ?string $correlationId = null): EInvoiceSubmitResult
    {
        $data = $this->data($body);
        $irn = $this->string($data['Irn'] ?? null);
        if (! EInvoiceIrnGuard::isIssuedIrn($irn)) {
            return EInvoiceSubmitResult::permanentFailure(
                'whitebooks',
                $this->safeFailurePayload($body, 'missing_irn'),
                $correlationId,
            );
        }

        return EInvoiceSubmitResult::success(
            provider: 'whitebooks',
            irn: $irn,
            ackNo: $this->string($data['AckNo'] ?? null),
            ackDate: $this->string($data['AckDt'] ?? $data['AckDate'] ?? null),
            signedQr: $this->string($data['SignedQRCode'] ?? $data['SignedQrCode'] ?? null),
            correlationId: $correlationId ?? $this->string($data['AckNo'] ?? null),
            payload: $this->safeSuccessPayload($data, $body),
        );
    }

    /**
     * @param  array<string, mixed>  $body
     */
    public function mapFetch(array $body, ?string $correlationId = null): EInvoiceSubmitResult
    {
        $data = $this->data($body);
        $irn = $this->string($data['Irn'] ?? null);
        if (! EInvoiceIrnGuard::isIssuedIrn($irn)) {
            return EInvoiceSubmitResult::ambiguous(
                'whitebooks',
                $this->safeFailurePayload($body, 'not_found'),
                $correlationId,
            );
        }

        return $this->mapGenerate($body, $correlationId);
    }

    public function authToken(array $body): ?string
    {
        $data = $this->data($body);

        return $this->string($data['AuthToken'] ?? $data['authToken'] ?? null);
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function data(array $body): array
    {
        $data = $body['data'] ?? $body['Data'] ?? $body;
        if (! is_array($data)) {
            return [];
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  array<string, mixed>  $envelope
     * @return array<string, mixed>
     */
    private function safeSuccessPayload(array $data, array $envelope = []): array
    {
        return [
            'Status' => $data['Status'] ?? null,
            'AckNo' => $data['AckNo'] ?? null,
            'AckDt' => $data['AckDt'] ?? $data['AckDate'] ?? null,
            'has_signed_invoice' => $this->string($data['SignedInvoice'] ?? null) !== null,
            'status_cd' => $envelope['status_cd'] ?? null,
            'status_desc' => $this->string($envelope['status_desc'] ?? null),
        ];
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function safeFailurePayload(array $body, string $reason): array
    {
        return [
            'reason' => $reason,
            'status_cd' => $body['status_cd'] ?? $body['Status'] ?? null,
            'status_desc' => $this->string($body['status_desc'] ?? $body['message'] ?? null),
        ];
    }

    private function string(mixed $value): ?string
    {
        if (! is_scalar($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
