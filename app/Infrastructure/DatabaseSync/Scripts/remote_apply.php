#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\DatabaseSync\DatabaseSyncManifest;
use App\Infrastructure\DatabaseSync\DeltaApplyRunner;
use App\Infrastructure\DatabaseSync\ApplyLock;
use App\Infrastructure\DatabaseSync\TableCheckpointStore;
use App\Infrastructure\DatabaseSync\TableDeltaApplier;
use App\Infrastructure\DatabaseSync\TargetHostGuard;
use App\Infrastructure\DatabaseSync\UniqueConflictChecker;
use Illuminate\Contracts\Console\Kernel;

$projectRoot = dirname(__DIR__, 4);

if (! is_file($projectRoot.'/vendor/autoload.php')) {
    fwrite(STDERR, json_encode(['error' => 'Laravel project root not found.']).PHP_EOL);

    exit(1);
}

require $projectRoot.'/vendor/autoload.php';

$app = require $projectRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = parseApplyOptions($argv);

try {
    $manifest = new DatabaseSyncManifest;
    (new TargetHostGuard)->assert($manifest);
    $lock = new ApplyLock;

    if ($lock->isLocked()) {
        throw new RuntimeException('Database sync apply lock is already held.');
    }

    $lock->acquire($options['generation_id']);

    try {
        $runner = new DeltaApplyRunner(
            new TableDeltaApplier(new UniqueConflictChecker, new TableCheckpointStore),
            $manifest,
        );

        $payload = $runner->applyGeneration($options['generation_id'], $options['tables']);
    } finally {
        $lock->release();
    }

    fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);

    exit(1);
}

/**
 * @param  list<string>  $argv
 * @return array{generation_id: string, tables: list<string>}
 */
function parseApplyOptions(array $argv): array
{
    $options = [
        'generation_id' => '',
        'tables' => [],
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if (! str_starts_with($argument, '--')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, null);

        match ($key) {
            'generation-id' => $options['generation_id'] = is_string($value) ? $value : '',
            'tables' => $options['tables'] = parseCsv($value),
            default => null,
        };
    }

    if ($options['generation_id'] === '') {
        throw new InvalidArgumentException('Missing --generation-id.');
    }

    return $options;
}

/**
 * @return list<string>
 */
function parseCsv(?string $value): array
{
    if (! is_string($value) || trim($value) === '') {
        return [];
    }

    return array_values(array_filter(array_map('trim', explode(',', $value))));
}
