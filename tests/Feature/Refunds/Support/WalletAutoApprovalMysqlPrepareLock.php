<?php

namespace Tests\Feature\Refunds\Support;

final class WalletAutoApprovalMysqlPrepareLock
{
    private const LOCK_FILENAME = 'radium_desk_wallet_auto_approval_mysql_prepare.lock';

    /**
     * @return resource|null
     */
    public static function acquire(): mixed
    {
        $path = sys_get_temp_dir().DIRECTORY_SEPARATOR.self::LOCK_FILENAME;
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            fwrite(STDERR, "Prepare lock: unable to open {$path}\n");

            return null;
        }

        if (! flock($handle, LOCK_EX | LOCK_NB)) {
            fclose($handle);

            return null;
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
