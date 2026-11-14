<?php

namespace App\Services\RadiumBox;

use App\Services\RadiumBox\Contracts\RadiumBoxPaymentDownstreamGateway;

final class RadiumBoxPaymentDownstreamGatewayResolver
{
    public function __construct(
        private readonly LegacyRadiumBoxCashfreeDelegationGateway $legacyGateway,
        private readonly DeskAuthoritativeRadiumBoxPaymentGateway $authoritativeGateway,
    ) {}

    public function resolve(): RadiumBoxPaymentDownstreamGateway
    {
        $contract = (string) config('radiumbox.payment_confirm.contract', LegacyRadiumBoxCashfreeDelegationGateway::CONTRACT);

        return match ($contract) {
            DeskAuthoritativeRadiumBoxPaymentGateway::CONTRACT => $this->authoritativeGateway,
            default => $this->legacyGateway,
        };
    }
}