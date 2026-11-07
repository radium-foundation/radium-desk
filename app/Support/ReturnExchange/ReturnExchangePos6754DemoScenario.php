<?php

namespace App\Support\ReturnExchange;

/**
 * Read-only demonstration fixture (P-07-04 verified production facts). No database writes.
 */
final class ReturnExchangePos6754DemoScenario
{
    /**
     * @return array<string, mixed>
     */
    public static function data(): array
    {
        return [
            'title' => 'POS-6754 — customer-requested USB → Type-C exchange (demo)',
            'sale_no' => 'POS-6754',
            'sale_id' => 54,
            'invoice_number' => 'INV-0767321',
            'finance_journal_id' => 31445,
            'return_serial' => 'LN2505154833',
            'replacement_serial' => 'LN2505155964',
            'unaffected_serials' => [
                'LN2505155961',
                'LN2505155965',
                'LN2505155971',
            ],
            'category' => 'Customer-requested product / variant exchange',
            'original_product_note' => 'USB variant (SKU family RBPB1000L1 — verify connector on case)',
            'requested_product_note' => 'Type-C variant (do not assume SKU interchangeability)',
            'price_difference' => 'Pending verification',
            'statutory_treatment' => 'Pending CA approval',
            'finance_reconciliation' => 'Pending approval (journal 31445 unchanged in demo)',
            'checklist_blocked' => [
                'Warranty entitlement not assumed',
                'Statutory document treatment not recorded',
                'Physical return not received',
                'Sale-row exchange orchestration not enabled',
            ],
        ];
    }
}
