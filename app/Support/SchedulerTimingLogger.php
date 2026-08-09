<?php

namespace App\Support;

use Carbon\Carbon;
use Throwable;

/**
 * Append-only JSONL writer for scheduler timing telemetry (Layer B).
 *
 * Never throws — disk/permission failures must not affect scheduled work.
 */
class SchedulerTimingLogger
{
    public function write(array $payload): void
    {
        try {
            $dir = storage_path('logs/scheduler-timing');

            if (! is_dir($dir) && ! mkdir($dir, 0755, true) && ! is_dir($dir)) {
                return;
            }

            @chmod($dir, 0755);

            $now = Carbon::now();
            $timezone = (string) config('app.timezone', 'Asia/Kolkata');

            $record = array_merge([
                'ts' => $now->copy()->timezone($timezone)->format('Y-m-d\TH:i:sP'),
                'ts_utc' => $now->copy()->utc()->format('Y-m-d\TH:i:s\Z'),
            ], $payload);

            $line = json_encode($record, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);

            if ($line === false) {
                return;
            }

            $path = $dir.DIRECTORY_SEPARATOR.$now->copy()->timezone($timezone)->format('Y-m-d').'.jsonl';
            $written = @file_put_contents($path, $line.PHP_EOL, FILE_APPEND | LOCK_EX);

            if ($written !== false) {
                @chmod($path, 0644);
            }
        } catch (Throwable) {
            // Telemetry must never interrupt scheduler / artisan command paths.
        }
    }
}
