<?php

use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

define('LARAVEL_START', microtime(true));

$bootstrapPath = '{{BOOTSTRAP_PATH}}';

// Determine if the application is in maintenance mode...
if (file_exists($maintenance = dirname(dirname($bootstrapPath)) . '/storage/framework/maintenance.php')) {
    require $maintenance;
}

// Register the Composer autoloader...
require '{{VENDOR_PATH}}';

// Bootstrap Laravel and handle the request...
/** @var Application $app */
$app = require_once $bootstrapPath;

$app->handleRequest(Request::capture());
