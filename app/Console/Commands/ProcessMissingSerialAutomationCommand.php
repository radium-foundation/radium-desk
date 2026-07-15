<?php

namespace App\Console\Commands;

use App\Services\MissingSerial\MissingSerialAutomationService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

#[Signature('missing-serial:process
    {--limit= : Maximum number of eligible orders to process}')]
#[Description('Run missing-serial automation: Request Serial Number after paid order + RadiumBox recovery window (see docs/missing-serial-automation.md)')]
class ProcessMissingSerialAutomationCommand extends Command
{
    public function __construct(
        private readonly MissingSerialAutomationService $automationService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        if (! config('missing_serial.enabled', true)) {
            $this->warn('Missing serial automation is disabled.');

            return self::SUCCESS;
        }

        $limit = $this->option('limit');
        $limit = is_numeric($limit) && (int) $limit > 0 ? (int) $limit : null;

        $result = $this->automationService->process($limit);

        $this->info(sprintf(
            '[%s] scanned=%d sent=%d reminded=%d escalated=%d skipped=%d failed=%d',
            now()->toDateTimeString(),
            $result->scanned,
            $result->sent,
            $result->reminded,
            $result->escalated,
            $result->skipped,
            $result->failed,
        ));

        Log::info('missing_serial.automation.processed', [
            'scanned' => $result->scanned,
            'sent' => $result->sent,
            'reminded' => $result->reminded,
            'escalated' => $result->escalated,
            'skipped' => $result->skipped,
            'failed' => $result->failed,
        ]);

        return self::SUCCESS;
    }
}
