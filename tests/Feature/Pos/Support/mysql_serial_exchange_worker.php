<?php

use App\Models\User;
use App\Services\Pos\PosCustomerRequestedSerialExchangeService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/../../Inventory/Support/mysql_inventory_pos_bootstrap.php';

$options = getopt('', ['payload:', 'ready:', 'go:', 'result:']);
$payloadPath = (string) ($options['payload'] ?? '');
$readyPath = (string) ($options['ready'] ?? '');
$goPath = (string) ($options['go'] ?? '');
$resultPath = (string) ($options['result'] ?? '');

if ($payloadPath === '' || $readyPath === '' || $goPath === '' || $resultPath === '') {
    fwrite(STDERR, "Worker requires --payload --ready --go --result\n");
    exit(2);
}

$payload = json_decode((string) file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);
if (! is_array($payload)) {
    fwrite(STDERR, "Invalid worker payload.\n");
    exit(2);
}

$credentials = inventory_pos_mysql_credentials_from_env();
inventory_pos_mysql_boot_app($credentials);

DB::statement('set session innodb_lock_wait_timeout = 50');
DB::statement('set session transaction isolation level repeatable read');

file_put_contents($readyPath, (string) getmypid());

$deadline = microtime(true) + 20;
while (! is_file($goPath)) {
    if (microtime(true) > $deadline) {
        file_put_contents($resultPath, json_encode([
            'ok' => false,
            'error' => 'Timed out waiting for the start barrier.',
        ], JSON_THROW_ON_ERROR));
        exit(1);
    }
    usleep(5000);
}

$started = microtime(true);
$actor = User::query()->findOrFail((int) $payload['actor_id']);
$service = app(PosCustomerRequestedSerialExchangeService::class);

try {
    $connectionId = DB::selectOne('select connection_id() as id')?->id;

    $base = [
        'inventory_sale_id' => (int) $payload['sale_id'],
        'original_serial_number' => (string) $payload['original_serial_number'],
        'replacement_serial_number' => (string) $payload['replacement_serial_number'],
        'idempotency_key' => (string) $payload['idempotency_key'],
        'exchange_reason' => (string) ($payload['exchange_reason'] ?? 'InnoDB concurrent exchange test'),
    ];

    if (($payload['action'] ?? 'execute') === 'statutory') {
        $exchange = $service->recordStatutoryApproval($actor, [
            ...$base,
            'statutory_treatment_decision' => (string) $payload['statutory_treatment_decision'],
            'statutory_evidence_reference' => (string) $payload['statutory_evidence_reference'],
        ]);

        file_put_contents($resultPath, json_encode([
            'ok' => true,
            'action' => 'statutory',
            'exchange_id' => $exchange->id,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'connection_id' => $connectionId,
        ], JSON_THROW_ON_ERROR));
        exit(0);
    }

    $exchange = $service->execute($actor, [
        ...$base,
        'physical_receipt_attestation_reason' => (string) $payload['physical_receipt_attestation_reason'],
    ]);

    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'action' => 'execute',
        'exchange_id' => $exchange->id,
        'executed' => $exchange->executed_at !== null,
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (ValidationException $exception) {
    $messages = $exception->errors();
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => json_encode($messages, JSON_THROW_ON_ERROR),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => DB::selectOne('select connection_id() as id')?->id,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $throwable) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => $throwable->getMessage(),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => DB::selectOne('select connection_id() as id')?->id,
    ], JSON_THROW_ON_ERROR));
    exit(0);
}
