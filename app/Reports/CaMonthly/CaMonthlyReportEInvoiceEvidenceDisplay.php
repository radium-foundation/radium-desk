<?php

namespace App\Reports\CaMonthly;

use App\Enums\EInvoiceRecordStatus;
use App\Models\EInvoiceRecord;
use App\Models\StatutoryInvoice;

/**
 * Maps persisted e-invoice records to CA Sales Report evidence columns.
 *
 * Uses stored provider payloads only — does not verify GST registration status.
 */
final class CaMonthlyReportEInvoiceEvidenceDisplay
{
    public const STATUS_IRN_GENERATED = 'IRN Generated';

    public const STATUS_PROVIDER_REJECTION = 'IRN Generation Failed — Provider Rejection';

    public const STATUS_SKIPPED = 'IRN Generation Skipped';

    public const STATUS_NO_RECORD = 'Not Available / No E-Invoice Record';

    public const STATUS_TEMPORARY_FAILURE = 'IRN Generation Failed — Temporary Failure';

    public const STATUS_AMBIGUOUS = 'IRN Generation Attempted — Response Unavailable';

    public const STATUS_IN_PROGRESS = 'IRN Generation In Progress';

    public const REASON_NO_RECORD = 'Not available — no e-invoice record';

    public static function generationStatus(StatutoryInvoice $invoice): string
    {
        $record = $invoice->eInvoiceRecord;
        if ($record === null) {
            return self::STATUS_NO_RECORD;
        }

        if (self::hasIssuedIrn($record)) {
            return self::STATUS_IRN_GENERATED;
        }

        return match (self::normalizedStatus($record)) {
            EInvoiceRecordStatus::Skipped->value => self::STATUS_SKIPPED,
            EInvoiceRecordStatus::PermanentFailure->value,
            EInvoiceRecordStatus::Failed->value => self::STATUS_PROVIDER_REJECTION,
            EInvoiceRecordStatus::TemporaryFailure->value => self::STATUS_TEMPORARY_FAILURE,
            EInvoiceRecordStatus::Ambiguous->value => self::STATUS_AMBIGUOUS,
            EInvoiceRecordStatus::Processing->value,
            EInvoiceRecordStatus::Queued->value => self::STATUS_IN_PROGRESS,
            EInvoiceRecordStatus::IrnNotFound->value => self::STATUS_AMBIGUOUS,
            default => self::STATUS_AMBIGUOUS,
        };
    }

    public static function responseCode(StatutoryInvoice $invoice): string
    {
        $record = $invoice->eInvoiceRecord;
        if ($record === null || self::hasIssuedIrn($record)) {
            return '';
        }

        if (self::normalizedStatus($record) === EInvoiceRecordStatus::Skipped->value) {
            return '';
        }

        return self::extractProviderCodes($record->response_payload);
    }

    public static function responseReason(StatutoryInvoice $invoice): string
    {
        $record = $invoice->eInvoiceRecord;
        if ($record === null) {
            return self::REASON_NO_RECORD;
        }

        if (self::hasIssuedIrn($record)) {
            return '';
        }

        if (self::normalizedStatus($record) === EInvoiceRecordStatus::Skipped->value) {
            return self::formatSkipReason($record->response_payload);
        }

        $providerMessages = self::extractProviderMessages($record->response_payload);
        if ($providerMessages !== '') {
            return $providerMessages;
        }

        $internalReason = self::extractInternalReason($record->response_payload);
        if ($internalReason !== '') {
            return $internalReason;
        }

        return '';
    }

    private static function hasIssuedIrn(EInvoiceRecord $record): bool
    {
        return is_string($record->irn) && trim($record->irn) !== '';
    }

    private static function normalizedStatus(EInvoiceRecord $record): string
    {
        $status = $record->status;

        if ($status instanceof EInvoiceRecordStatus) {
            return $status->value;
        }

        return trim((string) $status);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private static function extractProviderCodes(?array $payload): string
    {
        $entries = self::statusDescEntries($payload);
        $codes = [];
        foreach ($entries as $entry) {
            $code = self::normalizeScalar($entry['errorCode'] ?? $entry['error_code'] ?? null);
            if ($code !== null) {
                $codes[$code] = true;
            }
        }

        if ($codes === []) {
            $fallback = self::normalizeScalar($payload['error_code'] ?? $payload['payload']['error_code'] ?? null);
            if ($fallback !== null) {
                $codes[$fallback] = true;
            }
        }

        return implode('; ', array_keys($codes));
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private static function extractProviderMessages(?array $payload): string
    {
        $entries = self::statusDescEntries($payload);
        $messages = [];
        foreach ($entries as $entry) {
            $message = self::normalizeScalar($entry['errorMessage'] ?? $entry['error_message'] ?? null);
            if ($message !== null) {
                $messages[] = $message;
            }
        }

        return implode('; ', $messages);
    }

    /**
     * @param  array<string, mixed>|null  $payload
     * @return list<array<string, mixed>>
     */
    private static function statusDescEntries(?array $payload): array
    {
        if (! is_array($payload)) {
            return [];
        }

        $statusDesc = $payload['payload']['status_desc'] ?? $payload['status_desc'] ?? null;

        return self::decodeStatusDesc($statusDesc);
    }

    /**
     * @return list<array<string, mixed>>
     */
    private static function decodeStatusDesc(mixed $statusDesc): array
    {
        if (is_array($statusDesc)) {
            return self::normalizeEntryList($statusDesc);
        }

        if (! is_string($statusDesc) || trim($statusDesc) === '') {
            return [];
        }

        $decoded = json_decode($statusDesc, true);
        if (! is_array($decoded)) {
            return [];
        }

        return self::normalizeEntryList($decoded);
    }

    /**
     * @param  array<int|string, mixed>  $entries
     * @return list<array<string, mixed>>
     */
    private static function normalizeEntryList(array $entries): array
    {
        if ($entries === []) {
            return [];
        }

        if (array_is_list($entries)) {
            $normalized = [];
            foreach ($entries as $entry) {
                if (is_array($entry)) {
                    $normalized[] = $entry;
                }
            }

            return $normalized;
        }

        return [$entries];
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private static function formatSkipReason(?array $payload): string
    {
        if (! is_array($payload)) {
            return '';
        }

        $skipReason = self::normalizeScalar($payload['skip_reason'] ?? null);
        $gaps = $payload['gaps'] ?? null;
        $gapList = [];
        if (is_array($gaps)) {
            foreach ($gaps as $gap) {
                $normalized = self::normalizeScalar($gap);
                if ($normalized !== null) {
                    $gapList[] = $normalized;
                }
            }
            sort($gapList);
        }

        if ($skipReason === null && $gapList === []) {
            return '';
        }

        if ($gapList === []) {
            return (string) $skipReason;
        }

        $gapText = implode(', ', $gapList);

        return $skipReason === null
            ? $gapText
            : $skipReason.' — '.$gapText;
    }

    /**
     * @param  array<string, mixed>|null  $payload
     */
    private static function extractInternalReason(?array $payload): string
    {
        if (! is_array($payload)) {
            return '';
        }

        $candidates = [
            $payload['payload']['reason'] ?? null,
            $payload['reason'] ?? null,
        ];

        foreach ($candidates as $candidate) {
            $normalized = self::normalizeScalar($candidate);
            if ($normalized !== null) {
                return $normalized;
            }
        }

        return '';
    }

    private static function normalizeScalar(mixed $value): ?string
    {
        if (! is_string($value) && ! is_int($value) && ! is_float($value)) {
            return null;
        }

        $trimmed = trim((string) $value);

        return $trimmed === '' ? null : $trimmed;
    }
}
