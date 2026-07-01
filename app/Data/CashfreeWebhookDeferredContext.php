<?php

namespace App\Data;

readonly class CashfreeWebhookDeferredContext
{
    public function __construct(
        public int $orderId,
        public int $incidentId,
        public int $actorId,
    ) {}
}
