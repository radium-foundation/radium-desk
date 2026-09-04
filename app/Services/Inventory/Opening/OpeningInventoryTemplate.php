<?php

namespace App\Services\Inventory\Opening;

final class OpeningInventoryTemplate
{
    public const SHEET_OPENING = 'Inventory Opening';

    public const SHEET_SKU = 'SKU Master';

    public const SHEET_BRANCHES = 'Branches';

    /**
     * @var list<string>
     */
    public const OPENING_HEADERS = [
        'Opening Date',
        'Branch Code',
        'Location Type',
        'SKU',
        'Variant SKU',
        'Product Name',
        'Serialized',
        'Condition',
        'Stock Status',
        'Serial Number',
        'Quantity',
        'Unit Cost',
        'Selling Price',
        'GST %',
        'HSN',
        'Counted By',
        'Remarks',
        'Row Issues',
    ];

    /**
     * @var list<string>
     */
    public const SKU_HEADERS = [
        'SKU',
        'Product Name',
        'Variant SKU',
        'Serialized',
        'HSN',
        'GST %',
        'Default Selling Price',
        'Default Unit Cost',
        'Active',
        'Remarks',
    ];

    /**
     * @var list<string>
     */
    public const BRANCH_HEADERS = [
        'Branch Code',
        'Branch Name',
        'Location Type',
        'GSTIN',
        'State',
        'City',
        'Address',
        'Active',
        'Notes',
    ];
}
