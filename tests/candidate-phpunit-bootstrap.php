<?php

declare(strict_types=1);

$candidateRoot = dirname(__DIR__);
$_ENV['APP_BASE_PATH'] = $candidateRoot;
putenv('APP_BASE_PATH='.$candidateRoot);

$vendorAutoload = $candidateRoot.'/vendor/autoload.php';
if (! is_file($vendorAutoload)) {
    throw new RuntimeException('Vendor autoload missing for desk candidate PHPUnit bootstrap.');
}

require $vendorAutoload;

spl_autoload_register(static function (string $class) use ($candidateRoot): void {
    if (! str_starts_with($class, 'App\\')) {
        return;
    }

    $relativeClass = substr($class, strlen('App\\'));
    $path = $candidateRoot.'/app/'.str_replace('\\', '/', $relativeClass).'.php';
    if (is_file($path)) {
        require $path;
    }
}, prepend: true);
