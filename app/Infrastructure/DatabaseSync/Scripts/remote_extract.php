#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\DatabaseSync\ConsistentSnapshotSession;
use App\Infrastructure\DatabaseSync\CursorPredicateBuilder;
use App\Infrastructure\DatabaseSync\DatabaseSyncManifest;
use App\Infrastructure\DatabaseSync\DeltaExtractRunner;
use App\Infrastructure\DatabaseSync\SyncTableDefinition;
use Illuminate\Contracts\Console\Kernel;

$projectRoot = dirname(__DIR__, 4);

if (! is_file($projectRoot.'/vendor/autoload.php')) {
    fwrite(STDERR, json_encode(['error' => 'Laravel project root not found.']).PHP_EOL);

    exit(1);
}

require $projectRoot.'/vendor/autoload.php';

$app = require $projectRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = parseExtractOptions($argv);

try {
    $manifest = new DatabaseSyncManifest;

    if ($options['checkpoints_file'] === '') {
        throw new InvalidArgumentException('Missing --checkpoints-file. Extract must use VPS-authoritative checkpoints.');
    }

    $checkpointPayload = json_decode((string) file_get_contents($options['checkpoints_file']), true);

    if (! is_array($checkpointPayload)) {
        throw new InvalidArgumentException('VPS checkpoints file is invalid JSON.');
    }

    $checkpoints = $checkpointPayload['checkpoints'] ?? $checkpointPayload;

    if (! is_array($checkpoints)) {
        throw new InvalidArgumentException('VPS checkpoints file is missing a checkpoints map.');
    }

    $runner = new DeltaExtractRunner(new CursorPredicateBuilder, new ConsistentSnapshotSession);
    $outputDirectory = rtrim((string) config('database-sync.inbox_directory', storage_path('app/private/db-sync/inbox')), '/')
        .'/'.$options['generation_id'];

    $tables = [];

    foreach ($manifest->tablesInSyncOrder() as $definition) {
        if ($options['tables'] !== [] && ! in_array($definition->name, $options['tables'], true)) {
            continue;
        }

        $tables[] = $definition;
    }

    $extracted = $runner->extractGeneration(
        $tables,
        $options['generation_id'],
        $outputDirectory,
        $checkpoints,
    );

    $manifestPath = $outputDirectory.'/'.$options['generation_id'].'.extract.json';
    $payload = [
        'generation_id' => $options['generation_id'],
        'direction' => $manifest->direction,
        'tables' => $extracted['tables'] ?? [],
        'snapshot_at' => $extracted['snapshot_at'] ?? null,
    ];

    file_put_contents($manifestPath, json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES).PHP_EOL, LOCK_EX);

    fwrite(STDOUT, json_encode([
        'generation_id' => $options['generation_id'],
        'manifest_path' => $manifestPath,
        'tables' => array_map(static fn (SyncTableDefinition $table): string => $table->name, $tables),
        'snapshot_at' => $extracted['snapshot_at'] ?? null,
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);

    exit(1);
}

/**
 * @param  list<string>  $argv
 * @return array{generation_id: string, tables: list<string>, checkpoints_file: string}
 */
function parseExtractOptions(array $argv): array
{
    $options = [
        'generation_id' => '',
        'tables' => [],
        'checkpoints_file' => '',
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if (! str_starts_with($argument, '--')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, null);

        match ($key) {
            'generation-id' => $options['generation_id'] = is_string($value) ? $value : '',
            'tables' => $options['tables'] = parseCsv($value),
            'checkpoints-file' => $options['checkpoints_file'] = is_string($value) ? $value : '',
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
