<?php

namespace App\Services\WalletRefundOpmConversion;

use App\Enums\WalletRefundCreditSource;
use App\Models\AuditLog;
use App\Models\RefundRequest;
use App\Support\Refunds\DeskFirstWalletRefundSupport;
use Illuminate\Validation\ValidationException;

final class WalletRefundCreditSourceResolver
{
    /**
     * @var list<string>
     */
    private const CENTRAL_WALLET_PROVIDERS = [
        'desk_first_central_wallet',
        'rdservice_net_central_wallet',
    ];

    /**
     * @var list<string>
     */
    private const LEGACY_LOCAL_PROVIDERS = [
        'radiumbox_wallet',
        'rdservice_in_wallet',
    ];

    public function resolve(RefundRequest $refund): WalletRefundCreditSource
    {
        if (DeskFirstWalletRefundSupport::hasPersistedDeskFirstCentralWalletExecution($refund)) {
            return WalletRefundCreditSource::CentralWallet;
        }

        $provider = $this->completionProvider($refund);
        if ($provider === null) {
            throw ValidationException::withMessages([
                'conversion' => 'Wallet refund credit source could not be determined for this refund. Conversion is not allowed until completion audit is available.',
            ]);
        }

        if (in_array($provider, self::CENTRAL_WALLET_PROVIDERS, true)) {
            return WalletRefundCreditSource::CentralWallet;
        }

        if (in_array($provider, self::LEGACY_LOCAL_PROVIDERS, true)) {
            return WalletRefundCreditSource::LegacyLocal;
        }

        throw ValidationException::withMessages([
            'conversion' => 'Wallet refund credit source "'.$provider.'" is not supported for wallet-to-OPM conversion.',
        ]);
    }

    private function completionProvider(RefundRequest $refund): ?string
    {
        $log = AuditLog::query()
            ->where('auditable_type', RefundRequest::class)
            ->where('auditable_id', $refund->id)
            ->where('event', 'refund.completed')
            ->orderByDesc('id')
            ->first();

        $provider = data_get($log?->new_values, 'provider');

        return is_string($provider) && trim($provider) !== '' ? trim($provider) : null;
    }
}
