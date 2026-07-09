<?php

namespace App\Console\Commands;

use App\Services\Inquiry\InquirySpamCleanupService;
use Illuminate\Console\Attributes\Description;
use Illuminate\Console\Attributes\Signature;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Log;

#[Signature('inquiry-spam:cleanup-noinput {--dry-run : Show spam enquiry candidates without closing them} {--before= : Only include cases created before this date (Y-m-d)}')]
#[Description('Archive historical BonVoice no-input spam enquiry cases')]
class InquirySpamCleanupCommand extends Command
{
    public function __construct(
        private readonly InquirySpamCleanupService $cleanupService,
    ) {
        parent::__construct();
    }

    public function handle(): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $before = $this->resolveBeforeDate();

        if ($before === false) {
            return self::FAILURE;
        }

        if ($dryRun) {
            $this->info('Dry run — no changes will be written.');
        }

        $summary = $this->cleanupService->cleanup($dryRun, $before);

        $this->info(sprintf('Total found: %d', $summary->totalFound));

        if ($summary->references !== []) {
            $this->newLine();
            $this->info('Service case references:');

            foreach ($summary->references as $reference) {
                $this->line(sprintf('- %s', $reference));
            }
        }

        if ($dryRun) {
            $this->newLine();
            $this->info(sprintf('Would close: %d', $summary->wouldClose));
        }

        $this->info(sprintf('Cases closed: %d', $summary->casesClosed));
        $this->info(sprintf('Skipped: %d', $summary->skipped));

        if ($summary->skipReasons !== []) {
            $this->newLine();
            $this->info('Skipped:');

            foreach ($summary->skipReasons as $reason => $count) {
                $this->info(sprintf('- %s: %d', $reason, $count));
            }
        }

        Log::info('Inquiry spam cleanup command completed.', [
            'dry_run' => $dryRun,
            'before' => $before?->toDateString(),
            'total_found' => $summary->totalFound,
            'would_close' => $summary->wouldClose,
            'cases_closed' => $summary->casesClosed,
            'skipped' => $summary->skipped,
            'references' => $summary->references,
            'skip_reasons' => $summary->skipReasons,
        ]);

        return self::SUCCESS;
    }

    private function resolveBeforeDate(): Carbon|false|null
    {
        $before = $this->option('before');

        if ($before === null || $before === '') {
            return null;
        }

        try {
            return Carbon::parse((string) $before)->startOfDay();
        } catch (\Throwable) {
            $this->error('Invalid --before date. Use Y-m-d format.');

            return false;
        }
    }
}
