<?php

namespace App\Enums;

enum BuyerGstB2cConversionStatus: string
{
    case Pending = 'pending';
    case Approved = 'approved';
    case Rejected = 'rejected';
}
