<?php

namespace App\Services\Wallet;

enum WalletLedgerSource: string
{
    case RadiumBoxLegacy = 'radiumbox_legacy';
    case CentralWallet = 'central_wallet';
}
