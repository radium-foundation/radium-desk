<?php

namespace App\Services\Bonvoice;

readonly class BonvoiceWebhookProcessOptions
{
    public function __construct(
        public bool $suppressNotifications = false,
        public bool $suppressRecovery = false,
    ) {}
}
