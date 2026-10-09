<?php

namespace App\CentralWallet\Application;

enum CashfreeHistoricalIdentityRepairRefundExposure: string
{
    case NoRefundFound = 'NO_REFUND_FOUND';
    case DeskLedger = 'REFUND_EXISTS_DESK_LEDGER';
    case SpokeWallet = 'REFUND_EXISTS_SPOKE_WALLET';
    case Both = 'REFUND_EXISTS_BOTH';
    case RequiresReview = 'REFUND_STATE_REQUIRES_REVIEW';
    case Unknown = 'UNKNOWN';
}
