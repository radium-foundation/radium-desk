<?php

namespace App\Console\Commands;

use App\Services\Operations\PresenceEngineService;
use Illuminate\Console\Command;

class ProcessPresenceTimeoutsCommand extends Command
{
    protected $signature = 'presence:process-timeouts';

    protected $description = 'Close away work sessions and invalidate inactive team logins';

    public function handle(PresenceEngineService $presenceEngine): int
    {
        $processed = $presenceEngine->processTimedOutSessions();

        $this->info("Processed {$processed} away session(s).");

        return self::SUCCESS;
    }
}
