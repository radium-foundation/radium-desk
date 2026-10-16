<?php

namespace App\Services\WalletRefundOpmConversion\Contracts;

use App\Enums\WalletRefundCreditSource;
use App\Models\RefundRequest;
use App\Models\WalletRefundOpmConversion;

interface WalletRefundOpmSiteAdapter
{
    public function siteCode(): string;

    public function isEnabled(): bool;

    public function supportsCreditSource(WalletRefundCreditSource $source): bool;

    /**
     * @return array{reservation_id: string, status: string, idempotent_replay: bool}
     */
    public function reserve(WalletRefundOpmConversion $conversion, RefundRequest $refund): array;

    /**
     * @return array{reservation_id: string, status: string}
     */
    public function confirmReservation(WalletRefundOpmConversion $conversion): array;

    /**
     * @return array{wallet_reversal_reference: string, idempotent_replay: bool}
     */
    public function finalizeWalletReversal(WalletRefundOpmConversion $conversion, RefundRequest $refund): array;

    public function releaseReservation(WalletRefundOpmConversion $conversion, RefundRequest $refund): void;
}
