<?php

namespace App\Providers;

use App\CentralWallet\Application\AccountLinkService;
use App\CentralWallet\Application\AuditEventRecorder;
use App\CentralWallet\Application\BalanceMigrationCutoverService;
use App\CentralWallet\Application\BalanceMigrationStateMachine;
use App\CentralWallet\Application\CentralWalletService;
use App\CentralWallet\Application\CeremonyCompleteService;
use App\CentralWallet\Application\CeremonyVerificationProofValidator;
use App\CentralWallet\Application\Contracts\WalletMigrationSpokeClient;
use App\CentralWallet\Application\CrossSiteCeremonyCohortEligibility;
use App\CentralWallet\Application\CrossSiteCeremonyResolver;
use App\CentralWallet\Application\CustomerFoundationFromCeremonyService;
use App\CentralWallet\Application\CustomerIdentityResolveService;
use App\CentralWallet\Application\CustomerIdentitySubjectHasher;
use App\CentralWallet\Application\ExternalDirectLedgerDebitGate;
use App\CentralWallet\Application\IdempotencyService;
use App\CentralWallet\Application\IntegrationSourceSystemResolver;
use App\CentralWallet\Application\LedgerEntryReadService;
use App\CentralWallet\Application\LedgerService;
use App\CentralWallet\Application\MigrationControlledCwidProvisionService;
use App\CentralWallet\Application\NullWalletMigrationSpokeClient;
use App\CentralWallet\Application\ProvisionalIdentityResolveService;
use App\CentralWallet\Application\RefundMigrationBatchGate;
use App\CentralWallet\Application\RefundMigrationDryRunService;
use App\CentralWallet\Application\RefundMigrationJournalImportService;
use App\CentralWallet\Application\RefundMigrationLane1Executor;
use App\CentralWallet\Application\RefundMigrationManifestLoader;
use App\CentralWallet\Application\RefundMigrationOrchestrator;
use App\CentralWallet\Application\RefundMigrationRollbackService;
use App\CentralWallet\Application\RefundMigrationStateMachine;
use App\CentralWallet\Application\RefundMigrationTargetAssignmentService;
use App\CentralWallet\Application\RefundProvenanceMigrationService;
use App\CentralWallet\Application\ReservationService;
use App\CentralWallet\Application\ReservationStateMachine;
use App\CentralWallet\Application\TrustedFinancialAuthorizationGate;
use App\CentralWallet\Infrastructure\Auth\CentralWalletIntegrationAuthenticator;
use App\CentralWallet\Infrastructure\Http\HttpWalletMigrationSpokeClient;
use App\CentralWallet\Infrastructure\Http\Middleware\EnsureCentralWalletCustomerIdentityEnabled;
use App\CentralWallet\Infrastructure\Http\Middleware\EnsureCentralWalletProvisionalIdentityEnabled;
use App\CentralWallet\Infrastructure\Http\Middleware\EnsureCentralWalletReservationsEnabled;
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
        $this->app->singleton(CustomerFoundationFromCeremonyService::class);
        $this->app->singleton(MigrationControlledCwidProvisionService::class);
        $this->app->singleton(CustomerIdentityResolveService::class);
        $this->app->singleton(ProvisionalIdentityResolveService::class);
        $this->app->singleton(TrustedFinancialAuthorizationGate::class);
        $this->app->singleton(LedgerService::class);
        $this->app->singleton(IntegrationSourceSystemResolver::class);
        $this->app->singleton(ExternalDirectLedgerDebitGate::class);
        $this->app->singleton(ReservationStateMachine::class);
        $this->app->singleton(ReservationService::class);
        $this->app->singleton(LedgerEntryReadService::class);
        $this->app->singleton(BalanceMigrationStateMachine::class);
        $this->app->singleton(BalanceMigrationCutoverService::class);
        $this->app->singleton(RefundMigrationManifestLoader::class);
        $this->app->singleton(RefundMigrationStateMachine::class);
        $this->app->singleton(RefundMigrationJournalImportService::class);
        $this->app->singleton(RefundMigrationTargetAssignmentService::class);
        $this->app->singleton(RefundMigrationBatchGate::class);
        $this->app->singleton(RefundProvenanceMigrationService::class);
        $this->app->singleton(RefundMigrationLane1Executor::class);
        $this->app->singleton(RefundMigrationRollbackService::class);
        $this->app->singleton(RefundMigrationDryRunService::class);
        $this->app->singleton(RefundMigrationOrchestrator::class);

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
        Route::aliasMiddleware('central_wallet.customer_identity', EnsureCentralWalletCustomerIdentityEnabled::class);
        Route::aliasMiddleware('central_wallet.provisional_identity', EnsureCentralWalletProvisionalIdentityEnabled::class);

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
