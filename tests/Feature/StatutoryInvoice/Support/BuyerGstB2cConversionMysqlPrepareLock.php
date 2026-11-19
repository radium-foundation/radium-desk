<?php

namespace Tests\Feature\StatutoryInvoice\Support;

final class BuyerGstB2cConversionMysqlPrepareLock
{
    private const LOCK_FILENAME = 'radium_desk_buyer_gst_b2c_conversion_mysql_prepare.lock';

    /**
     * @return resource
     */
    public static function acquire()
    {
        $path = sys_get_temp_dir().'/'.self::LOCK_FILENAME;
        $handle = fopen($path, 'c+');
        if ($handle === false) {
            throw new \RuntimeException('Unable to open GST B2C conversion prepare lock.');
        }

        if (! flock($handle, LOCK_EX)) {
            fclose($handle);
            throw new \RuntimeException('Unable to acquire GST B2C conversion prepare lock.');
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
