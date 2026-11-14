<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);

$_ENV['APP_BASE_PATH'] = $basePath;
$_SERVER['APP_BASE_PATH'] = $basePath;

require $basePath.'/vendor/autoload.php';

/*
 * Ensure writable storage paths used by finance ZIP/XLSX tests
 * (storage/app is gitignored except private/public).
 */
$writableDirs = [
    $basePath.'/storage/app/tmp',
    $basePath.'/storage/app/tmp/ca-monthly-report',
];

foreach ($writableDirs as $directory) {
    if (is_dir($directory)) {
        continue;
    }

    if (! @mkdir($directory, 0775, true) && ! is_dir($directory)) {
        throw new RuntimeException("Unable to create PHPUnit storage directory: {$directory}");
    }
}