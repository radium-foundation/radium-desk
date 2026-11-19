<?php

namespace App\Enums;

enum BuyerGstVerificationStatus: string
{
    case Pending = 'pending';
    case Verified = 'verified';
    case Failed = 'failed';
    case ReviewRequired = 'review_required';
}
