#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\DatabaseSync\SyncCursorStrategy;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Support\Facades\DB;

$projectRoot = dirname(__DIR__, 4);

if (! is_file($projectRoot.'/vendor/autoload.php')) {
    fwrite(STDERR, json_encode(['error' => 'Laravel project root not found.']).PHP_EOL);

    exit(1);
}

require $projectRoot.'/vendor/autoload.php';

$app = require $projectRoot.'/bootstrap/app.php';
$app->make(Kernel::class)->bootstrap();

$options = parseInspectOptions($argv);

try {
    $payload = match ($options['action']) {
        'migration-status' => migrationStatusPayload(),
        'table-stats' => tableStatsPayload($options),
        default => throw new InvalidArgumentException('Unsupported inspect action.'),
    };
} catch (Throwable $exception) {
    fwrite(STDOUT, json_encode([
        'error' => $exception->getMessage(),
    ], JSON_UNESCAPED_SLASHES).PHP_EOL);

    exit(1);
}

fwrite(STDOUT, json_encode($payload, JSON_UNESCAPED_SLASHES).PHP_EOL);

/**
 * @param  list<string>  $argv
 * @return array{
 *     action: string,
 *     table?: string,
 *     strategy?: string,
 *     primary_key?: list<string>,
 *     updated_at?: string|null,
 *     created_at?: string|null,
 * }
 */
function parseInspectOptions(array $argv): array
{
    $options = [
        'action' => '',
        'table' => null,
        'strategy' => null,
        'primary_key' => [],
        'updated_at' => null,
        'created_at' => null,
    ];

    foreach (array_slice($argv, 1) as $argument) {
        if (! str_starts_with($argument, '--')) {
            continue;
        }

        [$key, $value] = array_pad(explode('=', substr($argument, 2), 2), 2, null);

        match ($key) {
            'action' => $options['action'] = is_string($value) ? $value : '',
            'table' => $options['table'] = is_string($value) ? $value : null,
            'strategy' => $options['strategy'] = is_string($value) ? $value : null,
            'primary-key' => $options['primary_key'] = parseCsv($value),
            'updated-at' => $options['updated_at'] = is_string($value) ? $value : null,
            'created-at' => $options['created_at'] = is_string($value) ? $value : null,
            default => null,
        };
    }

    if ($options['action'] === '') {
        throw new InvalidArgumentException('Missing --action.');
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

/**
 * @return array<string, mixed>
 */
function migrationStatusPayload(): array
{
    if (! schemaHasTable('migrations')) {
        return [
            'error' => 'migrations table does not exist on this endpoint.',
        ];
    }

    $rows = DB::table('migrations')
        ->select(['migration', 'batch'])
        ->orderBy('migration')
        ->get();

    $migrations = [];

    foreach ($rows as $row) {
        if (! is_string($row->migration ?? null)) {
            continue;
        }

        $migrations[$row->migration] = (int) ($row->batch ?? 0);
    }

    return [
        'migrations' => $migrations,
    ];
}

/**
 * @param  array{
 *     action: string,
 *     table?: string|null,
 *     strategy?: string|null,
 *     primary_key?: list<string>,
 *     updated_at?: string|null,
 *     created_at?: string|null,
 * }  $options
 * @return array<string, mixed>
 */
function tableStatsPayload(array $options): array
{
    $table = $options['table'] ?? null;
    $strategyValue = $options['strategy'] ?? null;

    if (! is_string($table) || ! isValidIdentifier($table)) {
        throw new InvalidArgumentException('Invalid table name.');
    }

    if (! is_string($strategyValue) || $strategyValue === '') {
        throw new InvalidArgumentException('Missing strategy.');
    }

    $strategy = SyncCursorStrategy::tryFromConfig($strategyValue);

    if ($strategy === null) {
        throw new InvalidArgumentException('Unsupported strategy.');
    }

    if (! schemaHasTable($table)) {
        return [
            'error' => "Table [{$table}] does not exist on this endpoint.",
        ];
    }

    $primaryKey = $options['primary_key'] ?? [];

    foreach ($primaryKey as $column) {
        if (! isValidIdentifier($column)) {
            throw new InvalidArgumentException('Invalid primary key column.');
        }
    }

    $updatedAt = $options['updated_at'] ?? null;
    $createdAt = $options['created_at'] ?? null;

    if (is_string($updatedAt) && ! isValidIdentifier($updatedAt)) {
        throw new InvalidArgumentException('Invalid updated_at column.');
    }

    if (is_string($createdAt) && ! isValidIdentifier($createdAt)) {
        throw new InvalidArgumentException('Invalid created_at column.');
    }

    $select = ['COUNT(*) AS row_count'];
    $tableSql = quoteIdentifier($table);

    if ($strategy->supportsMaxPrimaryKey() && count($primaryKey) === 1) {
        $column = quoteIdentifier($primaryKey[0]);
        $select[] = "MIN({$column}) AS min_primary_key";
        $select[] = "MAX({$column}) AS max_primary_key";
    }

    if ($strategy->supportsMaxUpdatedAt() && is_string($updatedAt)) {
        $select[] = 'MAX('.quoteIdentifier($updatedAt).') AS max_updated_at';
    }

    if ($strategy->supportsMaxCreatedAt() && is_string($createdAt)) {
        $select[] = 'MAX('.quoteIdentifier($createdAt).') AS max_created_at';
    }

    $sql = 'SELECT '.implode(', ', $select)." FROM {$tableSql}";
    $row = DB::selectOne($sql);

    if ($row === null) {
        return [
            'count' => 0,
        ];
    }

    return [
        'count' => (int) ($row->row_count ?? 0),
        'min_primary_key' => $row->min_primary_key ?? null,
        'max_primary_key' => $row->max_primary_key ?? null,
        'max_updated_at' => isset($row->max_updated_at) ? (string) $row->max_updated_at : null,
        'max_created_at' => isset($row->max_created_at) ? (string) $row->max_created_at : null,
    ];
}

function schemaHasTable(string $table): bool
{
    return DB::getSchemaBuilder()->hasTable($table);
}

function isValidIdentifier(string $value): bool
{
    return (bool) preg_match('/^[a-z][a-z0-9_]*$/', $value);
}

function quoteIdentifier(string $value): string
{
    if (! isValidIdentifier($value)) {
        throw new InvalidArgumentException('Invalid SQL identifier.');
    }

    return '`'.$value.'`';
}
