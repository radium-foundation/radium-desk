<?php

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\CommerceOrder;
use App\Models\HardwareFulfilment;
use App\Services\ChannelIngest\Data\ChannelOrderIngestRequest;
use App\Services\ChannelIngest\Data\ChannelOrderLineDraft;
use App\Services\HardwareFulfilment\HardwareFulfilmentFoundationService;
use Illuminate\Support\Facades\DB;

require __DIR__.'/mysql_preship_bootstrap.php';

$options = getopt('', ['payload:', 'ready:', 'go:', 'result:', 'prelock::']);
$payloadPath = (string) ($options['payload'] ?? '');
$readyPath = (string) ($options['ready'] ?? '');
$goPath = (string) ($options['go'] ?? '');
$resultPath = (string) ($options['result'] ?? '');
$prelock = array_key_exists('prelock', $options);

if ($payloadPath === '' || $readyPath === '' || $goPath === '' || $resultPath === '') {
    fwrite(STDERR, "Worker requires --payload --ready --go --result\n");
    exit(2);
}

/** @var array<string, mixed> $payload */
$payload = json_decode((string) file_get_contents($payloadPath), true, flags: JSON_THROW_ON_ERROR);

$credentials = preship_mysql_credentials_from_env();
preship_mysql_boot_app($credentials);

DB::statement('set session innodb_lock_wait_timeout = 50');

$lockedCommerce = false;
if ($prelock) {
    DB::beginTransaction();
    CommerceOrder::query()->whereKey((int) $payload['commerce_order_id'])->lockForUpdate()->firstOrFail();
    $lockedCommerce = true;
}

file_put_contents($readyPath, (string) getmypid());

$deadline = microtime(true) + 30;
while (! is_file($goPath)) {
    if (microtime(true) > $deadline) {
        if ($lockedCommerce) {
            DB::rollBack();
        }
        file_put_contents($resultPath, json_encode(['ok' => false, 'error' => 'barrier_timeout'], JSON_THROW_ON_ERROR));
        exit(1);
    }
    usleep(5000);
}

$connectionId = DB::selectOne('select connection_id() as id')?->id;

try {
    $commerce = CommerceOrder::query()->with('items')->findOrFail((int) $payload['commerce_order_id']);
    $request = new ChannelOrderIngestRequest(
        channel: StatutoryInvoiceChannel::RadiumBoxCom,
        sourceType: StatutoryInvoiceSourceType::CommerceOrder,
        sourceId: (string) $payload['source_id'],
        lines: [
            new ChannelOrderLineDraft(
                description: 'MFS110',
                sku: '946',
                qty: 1,
                unitPrice: 2499,
                hsnSac: '84716050',
                gstPercentage: 18,
                taxableValue: 2117.80,
                taxTotal: 381.20,
                lineTotal: 2499,
                shippingLineKind: 'physical_merchandise',
                requiresShipping: true,
                modelId: 946,
            ),
        ],
        paymentStatus: 'paid',
        currency: 'INR',
        paymentReference: 'cf-'.(string) $payload['source_id'],
        supportOrderId: (int) $payload['order_id'],
    );

    $service = app(HardwareFulfilmentFoundationService::class);
    $result = $service->ensureIngested($commerce, $request);

    if ($lockedCommerce) {
        DB::commit();
    }

    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'role' => 'ingest',
        'connection_id' => $connectionId,
        'prelock' => $prelock,
        'fulfilment_id' => $result?->id,
        'fulfilment_count' => HardwareFulfilment::query()->count(),
        'blocked_at' => $commerce->fresh()?->hardware_fulfilment_blocked_at?->toIso8601String(),
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    if ($lockedCommerce && DB::transactionLevel() > 0) {
        DB::rollBack();
    }

    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'role' => 'ingest',
        'connection_id' => $connectionId,
        'prelock' => $prelock,
        'error' => $exception::class,
        'message' => $exception->getMessage(),
        'fulfilment_count' => HardwareFulfilment::query()->count(),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
