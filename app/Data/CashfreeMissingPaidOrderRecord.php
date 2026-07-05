<?php

namespace App\Data;

use App\Enums\CashfreeHistoricalRecoveryDisposition;
use Illuminate\Support\Carbon;

readonly class CashfreeMissingPaidOrderRecord
{
    public function __construct(
        public int $webhookLogId,
        public ?string $orderId,
        public string $cfPaymentId,
        public ?Carbon $paidAt,
        public CashfreeHistoricalRecoveryDisposition $recoveryEligibility,
        public string $recoveryReason,
    ) {}
}
