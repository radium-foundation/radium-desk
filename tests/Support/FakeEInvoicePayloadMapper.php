<?php

namespace Tests\Support;

use App\Models\StatutoryInvoice;
use App\Services\StatutoryInvoice\Data\EInvoiceIrnPayload;
use App\Services\StatutoryInvoice\EInvoiceIrnPayloadMapper;
use App\Services\StatutoryInvoice\EInvoiceServiceClassification;
use App\Services\StatutoryInvoice\EInvoiceUqcMapper;
use App\Services\StatutoryInvoice\StatutorySellerIdentity;

/**
 * Test fixture mapper. Fills PIN/UQC/seller address that production mapping
 * correctly leaves as gaps. Never used outside tests.
 */
final class FakeEInvoicePayloadMapper extends EInvoiceIrnPayloadMapper
{
    public function __construct()
    {
        parent::__construct(
            app(StatutorySellerIdentity::class),
            app(EInvoiceServiceClassification::class),
            app(EInvoiceUqcMapper::class),
        );
    }

    public function map(StatutoryInvoice $invoice): EInvoiceIrnPayload
    {
        $mapped = parent::map($invoice);
        $items = [];
        foreach ($mapped->items as $item) {
            $item['unit'] = 'NOS';
            $item['is_servc'] = $item['is_servc'] ?? 'Y';
            $items[] = $item;
        }

        return $mapped->withTestIrpFixtures(
            seller: [
                'address' => $mapped->seller['address'] ?? 'Test fixture seller address',
                'pin' => $mapped->seller['pin'] ?? '110019',
                'location' => $mapped->seller['location'] ?? 'New Delhi',
            ],
            buyer: [
                'pin' => $mapped->buyer['pin'] ?? '110001',
                'location' => $mapped->buyer['location'] ?? 'New Delhi',
            ],
            items: $items,
            removeGaps: [
                'missing_seller_address',
                'missing_seller_pin',
                'missing_seller_loc',
                'missing_buyer_pin',
                'missing_buyer_loc',
                'missing_uqc',
                'missing_is_servc',
            ],
        );
    }
}
