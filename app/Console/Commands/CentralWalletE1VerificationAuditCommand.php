<?php

namespace App\Console\Commands;

use App\CentralWallet\Application\E1CohortStateResolver;
use App\CentralWallet\Domain\Enums\LedgerEntryType;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletLedgerEntry;
use App\CentralWallet\Infrastructure\Persistence\CentralWalletRefundMigration;
use Illuminate\Console\Command;
use InvalidArgumentException;

class CentralWalletE1VerificationAuditCommand extends Command
{
    protected $signature = 'central-wallet:e1-verification-audit
                            {--manifest= : Optional E-1 verification cohort manifest path}';

    protected $description = 'Read-only E-1 identity cohort state audit (P-30-10-32)';

    public function handle(E1CohortStateResolver $resolver): int
    {
        try {
            $audit = $resolver->audit($this->option('manifest'));
        } catch (InvalidArgumentException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('E-1 Identity Cohort Audit (read-only)');
        $this->line('Cohort: '.$audit['cohort_id']);
        $this->line('Population: '.$audit['population']['count'].' / ₹'.$audit['population']['amount']);

        $this->newLine();
        $this->line('Identity states:');
        foreach ($audit['identity_totals'] as $state => $total) {
            $this->line(sprintf('  %s: %d / ₹%s', $state, $total['count'], $total['amount']));
        }

        $this->newLine();
        $this->line('Destination states:');
        foreach ($audit['destination_totals'] as $state => $total) {
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
        $this->line('  E-1 verification enabled: '.(config('central_wallet.e1_identity_migration.verification_enabled') ? 'true' : 'false'));
        $this->line('  Refund migration execution: '.(config('central_wallet.refund_migration.execution_enabled') ? 'true' : 'false'));

        return self::SUCCESS;
    }
}
