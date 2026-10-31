<?php

namespace App\Services\RadiumBox;

use App\Models\StatutoryInvoice;

final class RadiumBoxStatutoryReceiptPublicationPayload
{
    public const SOURCE_TYPE = 'commerce_order';

    public const CHANNEL = 'radiumbox_com';

    /**
     * @return array<string, mixed>
     */
    public static function fromInvoice(StatutoryInvoice $invoice): array
    {
        $issuedAt = $invoice->issued_at;
        $payload = [
            'channel' => self::CHANNEL,
            'source_type' => self::SOURCE_TYPE,
            'source_id' => (string) $invoice->source_id,
            'desk_statutory_invoice_id' => (int) $invoice->id,
            'desk_invoice_number' => (string) $invoice->invoice_number,
            'invoice_total' => number_format((float) $invoice->invoice_value, 2, '.', ''),
        ];

        if ($issuedAt !== null) {
            $payload['issued_at'] = $issuedAt->toIso8601String();
        }

        return $payload;
    }

    public static function httpIdempotencyKey(StatutoryInvoice $invoice): string
    {
        return sprintf(
            'desk-statutory:%s:%s:%s:%d',
            self::CHANNEL,
            self::SOURCE_TYPE,
            (string) $invoice->source_id,
            (int) $invoice->id,
        );
    }
}
