<?php

namespace Tests\Feature\Workforce\Support;

final class LeavePolicyMysqlPrepareLock
{
    private const LOCK_PATH = '/tmp/radium_desk_leave_policy_mysql_prepare.lock';

    public static function acquire(): mixed
    {
        $handle = fopen(self::LOCK_PATH, 'c+');
        if ($handle === false) {
            return null;
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);

            return null;
        }

        return $handle;
    }

    public static function release(mixed $handle): void
    {
        if (! is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
