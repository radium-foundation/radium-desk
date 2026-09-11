<?php

namespace App\Services\StatutoryInvoice\Whitebooks;

use App\Services\StatutoryInvoice\Data\EInvoiceIrnPayload;

/**
 * NIC 1.1 JSON for the verified WhiteBooks GENERATE body (plaintext, no SEK wrap).
 * Omits Admin dummy EWB / export / vehicle / batch blocks.
 */
final class WhitebooksNicPayloadFactory
{
    /**
     * @return array<string, mixed>|null
     */
    public function generateBody(EInvoiceIrnPayload $payload): ?array
    {
        if (! $payload->isSubmittable()) {
            return null;
        }

        $items = [];
        foreach ($payload->items as $item) {
            $qty = (float) ($item['qty'] ?? 0);
            $taxable = (float) ($item['taxable_value'] ?? 0);
            $cgst = (float) ($item['cgst'] ?? 0);
            $sgst = (float) ($item['sgst'] ?? 0);
            $igst = (float) ($item['igst'] ?? 0);
            $items[] = [
                'SlNo' => (string) ($item['line_no'] ?? count($items) + 1),
                'IsServc' => (string) ($item['is_servc'] ?? ''),
                'HsnCd' => (string) ($item['hsn_sac'] ?? ''),
                'PrdDesc' => (string) ($item['description'] ?? ''),
                'Qty' => $qty,
                'Unit' => (string) ($item['unit'] ?? ''),
                // NIC 1.1 UnitPrice is the tax-exclusive rate. TotAmt = UnitPrice × Qty
                // before discount; AssAmt is stored taxable. Desk unit_price is GST-inclusive
                // selling price and is not copied here.
                'UnitPrice' => $this->assessableUnitPrice($qty, $taxable),
                'TotAmt' => $taxable,
                'AssAmt' => $taxable,
                'GstRt' => (float) ($item['gst_percentage'] ?? 0),
                'IgstAmt' => $igst,
                'CgstAmt' => $cgst,
                'SgstAmt' => $sgst,
                'TotItemVal' => (float) ($item['line_total'] ?? ($taxable + $cgst + $sgst + $igst)),
            ];
        }

        return [
            'Version' => '1.1',
            'TranDtls' => [
                'TaxSch' => 'GST',
                'SupTyp' => $payload->supplyType,
                'RegRev' => 'N',
                'IgstOnIntra' => 'N',
            ],
            'DocDtls' => [
                'Typ' => (string) ($payload->document['type'] ?? ''),
                'No' => (string) ($payload->document['number'] ?? ''),
                'Dt' => (string) ($payload->document['date'] ?? ''),
            ],
            'SellerDtls' => [
                'Gstin' => (string) ($payload->seller['gstin'] ?? ''),
                'LglNm' => (string) ($payload->seller['legal_name'] ?? ''),
                ...$this->nicAddressFromPayload($payload->seller),
                'Loc' => (string) ($payload->seller['location'] ?? ''),
                'Pin' => (int) ($payload->seller['pin'] ?? 0),
                'Stcd' => (string) ($payload->seller['state_code'] ?? ''),
            ],
            'BuyerDtls' => [
                'Gstin' => (string) ($payload->buyer['gstin'] ?? ''),
                'LglNm' => (string) ($payload->buyer['legal_name'] ?? ''),
                'Pos' => (string) ($payload->buyer['pos_code'] ?? $payload->buyer['state_code'] ?? ''),
                ...$this->nicAddressFromPayload($payload->buyer),
                'Loc' => (string) ($payload->buyer['location'] ?? ''),
                'Pin' => (int) ($payload->buyer['pin'] ?? 0),
                'Stcd' => (string) ($payload->buyer['state_code'] ?? ''),
            ],
            'ItemList' => $items,
            'ValDtls' => [
                'AssVal' => (float) ($payload->values['taxable_value'] ?? 0),
                'CgstVal' => (float) ($payload->values['cgst'] ?? 0),
                'SgstVal' => (float) ($payload->values['sgst'] ?? 0),
                'IgstVal' => (float) ($payload->values['igst'] ?? 0),
                'RndOffAmt' => (float) ($payload->values['rounding'] ?? 0),
                'TotInvVal' => (float) ($payload->values['invoice_value'] ?? 0),
            ],
        ];
    }

    /**
     * Document identity for GETIRNBYDOCDETAILS (P-194).
     * param1 is the document type; docnum/docdate are request headers (dd/MM/YYYY).
     *
     * @return array{doctype: string, docnum: string, docdate: string}|null
     */
    public function documentLookup(EInvoiceIrnPayload $payload): ?array
    {
        $type = trim((string) ($payload->document['type'] ?? ''));
        $number = trim((string) ($payload->document['number'] ?? ''));
        $date = trim((string) ($payload->document['date'] ?? ''));
        if ($type === '' || $number === '' || $date === '') {
            return null;
        }

        return [
            'doctype' => $type,
            'docnum' => $number,
            'docdate' => $date,
        ];
    }

    /**
     * @param  array<string, mixed>  $party
     * @return array{Addr1: string, Addr2?: string}
     */
    private function nicAddressFromPayload(array $party): array
    {
        $addr1 = trim((string) ($party['address_addr1'] ?? ''));
        $addr2 = trim((string) ($party['address_addr2'] ?? ''));
        if ($addr1 !== '') {
            $lines = ['Addr1' => $addr1];
            if ($addr2 !== '') {
                $lines['Addr2'] = $addr2;
            }

            return $lines;
        }

        return $this->nicAddressLines((string) ($party['address'] ?? ''));
    }

    /**
     * NIC Addr1 is 1–100 characters. Remainder maps to Addr2 (max 100).
     * Does not invent address text or truncate past 200 characters.
     *
     * @return array{Addr1: string, Addr2?: string}
     */
    private function nicAddressLines(string $address): array
    {
        $address = trim($address);
        if (strlen($address) <= 100) {
            return ['Addr1' => $address];
        }

        $break = $this->addressBreakOffset($address, 100);
        $addr1 = rtrim(substr($address, 0, $break), " \t,");
        $addr2 = ltrim(substr($address, $break), " \t,");
        if ($addr1 === '') {
            $addr1 = substr($address, 0, 100);
            $addr2 = ltrim(substr($address, 100), " \t,");
        }

        $lines = ['Addr1' => $addr1];
        if ($addr2 !== '') {
            $lines['Addr2'] = $addr2;
        }

        return $lines;
    }

    private function addressBreakOffset(string $address, int $max): int
    {
        $window = substr($address, 0, $max);
        $comma = strrpos($window, ',');
        if ($comma !== false && $comma >= 40) {
            return $comma + 1;
        }
        $space = strrpos($window, ' ');
        if ($space !== false && $space >= 40) {
            return $space + 1;
        }

        return $max;
    }

    /**
     * NIC UnitPrice × Qty = AssAmt when discount is 0.
     * Does not use the GST-inclusive stored selling price.
     */
    private function assessableUnitPrice(float $qty, float $taxable): float
    {
        if ($qty <= 0) {
            return 0.0;
        }

        return round($taxable / $qty, 2);
    }
}
