<?php

namespace App\Support\Scheduling;

/**
 * Hostinger (and similar shared hosts) wrap cron in flock and pass the lock FD
 * into `php artisan schedule:run`. Laravel `runInBackground()` children inherit
 * that FD, so a long/hung background task keeps the host flock held and blocks
 * every subsequent schedule:run — including the scheduler heartbeat.
 *
 * Closing only paths that look like host cron lock files releases the flock
 * without touching sockets, DB connections, or other FDs.
 */
final class HostCronLockReleaser
{
    /**
     * @return int Number of inherited host cron lock FDs closed.
     */
    public static function releaseInherited(): int
    {
        if (PHP_OS_FAMILY === 'Windows' || ! is_dir('/proc/self/fd')) {
            return 0;
        }

        $released = 0;

        foreach (scandir('/proc/self/fd') ?: [] as $entry) {
            if (! ctype_digit($entry)) {
                continue;
            }

            $fd = (int) $entry;

            if ($fd < 3) {
                continue;
            }

            $target = @readlink('/proc/self/fd/'.$fd);

            if (! is_string($target) || ! self::isHostCronLockPath($target)) {
                continue;
            }

            if (self::closeFd($fd)) {
                $released++;
            }
        }

        return $released;
    }

    public static function isHostCronLockPath(string $target): bool
    {
        $basename = basename($target);

        // Hostinger: /tmp/cron_lock_<id>
        if (str_starts_with($basename, 'cron_lock')) {
            return true;
        }

        // Generic flock lock files used by some host panels.
        if (str_contains($target, '/cron_lock')) {
            return true;
        }

        return false;
    }

    private static function closeFd(int $fd): bool
    {
        $stream = @fopen('php://fd/'.$fd, 'r+');

        if ($stream === false) {
            $stream = @fopen('php://fd/'.$fd, 'r');
        }

        if ($stream === false) {
            $stream = @fopen('php://fd/'.$fd, 'w');
        }

        if ($stream === false) {
            return false;
        }

        return @fclose($stream);
    }
}
