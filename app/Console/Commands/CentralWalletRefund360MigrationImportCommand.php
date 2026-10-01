<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\Refund360MigrationJournalImportService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use InvalidArgumentException;

#[Signature('central-wallet:refund360-migration-import
    {--manifest= : Optional manifest JSON path}
    {--verify-live-balances : Verify radiumbox.com wallet balances before import (production KVM8 only)}
    {--owner-authorized : Required explicit Owner authorization}')]
#[Description('Import refund 360 radiumbox.com migration journal (no financial writes)')]
class CentralWalletRefund360MigrationImportCommand extends Command
{
    public function __construct(
        private readonly Refund360MigrationJournalImportService $importService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! $this->option('owner-authorized')) {
            $this->error('owner_authorization_required');

            return self::FAILURE;
        }

        $manifest = $this->option('manifest');
        $path = is_string($manifest) && $manifest !== '' ? $manifest : null;

        try {
            $result = $this->importService->import(
                $path,
                (bool) $this->option('verify-live-balances'),
            );
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('batch_id='.$result['batch_id']);
        $this->info('imported='.$result['imported']);
        $this->info('skipped='.$result['skipped']);
        $this->info('assigned='.$result['assigned']);
        $this->info('blocked_manifest='.$result['blocked_manifest']);

        return self::SUCCESS;
    }
}
