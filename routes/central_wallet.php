<?php

use App\CentralWallet\Infrastructure\Http\Controllers\AccountLinkController;
use App\CentralWallet\Infrastructure\Http\Controllers\BalanceMigrationController;
use App\CentralWallet\Infrastructure\Http\Controllers\CeremonyCompleteController;
use App\CentralWallet\Infrastructure\Http\Controllers\HealthController;
use App\CentralWallet\Infrastructure\Http\Controllers\LedgerEntryController;
use App\CentralWallet\Infrastructure\Http\Controllers\ReservationController;
use App\CentralWallet\Infrastructure\Http\Controllers\WalletController;
use App\CentralWallet\Infrastructure\Http\Controllers\WalletRefundDestinationController;
use App\CentralWallet\Infrastructure\Http\Controllers\WalletVisibilityController;
use Illuminate\Support\Facades\Route;

Route::get('/health', HealthController::class)->name('central-wallet.health');

Route::post('/wallets', [WalletController::class, 'store'])->name('central-wallet.wallets.store');
Route::get('/wallets/{cwid}', [WalletController::class, 'show'])->name('central-wallet.wallets.show');
Route::get('/wallets/{cwid}/balance', [WalletController::class, 'balance'])->name('central-wallet.wallets.balance');
Route::post('/wallets/{cwid}/ledger-entries', [WalletController::class, 'appendLedgerEntry'])
    ->name('central-wallet.wallets.ledger-entries.store');
Route::get('/wallets/{cwid}/ledger-entries', [LedgerEntryController::class, 'indexForWallet'])
    ->name('central-wallet.wallets.ledger-entries.index');
Route::get('/wallets/{cwid}/ledger-history', [LedgerEntryController::class, 'indexCustomerHistoryForWallet'])
    ->name('central-wallet.wallets.ledger-history.index');

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

Route::middleware('central_wallet.historical_wallet_visibility')->group(function (): void {
    Route::get('/wallet-refund-destination', [WalletRefundDestinationController::class, 'show'])
        ->name('central-wallet.wallet-refund-destination.show');
    Route::get('/wallet-visibility', [WalletVisibilityController::class, 'show'])
        ->name('central-wallet.wallet-visibility.show');
});

Route::middleware('central_wallet.reservations')->group(function (): void {
    Route::post('/wallet-reservations', [ReservationController::class, 'store'])
        ->name('central-wallet.wallet-reservations.store');
    Route::get('/wallet-reservations/{reservation_id}', [ReservationController::class, 'show'])
        ->whereUuid('reservation_id')
        ->name('central-wallet.wallet-reservations.show');
    Route::post('/wallet-reservations/{reservation_id}/commit', [ReservationController::class, 'commit'])
        ->whereUuid('reservation_id')
        ->name('central-wallet.wallet-reservations.commit');
    Route::post('/wallet-reservations/{reservation_id}/release', [ReservationController::class, 'release'])
        ->whereUuid('reservation_id')
        ->name('central-wallet.wallet-reservations.release');
});

Route::post('/balance-migrations/execute', [BalanceMigrationController::class, 'execute'])
    ->name('central-wallet.balance-migrations.execute');
