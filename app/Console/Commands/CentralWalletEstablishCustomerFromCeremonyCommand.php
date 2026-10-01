<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\CustomerFoundationFromCeremonyService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Str;
use InvalidArgumentException;

#[Signature('central-wallet:establish-customer-from-ceremony
    {site-code : Spoke site code e.g. radiumbox.com}
    {local-user-id : Local user ID with ceremony identity}
    {--dry-run : Validate only; no writes}')]
#[Description('Establish Desk Customer ID from existing ceremony identity + CWID (identity foundation only)')]
class CentralWalletEstablishCustomerFromCeremonyCommand extends Command
{
    public function __construct(
        private readonly CustomerFoundationFromCeremonyService $foundationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $siteCode = (string) $this->argument('site-code');
        $localUserId = (string) $this->argument('local-user-id');

        if ($this->option('dry-run')) {
            $this->info('dry_run=1 site='.$siteCode.' local_user_id='.$localUserId);

            return self::SUCCESS;
        }

        try {
            $result = $this->foundationService->establishFromCeremony(
                siteCode: $siteCode,
                localUserId: $localUserId,
                actorId: 'cli:establish-customer-from-ceremony',
                correlationId: (string) Str::uuid(),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($result, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR));

        return self::SUCCESS;
    }
}
