<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);

$_ENV['APP_BASE_PATH'] = $basePath;
$_SERVER['APP_BASE_PATH'] = $basePath;

require $basePath.'/vendor/autoload.php';

spl_autoload_register(static function (string $class) use ($basePath): void {
    foreach (['Tests\\' => '/tests/', 'App\\' => '/app/'] as $prefix => $directory) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = str_replace('\\', '/', substr($class, strlen($prefix)));
        $path = $basePath.$directory.$relative.'.php';
        if (is_file($path)) {
            require_once $path;
        }

        return;
    }
}, true, true);

spl_autoload_register(static function (string $class) use ($basePath): void {
    $prefix = 'Tests\\Feature\\HardwareFulfilment\\Support\\';
    if (! str_starts_with($class, $prefix)) {
        return;
    }

    $relative = str_replace('\\', '/', substr($class, strlen('Tests\\')));
    $path = $basePath.'/tests/'.$relative.'.php';
    if (is_file($path)) {
        require_once $path;
    }
});

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
