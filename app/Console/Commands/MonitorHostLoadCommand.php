<?php

namespace App\Console\Commands;

use App\Services\Operations\HostLoad\HostLoadWatchdogService;
use Illuminate\Console\Command;

class MonitorHostLoadCommand extends Command
{
    protected $signature = 'host:monitor-load';

    protected $description = 'Monitor sustained host CPU load and send critical alerts (alert-only; no remediation)';

    public function handle(HostLoadWatchdogService $watchdog): int
    {
        if (! (bool) config('host_load_watchdog.enabled', false)) {
            $this->info('Host load watchdog is disabled.');

            return self::SUCCESS;
        }

        $result = $watchdog->monitor();

        $this->info(sprintf(
            'Host load watchdog: status=%s level=%s incident=%s notified=%s',
            $result['status'],
            $result['level'],
            $result['incident_id'] ?? 'none',
            $result['notified'] ? 'yes' : 'no',
        ));

        return $result['status'] === 'failed' ? self::FAILURE : self::SUCCESS;
    }
}
