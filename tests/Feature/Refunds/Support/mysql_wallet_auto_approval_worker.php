<?php

use App\Enums\ApprovedRefundMethod;
use App\Enums\CustomerPreferredRefundMethod;
use App\Enums\RefundDeductionProfile;
use App\Models\Order;
use App\Models\RefundRequest;
use App\Models\User;
use App\Services\RefundCalculationService;
use App\Services\RefundRequestService;
use Illuminate\Database\QueryException;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;

require __DIR__.'/mysql_wallet_auto_approval_bootstrap.php';

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
$workerLabel = (string) ($payload['worker_label'] ?? 'w0');
/** @var array<string, mixed> $workerPayload */
$workerPayload = $payload[$workerLabel] ?? $payload['worker'.substr($workerLabel, -1)] ?? [];

$credentials = wallet_auto_approval_mysql_credentials_from_env();
wallet_auto_approval_mysql_boot_app($credentials);

DB::statement('set session innodb_lock_wait_timeout = 50');
DB::statement('set session transaction isolation level repeatable read');

file_put_contents($readyPath, (string) getmypid());

$deadline = microtime(true) + 25;
while (! is_file($goPath)) {
    if (microtime(true) > $deadline) {
        file_put_contents($resultPath, json_encode(['ok' => false, 'error' => 'barrier timeout'], JSON_THROW_ON_ERROR));
        exit(1);
    }
    usleep(2000);
}

$started = microtime(true);
$connectionId = DB::selectOne('select connection_id() as id')?->id;
$action = (string) ($workerPayload['action'] ?? 'create');

