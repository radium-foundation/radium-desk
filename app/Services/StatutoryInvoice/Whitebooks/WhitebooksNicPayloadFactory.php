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
                'Addr1' => (string) ($payload->seller['address'] ?? ''),
                'Loc' => (string) ($payload->seller['location'] ?? ''),
                'Pin' => (int) ($payload->seller['pin'] ?? 0),
                'Stcd' => (string) ($payload->seller['state_code'] ?? ''),
            ],
            'BuyerDtls' => [
                'Gstin' => (string) ($payload->buyer['gstin'] ?? ''),
                'LglNm' => (string) ($payload->buyer['legal_name'] ?? ''),
                'Pos' => (string) ($payload->buyer['pos_code'] ?? $payload->buyer['state_code'] ?? ''),
                'Addr1' => (string) ($payload->buyer['address'] ?? ''),
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
                'TotInvVal' => (float) ($payload->values['invoice_value'] ?? 0),
            ],
        ];
    }

    /**
     * Query identity for GETIRNBYDOCDETAILS.
     * WhiteBooks PDF names document no + date only; it does not name query keys.
     * Path is from the public V1_03 catalogue. Exact query names remain UNVERIFIED
     * against WhiteBooks account material. Do not treat as production-ready.
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
