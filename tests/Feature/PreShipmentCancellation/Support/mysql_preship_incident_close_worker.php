<?php

use App\Models\PreShipmentCancellationRequest;
use App\Models\User;
use App\Services\PreShipmentCancellation\PreShipmentCancellationIncidentService;
use Illuminate\Support\Facades\DB;

require __DIR__.'/mysql_preship_bootstrap.php';

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

$credentials = preship_mysql_credentials_from_env();
preship_mysql_boot_app($credentials);

DB::statement('set session innodb_lock_wait_timeout = 50');

file_put_contents($readyPath, (string) getmypid());

$deadline = microtime(true) + 30;
while (! is_file($goPath)) {
    if (microtime(true) > $deadline) {
        file_put_contents($resultPath, json_encode(['ok' => false, 'error' => 'barrier_timeout'], JSON_THROW_ON_ERROR));
        exit(1);
    }
    usleep(5000);
}

$connectionId = DB::selectOne('select connection_id() as id')?->id;

try {
    $actor = User::query()->findOrFail((int) $payload['actor_id']);
    $case = PreShipmentCancellationRequest::query()->findOrFail((int) $payload['case_id']);
    $refreshed = app(PreShipmentCancellationIncidentService::class)->retryClose($case, $actor);

    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'role' => 'incident_close',
        'connection_id' => $connectionId,
        'case_id' => $refreshed->id,
        'service_case_sync_status' => $refreshed->service_case_sync_status?->value,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'role' => 'incident_close',
        'connection_id' => $connectionId,
        'error' => $exception::class,
        'message' => $exception->getMessage(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
