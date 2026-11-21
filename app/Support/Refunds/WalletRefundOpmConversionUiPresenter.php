<?php

namespace App\Support\Refunds;

use App\Enums\ApprovedRefundMethod;
use App\Enums\RefundStatus;
use App\Enums\WalletRefundOpmConversionStatus;
use App\Models\RefundRequest;
use App\Models\User;
use App\Models\WalletRefundOpmConversion;
use App\Services\WalletRefundOpmConversion\WalletRefundOpmConversionEligibilityService;
use Illuminate\Support\Facades\Config;

final class WalletRefundOpmConversionUiPresenter
{
    public function __construct(
        private readonly WalletRefundOpmConversionEligibilityService $eligibility,
    ) {}

    /**
     * @return array{
     *     active: ?WalletRefundOpmConversion,
     *     can_request: bool,
     *     request_block_reason: ?string,
     *     site_enabled: bool,
     * }
     */
    public function forRefund(RefundRequest $refund, ?User $user): array
    {
        $active = null;
        if (\Illuminate\Support\Facades\Schema::hasTable('wallet_refund_opm_conversions')) {
            $active = WalletRefundOpmConversion::query()
                ->where('refund_request_id', $refund->id)
                ->whereNotIn('status', [
                    WalletRefundOpmConversionStatus::Rejected,
                    WalletRefundOpmConversionStatus::Cancelled,
                    WalletRefundOpmConversionStatus::Completed,
                ])
                ->latest('id')
                ->first();
        }

        $siteEnabled = false;
        $canRequest = false;
        $blockReason = null;

        if ($user !== null && $user->can('refunds.create')) {
            try {
                $this->eligibility->assertCanSubmit($refund);
                $siteCode = $this->eligibility->siteCodeForRefund($refund);
                $siteEnabled = (bool) Config::get('wallet_refund_opm_conversion.sites.'.$siteCode.'.enabled', false);
                $canRequest = $siteEnabled && $active === null;
                if (! $siteEnabled) {
                    $blockReason = 'Wallet-to-OPM conversion is not enabled for this site.';
                }
            } catch (\Illuminate\Validation\ValidationException $exception) {
                $blockReason = collect($exception->errors())->flatten()->first();
            }
        }

        if ($refund->approved_refund_method !== ApprovedRefundMethod::Wallet
            || ! ($refund->status?->isTerminalSuccess() ?? false)) {
            $canRequest = false;
        }

        return [
            'active' => $active,
            'can_request' => $canRequest,
            'request_block_reason' => $blockReason,
            'site_enabled' => $siteEnabled,
        ];
    }
}
