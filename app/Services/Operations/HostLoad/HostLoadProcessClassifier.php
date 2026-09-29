<?php

namespace App\Services\Operations\HostLoad;

class HostLoadProcessClassifier
{
    public const CLASSIFICATION_PROTECTED = 'protected';

    public const CLASSIFICATION_LEGITIMATE_APP = 'legitimate_application';

    public const CLASSIFICATION_UNKNOWN = 'unknown';

    /**
     * @param  array{cpu: float, pid: int, command: string}|null  $process
     */
    public function classify(?array $process): string
    {
        if ($process === null) {
            return self::CLASSIFICATION_UNKNOWN;
        }

        $command = strtolower((string) ($process['command'] ?? ''));

        if ($command === '') {
            return self::CLASSIFICATION_UNKNOWN;
        }

        if ($this->matchesAny($command, [
            'mariadbd',
            '/usr/sbin/mariadbd',
            'mysqld',
            'redis-server',
            'sshd',
            'systemd',
            'supervisord',
            'litespeed',
            'openlitespeed',
            'lshttpd',
        ])) {
            return self::CLASSIFICATION_PROTECTED;
        }

        if ($this->matchesAny($command, [
            'lsphp',
            'queue:work',
            'schedule:run',
            'schedule:light-tick',
            'automation:snapshot',
            'platform:snapshots:warm',
            'watchdog:send-critical',
            'host:monitor-load',
            'backup-run.sh',
            'mysqldump',
        ])) {
            return self::CLASSIFICATION_LEGITIMATE_APP;
        }

        return self::CLASSIFICATION_UNKNOWN;
    }

    private function matchesAny(string $command, array $patterns): bool
    {
        foreach ($patterns as $pattern) {
            if (str_contains($command, strtolower($pattern))) {
                return true;
            }
        }

        return false;
    }
}
