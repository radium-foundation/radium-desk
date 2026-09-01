<?php

use App\Models\InventoryBranch;
use App\Models\User;
use App\Services\Inventory\PosSaleService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/mysql_inventory_pos_bootstrap.php';

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

$qty = max(1, (int) ($payload['qty'] ?? 1));
$line = [
    'product_id' => (int) $payload['product_id'],
    'qty' => $qty,
];
if (isset($payload['serial']) && is_string($payload['serial']) && $payload['serial'] !== '') {
    $line['serials'] = [$payload['serial']];
}

try {
    $sale = app(PosSaleService::class)->completeSale(
        branch: InventoryBranch::query()->findOrFail((int) $payload['branch_id']),
        customer: [
            'name' => (string) $payload['customer_name'],
            'phone' => (string) $payload['customer_phone'],
        ],
        lines: [$line],
        paymentMethod: 'Cash',
        actor: User::query()->findOrFail((int) $payload['actor_id']),
        idempotencyKey: isset($payload['idempotency_key']) ? (string) $payload['idempotency_key'] : null,
    );

    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'sale_id' => $sale->id,
        'invoice_number' => $sale->invoice_number,
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => DB::selectOne('select connection_id() as id')?->id,
    ], JSON_THROW_ON_ERROR));
} catch (ValidationException $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => collect($exception->errors())->flatten()->implode(' '),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => DB::selectOne('select connection_id() as id')?->id,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    $connectionId = null;
    try {
        $connectionId = DB::selectOne('select connection_id() as id')?->id;
    } catch (Throwable) {
        $connectionId = null;
    }

    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));
    exit(0);
}
