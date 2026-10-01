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
use App\CentralWallet\Application\E1CohortManifestLoader;
use App\CentralWallet\Application\E1CohortStateResolver;
use App\CentralWallet\Application\E1DestinationReadinessManifestService;
use App\CentralWallet\Application\E1IdentityMigrationJournalImportService;
use App\CentralWallet\Application\E1VerificationDestinationService;
use App\CentralWallet\Application\E2CohortManifestLoader;
use App\CentralWallet\Application\E2CohortStateResolver;
use App\CentralWallet\Application\E2DestinationReadinessManifestService;
use App\CentralWallet\Application\E2HistoricalManualRefundSettlementService;
use App\CentralWallet\Application\E2HistoricalSettlementBatchGate;
use App\CentralWallet\Application\E2HistoricalSettlementDryRunService;
use App\CentralWallet\Application\E2HistoricalSettlementJournalImportService;
use App\CentralWallet\Application\E2HistoricalSettlementManifestLoader;
use App\CentralWallet\Application\E2HistoricalSettlementOrchestrator;
use App\CentralWallet\Application\E2ProvisionalDisplayService;
use App\CentralWallet\Application\E2VerificationDestinationService;
use App\CentralWallet\Application\ExternalDirectLedgerDebitGate;
use App\CentralWallet\Application\HistoricalCohortProvisionalBalanceService;
use App\CentralWallet\Application\IdempotencyService;
use App\CentralWallet\Application\IdentityRequiredCohortManifestLoader;
use App\CentralWallet\Application\IntegrationSourceSystemResolver;
use App\CentralWallet\Application\LedgerEntryReadService;
use App\CentralWallet\Application\LedgerService;
use App\CentralWallet\Application\MigrationControlledCwidProvisionService;
use App\CentralWallet\Application\NextSafeBatchDryRunService;
use App\CentralWallet\Application\NextSafeBatchJournalImportService;
use App\CentralWallet\Application\NextSafeBatchManifestLoader;
use App\CentralWallet\Application\NullWalletMigrationSpokeClient;
use App\CentralWallet\Application\ProvisionalIdentityResolveService;
use App\CentralWallet\Application\Ready4FinancialMigrationManifestLoader;
use App\CentralWallet\Application\Ready4RefundMigrationBatchGate;
use App\CentralWallet\Application\Ready4RefundMigrationDryRunService;
use App\CentralWallet\Application\Ready4RefundMigrationJournalImportService;
use App\CentralWallet\Application\Ready4RefundMigrationOrchestrator;
use App\CentralWallet\Application\Ready4RefundMigrationRehearseService;
use App\CentralWallet\Application\Refund360MigrationBatchGate;
use App\CentralWallet\Application\Refund360MigrationDryRunService;
use App\CentralWallet\Application\Refund360MigrationJournalImportService;
use App\CentralWallet\Application\Refund360MigrationManifestLoader;
use App\CentralWallet\Application\Refund360MigrationOrchestrator;
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
use App\CentralWallet\Application\Type1FinancialMigrationManifestLoader;
use App\CentralWallet\Application\Type1MigrationCohortIdentityEstablishmentService;
use App\CentralWallet\Application\Type1MigrationCohortManifestLoader;
use App\CentralWallet\Application\Type1RefundMigrationBatchGate;
use App\CentralWallet\Application\Type1RefundMigrationDryRunService;
use App\CentralWallet\Application\Type1RefundMigrationJournalImportService;
use App\CentralWallet\Application\Type1RefundMigrationOrchestrator;
use App\CentralWallet\Application\Type1RefundMigrationRehearseService;
use App\CentralWallet\Infrastructure\Auth\CentralWalletIntegrationAuthenticator;
use App\CentralWallet\Infrastructure\Http\Middleware\EnsureCentralWalletCustomerIdentityEnabled;
use App\CentralWallet\Infrastructure\Http\Middleware\EnsureCentralWalletProvisionalIdentityEnabled;
use App\CentralWallet\Infrastructure\Http\Middleware\EnsureCentralWalletReservationsEnabled;
use App\CentralWallet\Infrastructure\Http\RoutingWalletMigrationSpokeClient;
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
        $this->app->singleton(Type1MigrationCohortManifestLoader::class);
        $this->app->singleton(Type1MigrationCohortIdentityEstablishmentService::class);
        $this->app->singleton(Type1FinancialMigrationManifestLoader::class);
        $this->app->singleton(Type1RefundMigrationJournalImportService::class);
        $this->app->singleton(Type1RefundMigrationBatchGate::class);
        $this->app->singleton(Type1RefundMigrationDryRunService::class);
        $this->app->singleton(Type1RefundMigrationOrchestrator::class);
        $this->app->singleton(Type1RefundMigrationRehearseService::class);
        $this->app->singleton(Ready4FinancialMigrationManifestLoader::class);
        $this->app->singleton(Ready4RefundMigrationJournalImportService::class);
        $this->app->singleton(Ready4RefundMigrationBatchGate::class);
        $this->app->singleton(Ready4RefundMigrationDryRunService::class);
        $this->app->singleton(Ready4RefundMigrationRehearseService::class);
        $this->app->singleton(Ready4RefundMigrationOrchestrator::class);
        $this->app->singleton(E2HistoricalSettlementManifestLoader::class);
        $this->app->singleton(E2HistoricalSettlementJournalImportService::class);
        $this->app->singleton(E2HistoricalSettlementBatchGate::class);
        $this->app->singleton(E2HistoricalManualRefundSettlementService::class);
        $this->app->singleton(E2HistoricalSettlementDryRunService::class);
        $this->app->singleton(E2HistoricalSettlementOrchestrator::class);
        $this->app->singleton(E2CohortManifestLoader::class);
        $this->app->singleton(E2ProvisionalDisplayService::class);
        $this->app->singleton(E2VerificationDestinationService::class);
        $this->app->singleton(E2CohortStateResolver::class);
        $this->app->singleton(E2DestinationReadinessManifestService::class);
        $this->app->singleton(E1CohortManifestLoader::class);
        $this->app->singleton(E1IdentityMigrationJournalImportService::class);
        $this->app->singleton(E1VerificationDestinationService::class);
        $this->app->singleton(E1CohortStateResolver::class);
        $this->app->singleton(E1DestinationReadinessManifestService::class);
        $this->app->singleton(NextSafeBatchManifestLoader::class);
        $this->app->singleton(NextSafeBatchJournalImportService::class);
        $this->app->singleton(NextSafeBatchDryRunService::class);
        $this->app->singleton(Refund360MigrationManifestLoader::class);
        $this->app->singleton(Refund360MigrationJournalImportService::class);
        $this->app->singleton(Refund360MigrationBatchGate::class);
        $this->app->singleton(Refund360MigrationDryRunService::class);
        $this->app->singleton(Refund360MigrationOrchestrator::class);
        $this->app->singleton(CustomerIdentityResolveService::class);
        $this->app->singleton(IdentityRequiredCohortManifestLoader::class);
        $this->app->singleton(HistoricalCohortProvisionalBalanceService::class);
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
            $spokes = [];

            foreach ([
                'rdservice.in' => config('order_lookup.spokes.rdservice_in', []),
                'radiumbox.com' => config('order_lookup.spokes.radiumbox_com', []),
            ] as $siteCode => $spoke) {
                if (! is_array($spoke)) {
                    continue;
                }

                $baseUrl = rtrim(trim((string) ($spoke['base_url'] ?? '')), '/');
                $token = trim((string) ($spoke['token'] ?? ''));
                if ($baseUrl === '' || $token === '') {
                    continue;
                }

                $spokes[$siteCode] = [
                    'base_url' => $baseUrl,
                    'token' => $token,
                    'host' => trim((string) ($spoke['host'] ?? '')),
                ];
            }

            if ($spokes === []) {
                return new NullWalletMigrationSpokeClient;
            }

            return new RoutingWalletMigrationSpokeClient(
                spokes: $spokes,
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
