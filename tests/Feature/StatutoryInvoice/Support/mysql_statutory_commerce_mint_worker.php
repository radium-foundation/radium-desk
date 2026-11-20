<?php

use App\Enums\OutboxEventStatus;
use App\Models\CommerceOrder;
use App\Models\CommerceOrderBuyerGstB2cConversion;
use App\Models\InvoiceSequenceAllocation;
use App\Models\OutboxEvent;
use App\Models\RadiumBoxStatutoryReceiptPublication;
use App\Models\StatutoryInvoice;
use App\Models\User;
use App\Services\RadiumBox\RadiumBoxStatutoryReceiptPublicationOutboxWriter;
use App\Services\StatutoryInvoice\StatutoryInvoiceService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

require __DIR__.'/mysql_statutory_commerce_mint_bootstrap.php';

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

$credentials = statutory_commerce_mint_mysql_credentials_from_env();
statutory_commerce_mint_mysql_boot_app($credentials);

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
$operation = (string) ($payload['operation'] ?? 'mint');
$actor = User::query()->findOrFail((int) ($payload['actor_user_id'] ?? 0));
$invoices = app(StatutoryInvoiceService::class);

function issueCommerceWithDeadlockRetry(
    StatutoryInvoiceService $invoices,
    CommerceOrder $order,
    User $actor,
): StatutoryInvoice {
    $attempts = 0;
    while (true) {
        try {
            return $invoices->issueFromCommerceOrder($order->fresh(['items']), $actor);
        } catch (QueryException $exception) {
            $attempts++;
            if ($attempts >= 5 || ! str_contains($exception->getMessage(), '1213')) {
                throw $exception;
            }
        }
    }
}

try {
    $invoice = null;

    if ($operation === 'enqueue' || $operation === 'enqueue_twice') {
        $invoice = StatutoryInvoice::query()->findOrFail((int) ($payload['statutory_invoice_id'] ?? 0));
        $invoices->queueEinvoiceIfEligible($invoice->fresh());
        if ($operation === 'enqueue_twice') {
            $invoices->queueEinvoiceIfEligible($invoice->fresh());
        }
    } elseif ($operation === 'mint' || $operation === 'mint_twice') {
        $orderId = (int) ($payload['commerce_order_id'] ?? 0);
        $order = CommerceOrder::query()->with('items')->findOrFail($orderId);
        $invoice = issueCommerceWithDeadlockRetry($invoices, $order->fresh(['items']), $actor);
        if ($operation === 'mint_twice') {
            $invoice = issueCommerceWithDeadlockRetry($invoices, $order->fresh(['items']), $actor);
        }
    } else {
        throw new RuntimeException('Unknown operation: '.$operation);
    }

    $orderIdForConversion = (int) ($payload['commerce_order_id'] ?? 0);
    $conversion = $orderIdForConversion > 0
        ? CommerceOrderBuyerGstB2cConversion::query()->where('commerce_order_id', $orderIdForConversion)->first()
        : null;

    if ($conversion === null && $invoice !== null) {
        $conversion = CommerceOrderBuyerGstB2cConversion::query()
            ->where('statutory_invoice_id', $invoice->id)
            ->first();
    }

    $outboxKey = $invoice !== null
        ? RadiumBoxStatutoryReceiptPublicationOutboxWriter::outboxIdempotencyKey($invoice->id)
        : null;

    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'worker' => $workerLabel,
        'operation' => $operation,
        'invoice_id' => $invoice?->id,
        'invoice_number' => $invoice?->invoice_number,
        'buyer_gstin' => $invoice?->buyer_gstin,
        'statutory_invoice_count' => StatutoryInvoice::query()->count(),
        'allocation_count' => InvoiceSequenceAllocation::query()->count(),
        'conversion_statutory_invoice_id' => $conversion?->statutory_invoice_id,
        'publication_count' => RadiumBoxStatutoryReceiptPublication::query()->count(),
        'outbox_receipt_count' => $outboxKey === null
            ? 0
            : OutboxEvent::query()->where('idempotency_key', $outboxKey)->count(),
        'outbox_receipt_completed' => $outboxKey === null
            ? 0
            : OutboxEvent::query()
                ->where('idempotency_key', $outboxKey)
                ->where('status', OutboxEventStatus::Completed)
                ->count(),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));

    exit(0);
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'worker' => $workerLabel,
        'operation' => $operation,
        'error' => $exception::class,
        'message' => $exception->getMessage(),
        'statutory_invoice_count' => StatutoryInvoice::query()->count(),
        'publication_count' => RadiumBoxStatutoryReceiptPublication::query()->count(),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));

    exit(1);
}
