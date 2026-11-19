<?php

use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstB2cConversion;
use App\Models\User;
use App\Services\StatutoryInvoice\CommerceOrderBuyerGstB2cConversionService;
use App\Services\StatutoryInvoice\ServiceStatutoryGstB2bClassification;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/mysql_buyer_gst_b2c_conversion_bootstrap.php';

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

$credentials = buyer_gst_b2c_conversion_mysql_credentials_from_env();
buyer_gst_b2c_conversion_mysql_boot_app($credentials);

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
    $order = CommerceOrder::query()->findOrFail((int) ($payload['commerce_order_id'] ?? 0));
    $actor = User::query()->findOrFail((int) ($payload['actor_user_id'] ?? 0));

    $conversion = app(CommerceOrderBuyerGstB2cConversionService::class)->approve(
        $order,
        $actor,
        'Concurrent worker '.$workerLabel,
    );

    $decision = app(ServiceStatutoryGstB2bClassification::class)->resolveForServiceOrder($order->fresh());

    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'worker' => $workerLabel,
        'conversion_id' => $conversion->id,
        'conversion_count' => CommerceOrderBuyerGstB2cConversion::query()->count(),
        'buyer_gstin_for_mint_is_null' => $decision->buyerGstinForMint === null,
        'issue_as_b2b' => $decision->issueAsB2b,
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
