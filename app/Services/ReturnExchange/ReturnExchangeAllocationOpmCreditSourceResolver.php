<?php

namespace App\Services\ReturnExchange;

use App\Enums\WalletRefundCreditSource;
use App\Models\OnlineReturnRefundAllocationLineExecution;
use Illuminate\Validation\ValidationException;

final class ReturnExchangeAllocationOpmCreditSourceResolver
{
    public function resolveFromExecution(OnlineReturnRefundAllocationLineExecution $execution): WalletRefundCreditSource
    {
        $provider = trim((string) $execution->executor_provider);

        return match ($provider) {
            'radiumbox_wallet', 'rdservice_in_wallet' => WalletRefundCreditSource::LegacyLocal,
            'desk_first_central_wallet', 'rdservice_net_central_wallet' => WalletRefundCreditSource::CentralWallet,
            default => throw ValidationException::withMessages([
                'conversion' => 'Wallet credit source cannot be determined from execution provider "'.$provider.'".',
            ]),
        };
    }
}
