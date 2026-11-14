<?php

namespace App\Services\RadiumBox;

use App\Data\RadiumBox\DeskAuthoritativePaymentEvent;

final class DeskAuthoritativePaymentEventSigner
{
    public function sign(DeskAuthoritativePaymentEvent $event, string $secret, bool $dryRun = false): string
    {
        return $this->signBody($event->transmitJson($dryRun), $secret);
    }

    public function signBody(string $transmitJson, string $secret): string
    {
        $secret = trim($secret);
        if ($secret === '') {
            throw new \RuntimeException('Desk payment event signing secret is not configured.');
        }

        return 'sha256='.hash_hmac('sha256', $transmitJson, $secret);
    }
}