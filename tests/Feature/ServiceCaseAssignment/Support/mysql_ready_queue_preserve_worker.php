<?php

use App\Models\AuditLog;
use App\Models\Incident;
use App\Models\Order;
use App\Models\User;
use App\Services\ServiceCaseAssignmentEligibilityService;
use App\Services\ServiceCaseAssignmentService;
use Illuminate\Support\Facades\DB;

require __DIR__.'/mysql_service_case_assignment_bootstrap.php';

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

$credentials = service_case_assignment_mysql_credentials_from_env();
service_case_assignment_mysql_boot_app($credentials);

DB::statement('set session innodb_lock_wait_timeout = 50');

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
$connectionId = DB::selectOne('select connection_id() as id')?->id;

try {
    $order = Order::query()->findOrFail((int) ($payload['order_id'] ?? 0));
    $actor = User::query()->findOrFail((int) ($payload['actor_user_id'] ?? 0));
    $agentUserId = (int) ($payload['agent_user_id'] ?? 0);
    $preservedEvent = (string) ($payload['preserved_event'] ?? ServiceCaseAssignmentService::READY_QUEUE_OWNER_PRESERVED_EVENT);

    app(ServiceCaseAssignmentEligibilityService::class)->evaluateAssignmentEligibility($order, $actor);

    $incident = Incident::query()->where('order_id', $order->id)->firstOrFail();

    file_put_contents($resultPath, json_encode([
        'ok' => true,
        'assigned_to_user_id' => $incident->assigned_to_user_id,
        'expected_agent_id' => $agentUserId,
        'owner_preserved' => $incident->assigned_to_user_id === $agentUserId,
        'audit_count' => AuditLog::query()
            ->where('event', $preservedEvent)
            ->where('auditable_id', $incident->id)
            ->count(),
        'elapsed_ms' => (int) round((microtime(true) - $started) * 1000),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));
    exit(0);
} catch (Throwable $exception) {
    file_put_contents($resultPath, json_encode([
        'ok' => false,
        'error' => $exception::class,
        'message' => $exception->getMessage(),
        'connection_id' => $connectionId,
    ], JSON_THROW_ON_ERROR));
    exit(1);
}
