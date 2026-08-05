<?php

namespace App\Console\Commands;

use App\Services\PerformanceIntelligence\PerformanceIntelligenceEngine;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class CapturePerformanceIntelligenceSnapshotCommand extends Command
{
    protected $signature = 'performance-intelligence:snapshot
                            {--date= : Work date Y-m-d (default: yesterday)}
                            {--force : Run even if feature flag is off (still writes only when enabled)}';

    protected $description = 'Capture Performance Intelligence daily shadow snapshots (no-op when disabled)';

    public function handle(PerformanceIntelligenceEngine $engine): int
    {
        if (! $engine->enabled()) {
            $this->info('Performance Intelligence disabled — skipping (zero runtime impact).');

            return self::SUCCESS;
        }

        $dateOption = $this->option('date');
        $workDate = is_string($dateOption) && $dateOption !== ''
            ? Carbon::parse($dateOption, config('app.timezone'))->startOfDay()
            : now()->subDay()->startOfDay();

        $this->info('Capturing Performance Intelligence snapshots for '.$workDate->toDateString());

        $result = $engine->captureDay($workDate);

        $this->info(sprintf(
            'Processed %d employees in %d ms (date=%s)',
            $result['processed'],
            $result['duration_ms'],
            $result['date'],
        ));

        return self::SUCCESS;
    }
}
