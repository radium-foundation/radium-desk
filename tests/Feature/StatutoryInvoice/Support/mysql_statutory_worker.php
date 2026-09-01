<?php

use App\Enums\StatutoryInvoiceChannel;
use App\Enums\StatutoryInvoiceSourceType;
use App\Models\User;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceLineDraft;
use App\Services\StatutoryInvoice\Data\StatutoryInvoiceMintRequest;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
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
    'statutory_invoices.series_code' => 'TEST',
    'statutory_invoices.number_format' => '{series}-{seq:5}',
    'statutory_invoices.document_type' => 'tax_invoice',
    'statutory_invoices.post_finance_journals' => false,
    'statutory_invoices.auto_issue_on_pos_complete' => false,
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
$actor = User::query()->findOrFail((int) $payload['actor_id']);
$channel = StatutoryInvoiceChannel::from((string) ($payload['channel'] ?? StatutoryInvoiceChannel::DeskPos->value));

try {
    $connectionId = DB::selectOne('select connection_id() as id')?->id;
    $invoice = app(StatutoryInvoiceService::class)->mint(
        new StatutoryInvoiceMintRequest(
            channel: $channel,
            sourceType: StatutoryInvoiceSourceType::External,
            sourceId: (string) $payload['source_id'],
            lines: [
                new StatutoryInvoiceLineDraft(
                    description: 'Concurrency line',
                    qty: 1,
                    unitPrice: 100,
                    gstPercentage: 18,
                    taxTotal: 18,
                    lineTotal: 118,
                    taxableValue: 100,
                ),
            ],
        ),
        $actor,
    );

    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'invoice_id' => $invoice->id,
        'invoice_number' => $invoice->invoice_number,
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
