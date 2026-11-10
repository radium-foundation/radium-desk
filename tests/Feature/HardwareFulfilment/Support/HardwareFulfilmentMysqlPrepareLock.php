<?php

namespace Tests\Feature\HardwareFulfilment\Support;

final class HardwareFulfilmentMysqlPrepareLock
{
    private const LOCK_FILENAME = 'radium_desk_hw_fulfilment_mysql_prepare.lock';

    /**
     * @return resource
     */
    public static function acquire(): mixed
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.self::LOCK_FILENAME;
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            fwrite(STDERR, "Prepare lock: unable to open {$path}\n");

            exit(3);
        }

        if (! flock($handle, LOCK_EX)) {
            fwrite(STDERR, "Prepare lock: unable to acquire exclusive lock on {$path}\n");
            fclose($handle);

            exit(3);
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    public static function release(mixed $handle): void
    {
        if (! is_resource($handle)) {
            return;
        }

        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
