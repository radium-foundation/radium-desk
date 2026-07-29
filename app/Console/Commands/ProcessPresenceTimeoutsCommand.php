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
        $staleAfterMinutes = $awayTimeout + 2;
        $cutoff = now()->subMinutes($staleAfterMinutes);
        $staleCount = WorkSession::query()
            ->whereNull('logout_at')
            ->where(function ($query) use ($cutoff): void {
                $query->where('last_activity_at', '<=', $cutoff)
                    ->orWhere(function ($orphaned) use ($cutoff): void {
                        $orphaned->whereNull('last_activity_at')
                            ->where('login_at', '<=', $cutoff);
                    });
            })
            ->count();

        PlatformHealthCache::recordPresenceTimeoutRun(
            processed: $processed,
            staleCount: $staleCount,
        );

        $this->info("Processed {$processed} away session(s).");

        return self::SUCCESS;
    }
}
