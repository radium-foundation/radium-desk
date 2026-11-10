<?php

use App\Models\HardwareFulfilment;
use App\Models\User;
use App\Services\HardwareFulfilment\HardwareSerialAllocationService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/mysql_hardware_fulfilment_auto_invoice_bootstrap.php';

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

$credentials = hardware_fulfilment_mysql_credentials_from_env();
hardware_fulfilment_mysql_boot_app_with_auto_invoice($credentials);

if (! (bool) config('hardware_fulfilment.post_allocate_auto_statutory_invoice')
    || ! (bool) config('statutory_invoices.location_series.enabled')) {
    fwrite(STDERR, "Auto-invoice worker refused: post-allocate statutory issuance is off.\n");
    exit(2);
}

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
$action = (string) ($payload['action'] ?? 'allocate_mixed');
$actor = User::query()->findOrFail((int) $payload['actor_id']);
$fulfilment = HardwareFulfilment::query()->findOrFail((int) $payload['fulfilment_id']);
$allocation = app(HardwareSerialAllocationService::class);

try {
    $connectionId = DB::selectOne('select connection_id() as id')?->id;

    if ($action === 'allocate_quantity') {
        $allocated = $allocation->allocateQuantityStock($fulfilment->fresh(['commerceOrder.items']), $actor);
        file_put_contents($resultPath, json_encode([
            'ok' => true,
            'action' => $action,
            'fulfilment_id' => $allocated->id,
            'state' => $allocated->state?->value,
            'post_allocate_auto_statutory_invoice' => (bool) config('hardware_fulfilment.post_allocate_auto_statutory_invoice'),
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'connection_id' => $connectionId,
        ], JSON_THROW_ON_ERROR));

        exit(0);
    }

    $scannerItemId = (int) $payload['scanner_item_id'];
    $serials = $payload['serials'] ?? [];
    if (! is_array($serials)) {
        $serials = [];
    }
    $selections = [$scannerItemId => array_values(array_map('strval', $serials))];

    $allocated = $allocation->allocate($fulfilment->fresh(['commerceOrder.items']), $selections, $actor);

    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'action' => $action,
        'fulfilment_id' => $allocated->id,
        'state' => $allocated->state?->value,
        'post_allocate_auto_statutory_invoice' => (bool) config('hardware_fulfilment.post_allocate_auto_statutory_invoice'),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (ValidationException $exception) {
    $messages = $exception->errors();
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'action' => $action,
        'error' => json_encode($messages, JSON_THROW_ON_ERROR),
        'post_allocate_auto_statutory_invoice' => (bool) config('hardware_fulfilment.post_allocate_auto_statutory_invoice'),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => DB::selectOne('select connection_id() as id')?->id,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'action' => $action,
        'error' => $exception->getMessage(),
        'post_allocate_auto_statutory_invoice' => (bool) config('hardware_fulfilment.post_allocate_auto_statutory_invoice'),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => DB::selectOne('select connection_id() as id')?->id,
    ], JSON_THROW_ON_ERROR));
    exit(0);
}
