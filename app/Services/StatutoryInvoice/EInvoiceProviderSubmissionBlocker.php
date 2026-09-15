<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Models\EInvoiceRecord;

/**
 * Maps persisted provider submission failures to agent-safe readiness reasons.
 * Does not expose provider payloads or credentials.
 */
final class EInvoiceProviderSubmissionBlocker
{
    public function blockedReason(?EInvoiceRecord $record): ?string
    {
        if ($record === null || ! $this->isPermanentFailure($record)) {
            return null;
        }

        $code = $this->errorCode($record);
        if ($code === null) {
            return null;
        }

        return match ($code) {
            '3028' => 'gstin_requires_verification',
            '3038' => 'buyer_pin_requires_verification',
            '3039' => 'buyer_pin_gstin_state_mismatch',
            default => null,
        };
    }

    private function isPermanentFailure(EInvoiceRecord $record): bool
    {
        $status = $record->status;

        return $status === EInvoiceRecordStatus::PermanentFailure
            || $status === EInvoiceRecordStatus::PermanentFailure->value;
    }

    private function errorCode(EInvoiceRecord $record): ?string
    {
        $payload = $record->response_payload;
        if (! is_array($payload)) {
            return null;
        }

        $candidates = [
            $payload['error_code'] ?? null,
            $payload['payload']['error_code'] ?? null,
        ];

        $statusDesc = $payload['payload']['status_desc'] ?? $payload['status_desc'] ?? null;
        if (is_string($statusDesc)) {
            $candidates[] = $this->codeFromStatusDesc($statusDesc);
        }

        foreach ($candidates as $candidate) {
            $normalized = $this->normalizeCode($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return null;
    }

    private function codeFromStatusDesc(string $statusDesc): ?string
    {
        if (! preg_match('/"errorCode"\s*:\s*"(\d{4})"/', $statusDesc, $matches)) {
            return null;
        }

        return $matches[1];
    }

    private function normalizeCode(mixed $code): ?string
    {
        if (! is_string($code) && ! is_int($code)) {
            return null;
        }

        $value = trim((string) $code);

        return $value !== '' ? $value : null;
    }
}
