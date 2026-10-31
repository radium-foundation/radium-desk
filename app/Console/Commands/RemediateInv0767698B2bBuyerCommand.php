<?php

namespace App\Console\Commands;

use App\Services\StatutoryInvoice\Inv0767698B2bBuyerRemediation;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use RuntimeException;

#[Signature('desk:remediate-inv-0767698-b2b-buyer
    {invoice_id : Statutory invoice id (must be 16507)}
    {--invoice-number=INV-0767698 : Expected invoice number guard}
    {--execute : Apply the remediation after validation (default is dry-run)}
    {--actor=P-31-108 : Operator or prompt id recorded in audit trail}')]
#[Description('Owner-authorized B2C→B2B buyer remediation for INV-0767698 (id 16507) only. Dry-run by default.')]
class RemediateInv0767698B2bBuyerCommand extends Command
{
    public function handle(Inv0767698B2bBuyerRemediation $remediation): int
    {
        $invoiceId = (int) $this->argument('invoice_id');
        $invoiceNumber = trim((string) $this->option('invoice-number'));
        $actor = trim((string) $this->option('actor'));
        $actor = $actor !== '' ? $actor : Inv0767698B2bBuyerRemediation::PROMPT_ID;

        try {
            $report = (bool) $this->option('execute')
                ? $remediation->execute($invoiceId, $invoiceNumber, $actor)
                : $remediation->plan($invoiceId, $invoiceNumber);
        } catch (RuntimeException $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->line(json_encode($report, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE));

        if ((bool) $this->option('execute')) {
            if (($report['applied'] ?? false) === true) {
                $this->info('Remediation applied for '.Inv0767698B2bBuyerRemediation::EXPECTED_INVOICE_NUMBER.'.');
            } elseif (($report['already_applied'] ?? false) === true) {
                $this->info('Remediation already applied (idempotent).');
            }
        } else {
            $this->info('Dry-run complete. Re-run with --execute to apply.');
        }

        return self::SUCCESS;
    }
}
