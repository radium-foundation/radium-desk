<?php

declare(strict_types=1);

require __DIR__.'/candidate-phpunit-bootstrap.php';

$_SERVER['argv'] = array_merge(['artisan'], array_slice($argv, 1));

require dirname(__DIR__).'/artisan';
