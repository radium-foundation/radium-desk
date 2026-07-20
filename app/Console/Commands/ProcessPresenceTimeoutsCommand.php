<?php

namespace App\Console\Commands;

use App\Models\WorkSession;
use App\Services\Operations\PresenceEngineService;
use App\Services\Platform\PlatformHealthCache;
use Illuminate\Console\Command;

class ProcessPresenceTimeoutsCommand extends Command
{
    protected $signature = 'presence:process-timeouts';

    protected $description = 'Close away work sessions and invalidate inactive team logins';

    public function handle(PresenceEngineService $presenceEngine): int
    {
        $processed = $presenceEngine->processTimedOutSessions();

        $awayTimeout = max(1, (int) config('presence.away_timeout_minutes', 15));
        $staleCount = WorkSession::query()
            ->whereNull('logout_at')
            ->where('last_activity_at', '<=', now()->subMinutes($awayTimeout))
            ->count();

        PlatformHealthCache::recordPresenceTimeoutRun(
            processed: $processed,
            staleCount: $staleCount,
        );

        $this->info("Processed {$processed} away session(s).");

        return self::SUCCESS;
    }
}
