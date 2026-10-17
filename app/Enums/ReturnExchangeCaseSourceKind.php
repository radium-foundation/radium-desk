<?php

namespace App\Enums;

enum ReturnExchangeCaseSourceKind: string
{
    case PosSale = 'pos_sale';
    case EcommerceOrder = 'ecommerce_order';
    case EcommerceFulfilment = 'ecommerce_fulfilment';
    case ServiceOrder = 'service_order';

    public function label(): string
    {
        return match ($this) {
            self::PosSale => 'POS sale',
            self::EcommerceOrder => 'Ecommerce order',
            self::EcommerceFulfilment => 'Ecommerce fulfilment',
            self::ServiceOrder => 'Service order',
        };
    }
}
