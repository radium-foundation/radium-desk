<?php

use App\CentralWallet\Infrastructure\Http\Controllers\AccountLinkController;
use App\CentralWallet\Infrastructure\Http\Controllers\CeremonyCompleteController;
use App\CentralWallet\Infrastructure\Http\Controllers\HealthController;
use App\CentralWallet\Infrastructure\Http\Controllers\LedgerEntryController;
use App\CentralWallet\Infrastructure\Http\Controllers\ReservationController;
use App\CentralWallet\Infrastructure\Http\Controllers\WalletController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('central-wallet.health');

Route::post('/wallets', [WalletController::class, 'store'])->name('central-wallet.wallets.store');
Route::get('/wallets/{cwid}', [WalletController::class, 'show'])->name('central-wallet.wallets.show');
Route::get('/wallets/{cwid}/balance', [WalletController::class, 'balance'])->name('central-wallet.wallets.balance');
Route::post('/wallets/{cwid}/ledger-entries', [WalletController::class, 'appendLedgerEntry'])
    ->name('central-wallet.wallets.ledger-entries.store');
Route::get('/wallets/{cwid}/ledger-entries', [LedgerEntryController::class, 'indexForWallet'])
    ->name('central-wallet.wallets.ledger-entries.index');

Route::get('/ledger-entries', [LedgerEntryController::class, 'index'])
    ->name('central-wallet.ledger-entries.index');
Route::get('/ledger-entries/{ledger_entry_id}', [LedgerEntryController::class, 'show'])
    ->whereNumber('ledger_entry_id')
    ->name('central-wallet.ledger-entries.show');

Route::post('/account-links', [AccountLinkController::class, 'store'])->name('central-wallet.account-links.store');
Route::post('/account-links/{link_id}/confirm', [AccountLinkController::class, 'confirm'])
    ->whereNumber('link_id')
    ->name('central-wallet.account-links.confirm');
Route::post('/account-links/{link_id}/revoke', [AccountLinkController::class, 'revoke'])
    ->whereNumber('link_id')
    ->name('central-wallet.account-links.revoke');
Route::get('/account-links', [AccountLinkController::class, 'index'])->name('central-wallet.account-links.index');

Route::post('/ceremony/complete', [CeremonyCompleteController::class, 'store'])
    ->name('central-wallet.ceremony.complete');

Route::post('/wallet-reservations', [ReservationController::class, 'store'])
    ->name('central-wallet.wallet-reservations.store');
