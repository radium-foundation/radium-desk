<?php

use App\Enums\ApprovedRefundMethod;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundRequestService;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/mysql_refund_completion_bootstrap.php';

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

$credentials = refund_completion_mysql_credentials_from_env();
$deskReference = (string) ($payload['desk_reference'] ?? 'REF-CONC-WORKER');
refund_completion_mysql_boot_app($credentials, $deskReference);

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

try {
    $refund = RefundRequest::query()->findOrFail((int) ($payload['refund_id'] ?? 0));
    $actor = User::query()->findOrFail((int) ($payload['ops_user_id'] ?? 0));

    $isWallet = $refund->approved_refund_method === ApprovedRefundMethod::Wallet;
    $data = $isWallet ? [] : [
        'execution_reference_no' => 'UTR-'.$workerLabel.'-'.$payload['suffix'],
        'execution_transaction_id' => 'TXN-'.$workerLabel.'-'.$payload['suffix'],
    ];

    $request = Request::create('/refunds/'.$refund->id.'/complete', 'POST', $data);
    $request->setUserResolver(static fn () => $actor);

    app(RefundRequestService::class)->complete(
        refund: $refund,
        user: $actor,
        data: $data,
        request: $request,
    );

    $fresh = $refund->fresh();
    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'worker' => $workerLabel,
        'refund_status' => $fresh?->status?->value,
        'execution_reference_no' => $fresh?->execution_reference_no,
        'execution_transaction_id' => $fresh?->execution_transaction_id,
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));

    exit(0);
} catch (ValidationException $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'validation' => true,
        'worker' => $workerLabel,
        'error' => $exception::class,
        'message' => implode(' ', $exception->validator->errors()->all()),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));

    exit(0);
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'validation' => false,
        'worker' => $workerLabel,
        'error' => $exception::class,
        'message' => $exception->getMessage(),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));

    exit(1);
}
