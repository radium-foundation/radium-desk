<?php

declare(strict_types=1);

use App\Services\DashboardOemHandoff\RdExpiryServiceReferenceSequenceAllocator;
use Illuminate\Contracts\Console\Kernel;

require __DIR__.'/../../vendor/autoload.php';

$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$connection = getenv('DB_CONNECTION') ?: 'sqlite';
$database = getenv('DB_DATABASE');
$config = $app->make('config');
$config->set('database.default', $connection);
if ($database !== false && $database !== '') {
    $config->set('database.connections.'.$connection.'.database', $database);
}
$app->make('db')->purge($connection);
$app->make('db')->reconnect($connection);

$allocator = $app->make(RdExpiryServiceReferenceSequenceAllocator::class);
$result = $allocator->allocate('2027-07-15');
fwrite(STDOUT, $result['reference']."\n");
exit(0);