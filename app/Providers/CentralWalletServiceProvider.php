<?php

namespace App\Providers;

use App\CentralWallet\Application\AccountLinkService;
use App\CentralWallet\Application\AuditEventRecorder;
use App\CentralWallet\Application\CentralWalletService;
use App\CentralWallet\Application\IdempotencyService;
use App\CentralWallet\Application\LedgerEntryReadService;
use App\CentralWallet\Application\LedgerService;
use App\CentralWallet\Infrastructure\Auth\CentralWalletIntegrationAuthenticator;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\ServiceProvider;

final class CentralWalletServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->app->singleton(CentralWalletIntegrationAuthenticator::class);
        $this->app->singleton(AuditEventRecorder::class);
        $this->app->singleton(IdempotencyService::class);
        $this->app->singleton(CentralWalletService::class);
        $this->app->singleton(AccountLinkService::class);
        $this->app->singleton(LedgerService::class);
        $this->app->singleton(LedgerEntryReadService::class);
    }

    public function boot(): void
    {
        Route::middleware([
            'api',
            'central_wallet.correlation',
            'central_wallet.enabled',
            'central_wallet.auth',
        ])
            ->prefix('api/central-wallet/v1')
            ->group(base_path('routes/central_wallet.php'));
    }
}
