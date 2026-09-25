<?php

namespace App\Console\Commands;

use App\Services\StatutoryInvoice\ServiceStatutoryGstMismatchExceptionService;
use Illuminate\Console\Attribute\AsCommand;
use Illuminate\Console\Command;

#[AsCommand(
    name: 'desk:process-service-statutory-gst-mismatch-exceptions',
    description: 'Process GST mismatch statutory-invoice exceptions (emails, deadlines, issuance)',
)]
class ProcessServiceStatutoryGstMismatchExceptionsCommand extends Command
{
    protected $signature = 'desk:process-service-statutory-gst-mismatch-exceptions';

    public function handle(ServiceStatutoryGstMismatchExceptionService $service): int
    {
        if (! (bool) config('service_statutory_invoice.gst_mismatch.enabled', true)) {
            $this->warn('GST mismatch statutory-invoice workflow is disabled.');

            return self::SUCCESS;
        }

        $service->processDueExceptions();
        $this->info('GST mismatch statutory-invoice exceptions processed.');

        return self::SUCCESS;
    }
}
