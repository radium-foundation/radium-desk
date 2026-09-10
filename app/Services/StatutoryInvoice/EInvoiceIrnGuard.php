<?php

namespace App\Services\StatutoryInvoice;

use App\Enums\EInvoiceRecordStatus;
use App\Models\EInvoiceRecord;

final class EInvoiceIrnGuard
{
    /**
     * @var list<string>
     */
    private const ISSUED_IRN_FIELDS = ['irn', 'ack_no', 'ack_date', 'signed_qr'];

    public static function isIssuedIrn(mixed $irn): bool
    {
        return is_string($irn) && trim($irn) !== '';
    }

    public static function recordHasIssuedIrn(?EInvoiceRecord $record): bool
    {
        return $record !== null && self::isIssuedIrn($record->irn);
    }

    public static function mustNotResubmit(?EInvoiceRecord $record): bool
    {
        if ($record === null) {
            return false;
        }

        if (self::recordHasIssuedIrn($record)) {
            return true;
        }

        $status = $record->status;

        return $status === EInvoiceRecordStatus::Submitted->value
            || $status === EInvoiceRecordStatus::PermanentFailure->value;
    }

    public static function mustRecoverInsteadOfGenerate(?EInvoiceRecord $record): bool
    {
        return $record !== null
            && $record->status === EInvoiceRecordStatus::Ambiguous->value
            && ! self::recordHasIssuedIrn($record);
    }

    /**
     * Drop blank IRN/ack/QR keys so updateOrCreate cannot null an issued IRN.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    public static function attributesWithoutClearingIssuedIrn(array $attributes): array
    {
        foreach (self::ISSUED_IRN_FIELDS as $field) {
            if (! array_key_exists($field, $attributes)) {
                continue;
            }

            $value = $attributes[$field];
            if ($field === 'ack_date') {
                if ($value === null || $value === '') {
                    unset($attributes[$field]);
                }

                continue;
            }

            if (! is_string($value) || trim($value) === '') {
                unset($attributes[$field]);
            }
        }

        return $attributes;
    }
}
