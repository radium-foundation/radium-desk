<?php

namespace App\Enums;

enum WorkspaceComponent: string
{
    case Assign = 'assign';
    case Action = 'action';
    case Remark = 'remark';
    case Resolve = 'resolve';
    case Close = 'close';
    case Timeline = 'timeline';
    case BatchTransaction = 'batch-transaction';
    case BatchDeviceModel = 'batch-device-model';
    case RequestSerialNumber = 'request-serial';
    case RequestCorrectSerial = 'request-correct-serial';
    case CustomerNotResponding = 'customer-not-responding';
    case LinkOrder = 'link-order';

    public function view(): string
    {
        return match ($this) {
            self::Assign => 'service-cases.fragments.assign-form',
            self::Action => 'service-cases.fragments.action-form',
            self::Remark => 'service-cases.fragments.remark-form',
            self::Resolve => 'service-cases.fragments.resolve-form',
            self::Close => 'service-cases.fragments.close-form',
            self::Timeline => 'incidents.partials.activity-timeline',
            self::BatchTransaction => 'dashboard.fragments.batch-transaction-form',
            self::BatchDeviceModel => 'dashboard.fragments.batch-device-model-form',
            self::RequestSerialNumber => 'customer-360.fragments.request-serial-form',
            self::RequestCorrectSerial => 'customer-360.fragments.request-correct-serial-form',
            self::CustomerNotResponding => 'customer-360.fragments.customer-not-responding-form',
            self::LinkOrder => 'customer-360.fragments.link-order-form',
        };
    }
}
