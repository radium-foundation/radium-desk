<?php

namespace Tests\Feature\StatutoryInvoice\Support;

final class StatutoryCommerceMintMysqlPrepareLock
{
    private const LOCK_FILENAME = 'radium_desk_statutory_commerce_mint_mysql_prepare.lock';

    /**
     * @return resource
     */
    public static function acquire()
    {
        $path = sys_get_temp_dir().'/'.self::LOCK_FILENAME;
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open statutory commerce mint prepare lock.');
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException('Unable to acquire statutory commerce mint prepare lock.');
        }

        return $handle;
    }

    /**
     * @param  resource  $handle
     */
    public static function release($handle): void
    {
        flock($handle, LOCK_UN);
        fclose($handle);
    }
}
