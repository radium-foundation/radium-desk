<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\E2CohortStateResolver;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CentralWalletE2VerificationAuditCommand extends Command
{
    protected $signature = 'central-wallet:e2-verification-audit
                            {--manifest= : Optional verification cohort manifest path}';

    protected $description = 'Read-only E-2 verification cohort state audit (P-30-10-22)';

    public function handle(E2CohortStateResolver $resolver): int
    {
        try {
            $audit = $resolver->audit($this->option('manifest'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('E-2 Verification Cohort Audit (read-only)');
        $this->line('Cohort: '.$audit['cohort_id']);
        $this->line('Population: '.$audit['population']['count'].' / ₹'.$audit['population']['amount']);

        foreach ($audit['totals'] as $state => $total) {
            $this->line(sprintf('  %s: %d / ₹%s', $state, $total['count'], $total['amount']));
        }

        $ledgerCreditCount = CentralWalletLedgerEntry::query()
            ->where('entry_type', LedgerEntryType::Credit->value)
            ->count();
        $ledgerCreditAmount = (string) CentralWalletLedgerEntry::query()
            ->where('entry_type', LedgerEntryType::Credit->value)
            ->sum('amount');
        $reconciled = CentralWalletRefundMigration::query()
            ->where('status', 'reconciled')
            ->count();

        $this->newLine();
        $this->line('Financial zero-check:');
        $this->line('  Ledger credits: '.$ledgerCreditCount.' / ₹'.bcadd((string) $ledgerCreditAmount, '0', 2));
        $this->line('  Reconciled journal: '.$reconciled);
        $this->line('  E-2 verification enabled: '.(config('central_wallet.e2_historical_settlement.verification_enabled') ? 'true' : 'false'));
        $this->line('  Lane 4 execution enabled: '.(config('central_wallet.e2_historical_settlement.execution_enabled') ? 'true' : 'false'));

        return self::SUCCESS;
    }
}
