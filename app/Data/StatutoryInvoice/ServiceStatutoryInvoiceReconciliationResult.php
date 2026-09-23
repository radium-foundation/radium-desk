<?php

namespace App\Data\StatutoryInvoice;

final readonly class ServiceStatutoryInvoiceReconciliationResult
{
    public function __construct(
        public int $scanned,
        public int $attempted,
        public int $skipped,
        public int $alreadyInvoiced,
        public int $ineligible,
        public int $noWorkflow,
        public bool $dryRun,
    ) {}
}
