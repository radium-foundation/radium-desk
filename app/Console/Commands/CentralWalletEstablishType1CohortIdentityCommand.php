<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\Type1MigrationCohortIdentityEstablishmentService;
use App\CentralWallet\Application\Type1MigrationCohortManifestLoader;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Signature('central-wallet:establish-type1-cohort-identity
    {site-code : Spoke site code e.g. rdservice.in}
    {local-user-id : Spoke local user ID}
    {--owner-authorized : Required explicit Owner authorization for cohort identity}
    {--all : Process all 50 cohort customers (requires --owner-authorized)}
    {--dry-run : Validate cohort membership only; no writes}')]
#[Description('Owner-authorized TYPE-1 migration cohort identity (no M2 OTP, no ledger writes)')]
class CentralWalletEstablishType1CohortIdentityCommand extends Command
{
    public function __construct(
        private readonly Type1MigrationCohortIdentityEstablishmentService $establishmentService,
        private readonly Type1MigrationCohortManifestLoader $manifestLoader,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('owner-authorized')) {
            $this->error('owner_authorization_required');

            return self::FAILURE;
        }

        if ($this->option('all')) {
            return $this->processAll();
        }

        return $this->processOne(
            (string) $this->argument('site-code'),
            (string) $this->argument('local-user-id'),
        );
    }

    private function processOne(string $siteCode, string $localUserId): int
    {
        $manifest = $this->manifestLoader->load();
        $customer = $this->manifestLoader->findCustomer($manifest, $siteCode, $localUserId);
        if ($customer === null) {
            $this->error('not_in_type1_migration_cohort');

            return self::FAILURE;
        }

        if ($this->option('dry-run')) {
            $this->line(json_encode([
                'dry_run' => true,
                'site_code' => $siteCode,
                'local_user_id' => $localUserId,
                'refund_ids' => $customer['refund_ids'] ?? [],
                'migration_identity_state' => 'IDENTITY_ESTABLISHMENT_REQUIRED',
            ], JSON_THROW_ON_ERROR));

            return self::SUCCESS;
        }

        try {
            $result = $this->establishmentService->establish(
                siteCode: $siteCode,
                localUserId: $localUserId,
                actorId: 'cli:type1-cohort-identity',
                correlationId: (string) Str::uuid(),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return ($result['migration_identity_state'] ?? '') === 'IDENTITY_ESTABLISHED'
            ? self::SUCCESS
            : self::FAILURE;
    }

    private function processAll(): int
    {
        $manifest = $this->manifestLoader->load();
        $failures = 0;

        foreach ($manifest['customers'] as $customer) {
            $site = (string) ($customer['site'] ?? '');
            $uid = (string) ($customer['local_user_id'] ?? '');
            if ($this->option('dry-run')) {
                $this->line(json_encode([
                    'dry_run' => true,
                    'site_code' => $site,
                    'local_user_id' => $uid,
                    'migration_identity_state' => 'IDENTITY_ESTABLISHMENT_REQUIRED',
                ], JSON_THROW_ON_ERROR));

                continue;
            }

            try {
                $result = $this->establishmentService->establish(
                    siteCode: $site,
                    localUserId: $uid,
                    actorId: 'cli:type1-cohort-identity-batch',
                    correlationId: (string) Str::uuid(),
                );
                $this->line(json_encode(['site' => $site, 'local_user_id' => $uid, 'result' => $result], JSON_THROW_ON_ERROR));
                if (($result['migration_identity_state'] ?? '') !== 'IDENTITY_ESTABLISHED') {
                    $failures++;
                }
            } catch (InvalidArgumentException $exception) {
                $this->error($site.':'.$uid.' '.$exception->getMessage());
                $failures++;
            }
        }

        return $failures === 0 ? self::SUCCESS : self::FAILURE;
    }
}
