<?php

namespace App\Providers;

use App\CentralWallet\Application\AccountLinkService;
use App\CentralWallet\Application\AuditEventRecorder;
use App\CentralWallet\Application\BalanceMigrationCutoverService;
use App\CentralWallet\Application\BalanceMigrationStateMachine;
use App\CentralWallet\Application\CentralWalletCustomerIdentityEnsureService;
use App\CentralWallet\Application\CentralWalletService;
use App\CentralWallet\Application\CeremonyCompleteService;
use App\CentralWallet\Application\CeremonyVerificationProofValidator;
use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Application\CrossSiteCeremonyCohortEligibility;
use App\CentralWallet\Application\CrossSiteCeremonyResolver;
use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Application\CustomerLedgerHistoryAuthorizationGate;
use App\CentralWallet\Application\ExternalDirectLedgerDebitGate;
use App\CentralWallet\Application\HistoricalContactIdentityMatchService;
use App\CentralWallet\Application\HistoricalVisibilityContactIndexLoader;
use App\CentralWallet\Application\IdempotencyService;
use App\CentralWallet\Application\IntegrationSourceSystemResolver;
use App\CentralWallet\Application\LedgerEntryReadService;
use App\CentralWallet\Application\LedgerService;
use App\CentralWallet\Application\NullWalletMigrationSpokeClient;
use App\CentralWallet\Application\ReconciledHistoricalRefundFilter;
use App\CentralWallet\Application\ReservationService;
use App\CentralWallet\Application\ReservationStateMachine;
use App\CentralWallet\Application\WalletRefundDestinationIdentityService;
use App\CentralWallet\Application\WalletVisibilityService;
use App\CentralWallet\Infrastructure\Auth\CentralWalletIntegrationAuthenticator;
use App\CentralWallet\Infrastructure\Http\HttpWalletMigrationSpokeClient;
use App\CentralWallet\Infrastructure\Http\Middleware\EnsureCentralWalletReservationsEnabled;
use App\CentralWallet\Infrastructure\Http\Middleware\EnsureHistoricalWalletVisibilityEnabled;
use App\CentralWallet\Reliability\CentralWalletAccountLinkReconciliationAnalyzer;
use App\CentralWallet\Reliability\CentralWalletAccountLinkReconciliationReader;
use App\CentralWallet\Reliability\CentralWalletAccountLinkVarianceEvaluator;
use App\CentralWallet\Reliability\CentralWalletContractCatalog;
use App\CentralWallet\Reliability\CentralWalletContractProbe;
use App\CentralWallet\Reliability\CentralWalletDeploymentDriftVerifier;
use App\CentralWallet\Reliability\CentralWalletReleaseGateConfiguration;
use App\CentralWallet\Reliability\CentralWalletReleaseGateReporter;
use App\CentralWallet\Reliability\CentralWalletReleaseGateRunner;
use App\CentralWallet\Reliability\CentralWalletRuntimeManifestBuilder;
use App\CentralWallet\Reliability\CentralWalletRuntimeManifestStore;
use App\CentralWallet\Reliability\CentralWalletSemanticInvariantVerifier;
use App\CentralWallet\Reliability\CentralWalletSyntheticWalletProbe;
use App\CentralWallet\Reliability\WalletVisibilityContractValidator;
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
        $this->app->singleton(CeremonyVerificationProofValidator::class);
        $this->app->singleton(CrossSiteCeremonyCohortEligibility::class);
        $this->app->singleton(CrossSiteCeremonyResolver::class);
        $this->app->singleton(CeremonyCompleteService::class);
        $this->app->singleton(CustomerIdentitySubjectHasher::class);
        $this->app->singleton(HistoricalVisibilityContactIndexLoader::class);
        $this->app->singleton(ReconciledHistoricalRefundFilter::class);
        $this->app->singleton(HistoricalContactIdentityMatchService::class);
        $this->app->singleton(CentralWalletCustomerIdentityEnsureService::class);
        $this->app->singleton(WalletRefundDestinationIdentityService::class);
        $this->app->singleton(WalletVisibilityService::class);
        $this->app->singleton(CentralWalletContractCatalog::class);
        $this->app->singleton(WalletVisibilityContractValidator::class);
        $this->app->singleton(CentralWalletRuntimeManifestStore::class);
        $this->app->singleton(CentralWalletRuntimeManifestBuilder::class);
        $this->app->singleton(CentralWalletDeploymentDriftVerifier::class);
        $this->app->singleton(CentralWalletContractProbe::class);
        $this->app->singleton(CentralWalletReleaseGateConfiguration::class);
        $this->app->singleton(CentralWalletAccountLinkReconciliationAnalyzer::class);
        $this->app->singleton(CentralWalletAccountLinkReconciliationReader::class);
        $this->app->singleton(CentralWalletAccountLinkVarianceEvaluator::class);
        $this->app->singleton(CentralWalletSemanticInvariantVerifier::class);
        $this->app->singleton(CentralWalletSyntheticWalletProbe::class);
        $this->app->singleton(CentralWalletReleaseGateRunner::class);
        $this->app->singleton(CentralWalletReleaseGateReporter::class);
        $this->app->singleton(LedgerService::class);
        $this->app->singleton(IntegrationSourceSystemResolver::class);
        $this->app->singleton(ExternalDirectLedgerDebitGate::class);
        $this->app->singleton(ReservationStateMachine::class);
        $this->app->singleton(ReservationService::class);
        $this->app->singleton(CustomerLedgerHistoryAuthorizationGate::class);
        $this->app->singleton(LedgerEntryReadService::class);
        $this->app->singleton(BalanceMigrationStateMachine::class);
        $this->app->singleton(BalanceMigrationCutoverService::class);

        $this->app->singleton(WalletMigrationSpokeClient::class, function (): WalletMigrationSpokeClient {
            $migrationConfig = config('central_wallet.balance_migration', []);
            $baseUrl = rtrim(trim((string) ($migrationConfig['spoke_base_url'] ?? '')), '/');
            $token = trim((string) ($migrationConfig['spoke_token'] ?? ''));

            if ($baseUrl === '' || $token === '') {
                $spoke = config('order_lookup.spokes.rdservice_in', []);
                $baseUrl = rtrim(trim((string) ($spoke['base_url'] ?? '')), '/');
                $token = trim((string) ($spoke['token'] ?? ''));
            }

            if ($baseUrl === '' || $token === '') {
                return new NullWalletMigrationSpokeClient;
            }

            return new HttpWalletMigrationSpokeClient(
                baseUrl: $baseUrl,
                token: $token,
                connectTimeoutSeconds: (int) ($migrationConfig['spoke_connect_timeout_seconds'] ?? 3),
                timeoutSeconds: (int) ($migrationConfig['spoke_timeout_seconds'] ?? 15),
            );
        });
    }

    public function boot(): void
    {
        Route::aliasMiddleware('central_wallet.reservations', EnsureCentralWalletReservationsEnabled::class);
        Route::aliasMiddleware('central_wallet.historical_wallet_visibility', EnsureHistoricalWalletVisibilityEnabled::class);

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
