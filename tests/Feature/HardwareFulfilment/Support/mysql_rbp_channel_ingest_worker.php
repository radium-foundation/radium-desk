<?php

use App\Enums\StatutoryInvoiceChannel;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Services\ChannelIngest\ChannelIngestService;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/mysql_hardware_fulfilment_bootstrap.php';

$options = getopt('', ['payload:', 'ready:', 'go:', 'result:']);
$payloadPath = (string) ($options['payload'] ?? '');
$readyPath = (string) ($options['ready'] ?? '');
$goPath = (string) ($options['go'] ?? '');
$resultPath = (string) ($options['result'] ?? '');

if ($payloadPath === '' || $readyPath === '' || $goPath === '' || $resultPath === '') {
    fwrite(STDERR, "Worker requires --payload --ready --go --result\n");
    exit(2);
}

/** @var array<string, mixed> $payload */
$payload = json_decode((string) file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);

$credentials = hardware_fulfilment_mysql_credentials_from_env();
hardware_fulfilment_mysql_boot_app($credentials);

config([
    'channel_ingest.secrets.radiumbox_com' => 'innodb-rbp-ingest-secret',
    'channel_ingest.auto_issue_invoice' => false,
    'hardware_fulfilment.correlate_cashfree' => false,
]);

DB::statement('set session innodb_lock_wait_timeout = 50');

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
$connectionId = DB::selectOne('select connection_id() as id')?->id;

try {
    $ingestPayload = is_array($payload['ingest_payload'] ?? null) ? $payload['ingest_payload'] : $payload;
    $result = app(ChannelIngestService::class)->ingest($ingestPayload, StatutoryInvoiceChannel::RadiumBoxCom);

    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'http_status' => $result->httpStatus,
        'duplicate' => $result->duplicate,
        'commerce_count' => CommerceOrder::query()->count(),
        'fulfilment_count' => HardwareFulfilment::query()->count(),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (ValidationException $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => 'validation',
        'messages' => $exception->errors(),
        'commerce_count' => CommerceOrder::query()->count(),
        'fulfilment_count' => HardwareFulfilment::query()->count(),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));
    exit(1);
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => $exception::class,
        'message' => $exception->getMessage(),
        'commerce_count' => CommerceOrder::query()->count(),
        'fulfilment_count' => HardwareFulfilment::query()->count(),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}