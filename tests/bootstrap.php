<?php

declare(strict_types=1);

$basePath = dirname(__DIR__);

$_ENV['APP_BASE_PATH'] = $basePath;
$_SERVER['APP_BASE_PATH'] = $basePath;

require $basePath.'/vendor/autoload.php';
