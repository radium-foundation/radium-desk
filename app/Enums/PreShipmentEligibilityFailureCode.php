<?php

namespace App\Enums;

enum PreShipmentEligibilityFailureCode: string
{
    case FeatureDisabled = 'feature_disabled';
    case UnsupportedFamily = 'unsupported_order_family';
    case Unpaid = 'unpaid';
    case SerialAllocated = 'serials_allocated';
    case StatutoryInvoiceIssued = 'statutory_invoice_issued';
    case ShipmentOrAwb = 'shipment_or_awb';
    case AmbiguousState = 'ambiguous_state';
    case ExistingOpenCase = 'existing_open_case';
    case FulfilmentBeyondScope = 'fulfilment_beyond_preship_scope';
}
