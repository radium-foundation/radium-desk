<?php

$root = dirname(__DIR__);
$loader = require $root.'/vendor/autoload.php';

spl_autoload_register(function (string $class) use ($root): bool {
    $prefixes = [
        'Tests\\' => $root.'/tests/',
        'App\\' => $root.'/app/',
        'Database\\Seeders\\' => $root.'/database/seeders/',
        'Database\\Factories\\' => $root.'/database/factories/',
    ];
    foreach ($prefixes as $prefix => $base) {
        if (! str_starts_with($class, $prefix)) {
            continue;
        }
        $relative = substr($class, strlen($prefix));
        $file = $base.str_replace('\\', '/', $relative).'.php';
        if (is_file($file)) {
            require $file;

            return true;
        }
    }

    return false;
}, true, true);

return $loader;
