<?php

namespace App\Enums;

enum StatutoryInvoiceSourceType: string
{
    case InventorySale = 'inventory_sale';
    case SupportOrder = 'support_order';
    case CommerceOrder = 'commerce_order';
    case External = 'external';
}
