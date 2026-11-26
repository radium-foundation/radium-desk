<?php

use Illuminate\Support\Facades\DB;

require __DIR__.'/mysql_p01_60_reservation_bootstrap.php';

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

$credentials = p01_60_reservation_mysql_credentials_from_env();
$integrationToken = (string) ($payload['integration_token'] ?? '');
$app = p01_60_reservation_mysql_boot_app($credentials, $integrationToken);

DB::statement('set session innodb_lock_wait_timeout = 50');
DB::statement('set session transaction isolation level repeatable read');

file_put_contents($readyPath, (string) getmypid());

$deadline = microtime(true) + 25;
while (! is_file($goPath)) {
    if (microtime(true) > $deadline) {
        file_put_contents($resultPath, json_encode([
            'ok' => false,
            'error' => 'Timed out waiting for the start barrier.',
        ], JSON_THROW_ON_ERROR));
        exit(1);
    }
    usleep(2000);
}

$started = microtime(true);
$connectionId = DB::selectOne('select connection_id() as id')?->id;
$workerLabel = (string) ($payload['worker_label'] ?? 'w0');
$scenario = (string) ($payload['scenario'] ?? 'competing_reserve');

try {
    $httpContext = [
        'integration_token' => $integrationToken,
        'site_code' => (string) ($payload['site_code'] ?? 'radiumbox.com'),
    ];

    if ($scenario === 'competing_reserve') {
        $response = p01_60_reservation_http_post($app, $httpContext, '/api/central-wallet/v1/wallet-reservations', [
            'idempotency_key' => 'reserve-'.$workerLabel.'-'.($payload['suffix'] ?? 'x'),
            'central_wallet_id' => (string) $payload['central_wallet_id'],
            'amount' => (string) ($payload['reserve_amount'] ?? '20.00'),
            'business_reference' => 'order:CONC-'.$workerLabel.'-'.($payload['suffix'] ?? 'x'),
            'spend_authorization' => $payload['spend_authorization'] ?? null,
        ]);

        $body = is_array($response['body']) ? $response['body'] : [];
        $created = ($response['http_status'] ?? 0) === 201 && ($body['state'] ?? '') === 'active';
        $insufficient = ($response['http_status'] ?? 0) === 422
            && (($body['error'] ?? '') === 'insufficient_balance' || str_contains((string) ($body['message'] ?? ''), 'Insufficient'));

        $diagnostics = [];
        if (($response['http_status'] ?? 0) >= 500) {
            $diagnostics = array_filter([
                'exception_class' => $response['exception_class'] ?? null,
                'exception_message' => $response['exception_message'] ?? null,
                'sql_state' => $response['sql_state'] ?? null,
                'sql_vendor_code' => $response['sql_vendor_code'] ?? null,
                'response_raw' => isset($response['raw']) ? substr((string) $response['raw'], 0, 500) : null,
            ]);
        }

        file_put_contents($resultPath, json_encode(array_merge([
            'ok' => $created || $insufficient,
            'validation' => $insufficient,
            'created' => $created,
            'http_status' => $response['http_status'] ?? 0,
            'error' => $body['error'] ?? null,
            'reservation_id' => $body['reservation_id'] ?? null,
            'worker' => $workerLabel,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'connection_id' => $connectionId,
        ], $diagnostics), JSON_THROW_ON_ERROR));
        exit(0);
    }

    if ($scenario === 'concurrent_commit') {
        $reservationId = (string) ($payload['reservation_id'] ?? '');
        $idemKey = (string) ($payload['commit_idempotency_key'] ?? 'commit');
        if ($workerLabel === 'w1') {
            $idemKey .= '-alt';
        }

        $response = p01_60_reservation_http_post(
            $app,
            $httpContext,
            '/api/central-wallet/v1/wallet-reservations/'.$reservationId.'/commit',
            ['idempotency_key' => $idemKey],
        );

        $body = is_array($response['body']) ? $response['body'] : [];
        $httpStatus = (int) ($response['http_status'] ?? 0);
        $ok = in_array($httpStatus, [200, 201], true);
        $idempotentReplay = ($body['idempotent_replay'] ?? false) === true;

        $diagnostics = [];
        if ($httpStatus >= 500) {
            $diagnostics = array_filter([
                'exception_class' => $response['exception_class'] ?? null,
                'exception_message' => $response['exception_message'] ?? null,
                'sql_state' => $response['sql_state'] ?? null,
                'sql_vendor_code' => $response['sql_vendor_code'] ?? null,
                'response_raw' => isset($response['raw']) ? substr((string) $response['raw'], 0, 500) : null,
            ]);
        }

        file_put_contents($resultPath, json_encode(array_merge([
            'ok' => $ok || $idempotentReplay,
            'http_status' => $httpStatus,
            'idempotent_replay' => $idempotentReplay,
            'state' => $body['state'] ?? null,
            'ledger_entry_id' => $body['ledger_entry_id'] ?? null,
            'worker' => $workerLabel,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            'connection_id' => $connectionId,
        ], $diagnostics), JSON_THROW_ON_ERROR));
        exit(0);
    }

    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => 'Unknown scenario '.$scenario,
    ], JSON_THROW_ON_ERROR));
    exit(1);
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
        'worker' => $workerLabel,
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
