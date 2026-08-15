#!/usr/bin/env php
<?php

declare(strict_types=1);

use App\Infrastructure\DatabaseSync\SyncCursorStrategy;
use App\Infrastructure\DatabaseSync\TableCheckpointStore;
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
        'table-columns' => tableColumnsPayload($options),
        'table-indexes' => tableIndexesPayload($options),
        'reference-sequences' => referenceSequencesPayload(),
        'soft-delete-count' => softDeleteCountPayload($options),
        'dark-status' => darkStatusPayload(),
        'export-checkpoints' => exportCheckpointsPayload(),
        'reconciliation-readonly' => reconciliationReadonlyPayload(),
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

/**
 * @param  array{table?: string|null}  $options
 * @return array<string, mixed>
 */
function tableColumnsPayload(array $options): array
{
    $table = $options['table'] ?? null;

    if (! is_string($table) || ! isValidIdentifier($table) || ! schemaHasTable($table)) {
        throw new InvalidArgumentException('Invalid table name.');
    }

    $rows = DB::select(
        'SELECT COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE
         FROM information_schema.COLUMNS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         ORDER BY ORDINAL_POSITION',
        [$table],
    );

    $columns = [];

    foreach ($rows as $row) {
        if (! is_string($row->COLUMN_NAME ?? null)) {
            continue;
        }

        $nullable = ($row->IS_NULLABLE ?? 'NO') === 'YES' ? 'nullable' : 'not_null';
        $columns[$row->COLUMN_NAME] = ($row->COLUMN_TYPE ?? 'unknown').':'.$nullable;
    }

    return ['columns' => $columns];
}

/**
 * @param  array{table?: string|null}  $options
 * @return array<string, mixed>
 */
function tableIndexesPayload(array $options): array
{
    $table = $options['table'] ?? null;

    if (! is_string($table) || ! isValidIdentifier($table) || ! schemaHasTable($table)) {
        throw new InvalidArgumentException('Invalid table name.');
    }

    $rows = DB::select(
        'SELECT INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
         FROM information_schema.STATISTICS
         WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = ?
         ORDER BY INDEX_NAME, SEQ_IN_INDEX',
        [$table],
    );

    $grouped = [];

    foreach ($rows as $row) {
        if ((int) ($row->NON_UNIQUE ?? 1) !== 0 || ! is_string($row->INDEX_NAME ?? null) || ! is_string($row->COLUMN_NAME ?? null)) {
            continue;
        }

        $grouped[$row->INDEX_NAME][] = $row->COLUMN_NAME;
    }

    $indexes = [];

    foreach ($grouped as $columns) {
        $indexes[implode('|', $columns)] = $columns;
    }

    return ['indexes' => $indexes];
}

/**
 * @return array<string, mixed>
 */
function referenceSequencesPayload(): array
{
    if (! schemaHasTable('reference_sequences')) {
        return ['sequences' => []];
    }

    $rows = DB::table('reference_sequences')->select(['name', 'current_value'])->get();
    $sequences = [];

    foreach ($rows as $row) {
        if (is_string($row->name ?? null)) {
            $sequences[$row->name] = (int) ($row->current_value ?? 0);
        }
    }

    return ['sequences' => $sequences];
}

/**
 * @param  array{table?: string|null}  $options
 * @return array<string, mixed>
 */
function softDeleteCountPayload(array $options): array
{
    $table = $options['table'] ?? null;

    if (! is_string($table) || ! isValidIdentifier($table) || ! schemaHasTable($table)) {
        throw new InvalidArgumentException('Invalid table name.');
    }

    $tableSql = quoteIdentifier($table);

    return [
        'count' => (int) (DB::selectOne("SELECT COUNT(*) AS row_count FROM {$tableSql} WHERE deleted_at IS NOT NULL")->row_count ?? 0),
    ];
}

/**
 * @return array<string, mixed>
 */
function darkStatusPayload(): array
{
    $configured = config('database-sync.forbidden_vps_commands');
    $commands = is_array($configured)
        ? array_values(array_filter($configured, static fn ($command): bool => is_string($command) && $command !== ''))
        : ['queue:work', 'schedule:run', 'outbox:process'];

    $processLines = [];
    $exitCode = 1;
    exec('ps -ax -o args= 2>/dev/null', $processLines, $exitCode);

    if ($exitCode !== 0 || $processLines === []) {
        return [
            'error' => 'Unable to list VPS processes for dark-status.',
        ];
    }

    $active = [];

    foreach ($commands as $command) {
        foreach ($processLines as $line) {
            if (! is_string($line) || ! str_contains($line, $command)) {
                continue;
            }

            if (str_contains($line, 'remote_inspect.php')) {
                continue;
            }

            $active[] = $command;

            break;
        }
    }

    return [
        'active' => $active,
        'dark' => $active === [],
        'process_list_ok' => true,
        'process_count' => count($processLines),
        'operational_note' => 'Process list does not prove DNS, cron, or scheduler configuration.',
    ];
}

/**
 * @return array<string, mixed>
 */
function exportCheckpointsPayload(): array
{
    return [
        'authority' => 'vps',
        'checkpoints' => (new TableCheckpointStore)->exportAll(),
    ];
}

/**
 * @return array<string, mixed>
 */
function reconciliationReadonlyPayload(): array
{
    $ordersCount = schemaHasTable('orders')
        ? (int) (DB::selectOne('SELECT COUNT(*) AS row_count FROM '.quoteIdentifier('orders'))->row_count ?? 0)
        : 0;

    $webhookCount = 0;

    if (schemaHasTable('cashfree_webhook_logs')) {
        $webhookCount = (int) (DB::selectOne('SELECT COUNT(*) AS row_count FROM '.quoteIdentifier('cashfree_webhook_logs'))->row_count ?? 0);
    }

    return [
        'mode' => 'read_only',
        'orders_count' => $ordersCount,
        'cashfree_webhook_logs_count' => $webhookCount,
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