try {
    $agent = User::query()->findOrFail((int) ($payload['agent_id'] ?? 0));
    $service = app(RefundRequestService::class);
    $request = Request::create('/');

    if ($action === 'create') {
        $orderId = (int) ($workerPayload['order_id'] ?? $payload['order_id'] ?? 0);
        $order = Order::query()->findOrFail($orderId);

        $createPayload = [
            'order_id' => $order->id,
            'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
            'reason' => 'Concurrent wallet auto-approval probe '.$workerLabel,
            'remarks' => 'MariaDB concurrency worker.',
            'customer_preferred_method' => CustomerPreferredRefundMethod::Wallet->value,
        ];

        $refund = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $refund = $service->create($agent, $createPayload, $request);
                break;
            } catch (QueryException $exception) {
                $isDeadlock = str_contains($exception->getMessage(), 'Deadlock')
                    || (string) $exception->getCode() === '40001';
                if (! $isDeadlock || $attempt === 3) {
                    throw $exception;
                }
                usleep(25_000 * $attempt);
            } catch (ValidationException $exception) {
                file_put_contents($resultPath, json_encode([
                    'ok' => false,
                    'validation' => true,
                    'worker' => $workerLabel,
                    'message' => $exception->getMessage(),
                    'errors' => $exception->errors(),
                    'connection_id' => $connectionId,
                    'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
                ], JSON_THROW_ON_ERROR));
                exit(0);
            }
        }

        $fresh = $refund?->fresh();
        file_put_contents($resultPath, json_encode([
            'ok' => true,
            'worker' => $workerLabel,
            'refund_id' => $fresh?->id,
            'refund_status' => $fresh?->status?->value,
            'approved_method' => $fresh?->approved_refund_method?->value,
            'order_id' => $orderId,
            'connection_id' => $connectionId,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ], JSON_THROW_ON_ERROR));
        exit(0);
    }

    if ($action === 'auto_promote') {
        $refund = RefundRequest::query()->findOrFail((int) ($payload['refund_id'] ?? 0));
        $order = Order::query()->findOrFail($refund->order_id);
        $promoted = null;

        try {
            for ($attempt = 1; $attempt <= 3; $attempt++) {
                try {
                    DB::transaction(function () use ($service, $agent, $refund, $order, $request, &$promoted): void {
                        $lockedOrder = Order::query()->lockForUpdate()->findOrFail($order->id);
                        $calculation = app(RefundCalculationService::class)->calculate($lockedOrder, [
                            'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
                            'refund_amount' => $refund->refund_amount ?? $refund->amount,
                        ], $refund);

                        $reflection = new ReflectionMethod(
                            RefundRequestService::class,
                            'attemptAutoWalletApprovalWithinCreateTransaction',
                        );
                        $reflection->setAccessible(true);
                        $didPromote = (bool) $reflection->invoke(
                            $service,
                            $refund,
                            $lockedOrder,
                            $agent,
                            $calculation,
                            $request,
                        );

                        if (! $didPromote) {
                            throw ValidationException::withMessages([
                                'refund' => 'Automatic wallet promotion was not eligible for this refund.',
                            ]);
                        }

                        $promoted = $refund->fresh();
                    });
                    break;
                } catch (QueryException $exception) {
                    $isDeadlock = str_contains($exception->getMessage(), 'Deadlock')
                        || (string) $exception->getCode() === '40001';
                    if (! $isDeadlock || $attempt === 3) {
                        throw $exception;
                    }
                    usleep(25_000 * $attempt);
                }
            }

            $fresh = $promoted;
            file_put_contents($resultPath, json_encode([
                'ok' => true,
                'worker' => $workerLabel,
                'refund_id' => $fresh?->id,
                'refund_status' => $fresh?->status?->value,
                'approved_method' => $fresh?->approved_refund_method?->value,
                'approval_source' => 'auto',
                'connection_id' => $connectionId,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ], JSON_THROW_ON_ERROR));
        } catch (ValidationException $exception) {
            file_put_contents($resultPath, json_encode([
                'ok' => false,
                'validation' => true,
                'worker' => $workerLabel,
                'message' => $exception->getMessage(),
                'connection_id' => $connectionId,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ], JSON_THROW_ON_ERROR));
        } catch (QueryException $exception) {
            file_put_contents($resultPath, json_encode([
                'ok' => false,
                'validation' => true,
                'deadlock' => true,
                'worker' => $workerLabel,
                'message' => $exception->getMessage(),
                'connection_id' => $connectionId,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ], JSON_THROW_ON_ERROR));
        }

        exit(0);
    }

    if ($action === 'approve') {
        $ops = User::query()->findOrFail((int) ($payload['ops_user_id'] ?? 0));
        $refundId = (int) ($workerPayload['refund_id'] ?? $payload['refund_id'] ?? 0);
        if ($refundId === 0) {
            file_put_contents($resultPath, json_encode([
                'ok' => false,
                'validation' => true,
                'worker' => $workerLabel,
                'message' => 'Refund id is required for manual approve.',
                'connection_id' => $connectionId,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ], JSON_THROW_ON_ERROR));
            exit(0);
        }

        $refund = RefundRequest::query()->findOrFail($refundId);

        try {
            $approved = $service->approve(
                $refund,
                $ops,
                [
                    'approved_refund_method' => ApprovedRefundMethod::Wallet->value,
                    'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
                    'review_notes' => 'Manual approve race '.$workerLabel,
                ],
                $request,
            );

            file_put_contents($resultPath, json_encode([
                'ok' => true,
                'worker' => $workerLabel,
                'refund_id' => $approved->id,
                'refund_status' => $approved->status?->value,
                'approved_method' => $approved->approved_refund_method?->value,
                'connection_id' => $connectionId,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ], JSON_THROW_ON_ERROR));
        } catch (ValidationException $exception) {
            file_put_contents($resultPath, json_encode([
                'ok' => false,
                'validation' => true,
                'worker' => $workerLabel,
                'message' => $exception->getMessage(),
                'connection_id' => $connectionId,
                'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
            ], JSON_THROW_ON_ERROR));
        }

        exit(0);
    }

    if ($action === 'rollback_probe') {
        $orderId = (int) ($payload['order_id'] ?? 0);
        $order = Order::query()->findOrFail($orderId);
        $rolledBack = false;

        try {
            DB::transaction(function () use ($service, $agent, $order, $request): void {
                $service->create($agent, [
                    'order_id' => $order->id,
                    'deduction_profile_key' => RefundDeductionProfile::FullRefund->value,
                    'reason' => 'Rollback probe.',
                    'remarks' => 'Forced rollback.',
                    'customer_preferred_method' => CustomerPreferredRefundMethod::Wallet->value,
                ], $request);
                throw new RuntimeException('Forced rollback after wallet auto-approval create.');
            });
        } catch (RuntimeException $exception) {
            $rolledBack = str_contains($exception->getMessage(), 'Forced rollback');
        }

        $count = RefundRequest::query()->where('order_id', $orderId)->count();
        file_put_contents($resultPath, json_encode([
            'ok' => $rolledBack && $count === 0,
            'worker' => $workerLabel,
            'rolled_back' => $rolledBack,
            'refund_rows' => $count,
            'connection_id' => $connectionId,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ], JSON_THROW_ON_ERROR));
        exit(0);
    }

    file_put_contents($resultPath, json_encode(['ok' => false, 'error' => 'Unknown action'], JSON_THROW_ON_ERROR));
    exit(1);
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'worker' => $workerLabel,
        'message' => $exception->getMessage(),
        'connection_id' => $connectionId,
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
