<?php

namespace App\Services\WalletRefundOpmConversion\Contracts;

use App\Enums\WalletRefundCreditSource;

interface WalletRefundOpmSpokeConversion
{
    public function getPublicId(): string;

    public function getAmount(): string;

    public function getCurrency(): string;

    public function getSiteReservationId(): ?string;

    public function getWalletFinalizationIdempotencyKey(): ?string;

    public function getWalletCreditSource(): ?WalletRefundCreditSource;
}
