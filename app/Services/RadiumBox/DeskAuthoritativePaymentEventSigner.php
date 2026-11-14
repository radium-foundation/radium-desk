<?php

namespace App\Services\RadiumBox;

use App\Data\RadiumBox\DeskAuthoritativePaymentEvent;

final class DeskAuthoritativePaymentEventSigner
{
    public function sign(DeskAuthoritativePaymentEvent $event, string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '') {
            throw new \RuntimeException('Desk payment event signing secret is not configured.');
        }

        return 'sha256='.hash_hmac('sha256', $event->canonicalJson(), $secret);
    }
}