<?php

use App\Models\LeaveRequest;
use App\Models\User;
use App\Services\Operations\LeaveRequestService;
use Illuminate\Database\QueryException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Validation\ValidationException;
use Throwable;

require __DIR__.'/mysql_leave_policy_bootstrap.php';

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
$workerKey = (string) ($payload['worker_label'] ?? 'w0');
/** @var array<string, mixed> $workerPayload */
$workerPayload = $payload[$workerKey] ?? [];
$approverId = (int) ($payload['approver_id'] ?? 0);

$credentials = leave_policy_mysql_credentials_from_env();
leave_policy_mysql_boot_app($credentials);

if (! empty($payload['leave_approver_email'])) {
    config(['workforce.leave_approver.email' => $payload['leave_approver_email']]);
}

if (! empty($payload['leave_policy_coverage']) && is_array($payload['leave_policy_coverage'])) {
    /** @var array<string, mixed> $coverage */
    $coverage = $payload['leave_policy_coverage'];
    config([
        'leave_policy.coverage.triad_emails' => $coverage['triad_emails'] ?? [],
        'leave_policy.coverage.admin_pair_emails' => $coverage['admin_pair_emails'] ?? [],
    ]);
}

if (! empty($payload['test_now'])) {
    Carbon::setTestNow(Carbon::parse(
        (string) $payload['test_now'],
        (string) ($payload['test_now_timezone'] ?? 'Asia/Kolkata'),
    ));
}

config([
    'workforce_calendar.retroactive_leave_days' => (int) ($payload['retroactive_leave_days'] ?? 2),
]);

if (! empty($payload['leave_policy_auto_approval_enabled'])) {
    config(['leave_policy.auto_approval.enabled' => true]);
}

DB::statement('set session innodb_lock_wait_timeout = 50');

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

try {
    $approver = User::query()->findOrFail($approverId);
    $action = (string) ($workerPayload['action'] ?? 'approve');

    if ($action === 'submit') {
        $agent = User::query()->findOrFail((int) ($workerPayload['agent_id'] ?? 0));
        $day = (string) ($workerPayload['start_date'] ?? $payload['day'] ?? '');

        $submitPayload = [
            'start_date' => $day,
            'end_date' => (string) ($workerPayload['end_date'] ?? $day),
            'reason' => 'Concurrent submit '.$workerKey,
        ];

        $leave = null;
        for ($attempt = 1; $attempt <= 3; $attempt++) {
            try {
                $leave = app(LeaveRequestService::class)->submit($agent, $submitPayload);
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

        if ($leave === null) {
            throw new RuntimeException('Submit did not complete.');
        }

        $fresh = $leave->fresh();
        file_put_contents($resultPath, json_encode([
            'ok' => true,
            'worker' => $workerKey,
            'leave_id' => $fresh?->id,
            'leave_status' => $fresh?->status?->value,
            'approval_source' => $fresh?->approval_source?->value,
            'connection_id' => $connectionId,
            'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        ], JSON_THROW_ON_ERROR));

        exit(0);
    }

    $leaveId = (int) ($workerPayload['leave_id'] ?? 0);
    $leave = LeaveRequest::query()->findOrFail($leaveId);

    $approvalOptions = [];
    if (! empty($workerPayload['override'])) {
        $approvalOptions = [
            'authorized_override' => true,
            'authorized_override_reason' => 'Management monthly override',
        ];
    }

    app(LeaveRequestService::class)->approve($leave, $approver, 'Concurrent '.$workerKey, $approvalOptions);

    $fresh = $leave->fresh();
    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'worker' => $workerKey,
        'leave_status' => $fresh?->status?->value,
        'connection_id' => $connectionId,
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
    ], JSON_THROW_ON_ERROR));

    exit(0);
} catch (ValidationException $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'validation' => true,
        'worker' => $workerKey,
        'message' => implode(' ', $exception->validator->errors()->all()),
        'connection_id' => $connectionId,
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
    ], JSON_THROW_ON_ERROR));

    exit(0);
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'validation' => false,
        'worker' => $workerKey,
        'message' => $exception->getMessage(),
        'connection_id' => $connectionId,
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
    ], JSON_THROW_ON_ERROR));

    exit(0);
}
