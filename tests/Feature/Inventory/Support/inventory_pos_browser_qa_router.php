<?php

declare(strict_types=1);

$root = dirname(__DIR__, 4);
$database = (string) (getenv('DB_DATABASE') ?: '');
$connection = (string) (getenv('DB_CONNECTION') ?: '');
$appEnv = (string) (getenv('APP_ENV') ?: 'local');
$appUrl = (string) (getenv('APP_URL') ?: '');

if ($connection !== 'sqlite' || basename($database) !== 'inventory-pos-browser-qa.sqlite') {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Refusing browser QA router: sqlite file must be inventory-pos-browser-qa.sqlite.\n";

    return true;
}

if (! in_array($appEnv, ['local', 'testing', 'development'], true)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Refusing browser QA router outside local/testing.\n";

    return true;
}

$host = (string) parse_url($appUrl !== '' ? $appUrl : 'http://127.0.0.1:8765', PHP_URL_HOST);
if (! in_array($host, ['127.0.0.1', 'localhost'], true)) {
    http_response_code(500);
    header('Content-Type: text/plain; charset=utf-8');
    echo "Refusing browser QA router: APP_URL host must be loopback.\n";

    return true;
}

foreach ([
    'DB_CONNECTION' => 'sqlite',
    'DB_DATABASE' => $database,
    'APP_URL' => $appUrl !== '' ? $appUrl : 'http://127.0.0.1:8765',
    'SESSION_DOMAIN' => '',
] as $key => $value) {
    putenv($key.'='.$value);
    $_ENV[$key] = $value;
    $_SERVER[$key] = $value;
}

$uri = urldecode((string) parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH));
$public = $root.'/public';
if ($uri !== '/' && $uri !== '' && is_file($public.$uri)) {
    return false;
}

require $public.'/index.php';
