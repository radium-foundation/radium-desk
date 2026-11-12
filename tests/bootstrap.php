<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap: load Composer autoload, then ensure writable storage paths
 * used by finance ZIP/XLSX tests (storage/app is gitignored except private/public).
 */

require dirname(__DIR__).'/vendor/autoload.php';

$writableDirs = [
    dirname(__DIR__).'/storage/app/tmp',
    dirname(__DIR__).'/storage/app/tmp/ca-monthly-report',
];

foreach ($writableDirs as $directory) {
    if (is_dir($directory)) {
        continue;
    }

    if (! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create PHPUnit storage directory: {$directory}");
    }
}
