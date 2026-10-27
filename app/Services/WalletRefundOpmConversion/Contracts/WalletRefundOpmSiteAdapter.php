<?php

namespace App\Services\WalletRefundOpmConversion\Contracts;

use App\Enums\WalletRefundCreditSource;
use App\Services\WalletRefundOpmConversion\Contracts\WalletRefundOpmSpokeConversion;
use App\Services\WalletRefundOpmConversion\Contracts\WalletRefundOpmSpokeSubject;

interface WalletRefundOpmSiteAdapter
{
    public function siteCode(): string;

    public function isEnabled(): bool;

    public function supportsCreditSource(WalletRefundCreditSource $source): bool;

    /**
     * @return array{reservation_id: string, status: string, idempotent_replay: bool}
     */
    public function reserve(WalletRefundOpmSpokeConversion $conversion, WalletRefundOpmSpokeSubject $subject): array;

    /**
     * @return array{reservation_id: string, status: string}
     */
    public function confirmReservation(WalletRefundOpmSpokeConversion $conversion): array;

    /**
     * @return array{wallet_reversal_reference: string, idempotent_replay: bool}
     */
    public function finalizeWalletReversal(WalletRefundOpmSpokeConversion $conversion, WalletRefundOpmSpokeSubject $subject): array;

    /**
     * @return array{idempotent_replay: bool, status?: string}
     */
    public function releaseReservation(WalletRefundOpmSpokeConversion $conversion, WalletRefundOpmSpokeSubject $subject): array;
}
