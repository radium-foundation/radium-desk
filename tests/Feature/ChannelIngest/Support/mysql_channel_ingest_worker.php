<?php

use App\Enums\StatutoryInvoiceChannel;
use App\Services\ChannelIngest\ChannelIngestService;
use Illuminate\Support\Facades\DB;

require dirname(__DIR__, 2).'/Inventory/Support/mysql_inventory_pos_bootstrap.php';

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

config([
    'channel_ingest.auto_issue_invoice' => false,
    'channel_ingest.cutover_approved' => false,
    'statutory_invoices.series_code' => 'TEST',
    'statutory_invoices.number_format' => '{series}-{seq:5}',
    'statutory_invoices.post_finance_journals' => false,
]);

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
$channel = StatutoryInvoiceChannel::from((string) ($payload['channel'] ?? StatutoryInvoiceChannel::RdServiceIn->value));
$sourceId = (string) $payload['source_id'];

try {
    $connectionId = DB::selectOne('select connection_id() as id')?->id;
    $result = app(ChannelIngestService::class)->ingest(
        [
            'channel' => $channel->value,
            'source_type' => 'commerce_order',
            'source_id' => $sourceId,
            'payment_status' => 'paid',
            'currency' => 'INR',
            'customer' => ['name' => 'Concurrency'],
            'seller_gstin' => '07AAICP1128M1Z9',
            'place_of_supply_state' => 'Delhi',
            'lines' => [[
                'description' => 'Concurrency line',
                'qty' => 1,
                'unit_price' => 100,
                'hsn_sac' => '998313',
                'taxable_value' => 100,
                'tax_total' => 18,
                'line_total' => 118,
            ]],
        ],
        $channel,
    );

    file_put_contents($resultPath, json_encode([
        'ok' => $result->order !== null,
        'order_id' => $result->order?->id,
        'order_no' => $result->order?->order_no,
        'duplicate' => $result->duplicate,
        'invoice_id' => $result->order?->statutory_invoice_id,
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => $exception->getMessage(),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => DB::selectOne('select connection_id() as id')?->id,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
