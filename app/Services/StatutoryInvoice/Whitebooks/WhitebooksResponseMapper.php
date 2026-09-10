<?php

namespace App\Services\StatutoryInvoice\Whitebooks;

use App\Services\StatutoryInvoice\Data\EInvoiceSubmitResult;
use App\Services\StatutoryInvoice\EInvoiceIrnGuard;

/**
 * Maps WhiteBooks GENERATE / GETIRN envelopes onto the Phase A result model.
 * Does not fabricate IRN, AckNo, AckDt, or signed QR.
 * Get-IRN HTTP 200 / status_cd=0 / errorCode 2154 is confirmed IRN-not-found (P-196).
 */
final class WhitebooksResponseMapper
{
    /**
     * Production-verified Get-IRN not-found code. Do not treat other codes as not-found.
     */
    public const GET_IRN_NOT_FOUND_ERROR_CODE = '2154';

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
            signedInvoice: $this->string($data['SignedInvoice'] ?? null),
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
        if (EInvoiceIrnGuard::isIssuedIrn($irn)) {
            return $this->mapGenerate($body, $correlationId);
        }

        if ($this->isVerifiedIrnNotFound($body)) {
            return EInvoiceSubmitResult::irnNotFound(
                'whitebooks',
                $this->safeFailurePayload($body, 'irn_not_found', self::GET_IRN_NOT_FOUND_ERROR_CODE),
                $correlationId,
            );
        }

        return EInvoiceSubmitResult::ambiguous(
            'whitebooks',
            $this->safeFailurePayload($body, 'not_found'),
            $correlationId,
        );
    }

    public function authToken(array $body): ?string
    {
        $data = $this->data($body);

        return $this->string($data['AuthToken'] ?? $data['authToken'] ?? null);
    }

    /**
     * P-196 production Get-IRN: HTTP 200, status_cd=0, status_desc errorCode 2154.
     * HTTP 404 is not this signal.
     *
     * @param  array<string, mixed>  $body
     */
    public function isVerifiedIrnNotFound(array $body): bool
    {
        return $this->statusCdIsZero($body) && $this->statusDescHasErrorCode($body, self::GET_IRN_NOT_FOUND_ERROR_CODE);
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function statusCdIsZero(array $body): bool
    {
        $code = $body['status_cd'] ?? null;
        if (is_int($code) || is_float($code)) {
            return (int) $code === 0;
        }
        if (is_string($code)) {
            return trim($code) === '0';
        }

        return false;
    }

    /**
     * @param  array<string, mixed>  $body
     */
    private function statusDescHasErrorCode(array $body, string $code): bool
    {
        foreach ($this->statusDescErrors($body['status_desc'] ?? null) as $entry) {
            $errorCode = $this->string($entry['errorCode'] ?? $entry['ErrorCode'] ?? null);
            if ($errorCode === $code) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function statusDescErrors(mixed $statusDesc): array
    {
        if (is_string($statusDesc)) {
            $decoded = json_decode($statusDesc, true);
            if (! is_array($decoded)) {
                return [];
            }
            $statusDesc = $decoded;
        }
        if (! is_array($statusDesc)) {
            return [];
        }

        $entries = [];
        $items = array_is_list($statusDesc) ? $statusDesc : [$statusDesc];
        foreach ($items as $item) {
            if (is_array($item)) {
                $entries[] = $item;
            }
        }

        return $entries;
    }

    /**
     * @param  array<string, mixed>  $body
     * @return array<string, mixed>
     */
    private function data(array $body): array
    {
        if (! array_key_exists('data', $body) && ! array_key_exists('Data', $body)) {
            return [];
        }
        $data = $body['data'] ?? $body['Data'];
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
    private function safeFailurePayload(array $body, string $reason, ?string $errorCode = null): array
    {
        return [
            'reason' => $reason,
            'status_cd' => $body['status_cd'] ?? $body['Status'] ?? null,
            'status_desc' => $this->string($body['status_desc'] ?? $body['message'] ?? null),
            'error_code' => $errorCode,
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
