<?php

namespace App\Enums;

enum RadiumBoxReadIdentifierType: string
{
    case CommercialId = 'commercial_id';
    case Ordercode = 'ordercode';
    case RdserviceOrderId = 'rdservice_order_id';
    case RdId = 'rd_id';
    case Rdorderid = 'rdorderid';

    public function describes(): string
    {
        return match ($this) {
            self::CommercialId => 'orders.id',
            self::Ordercode => 'orders.ordercode',
            self::RdserviceOrderId => 'orders.rdservice_order_id',
            self::RdId => 'order_rdservice.id',
            self::Rdorderid => 'order_rdservice.rdorderid',
        };
    }
}
