<?php

declare(strict_types=1);

/**
 * Fallback timer for a single PHPUnit process (P-15-19).
 *
 * pcntl_alarm cannot interrupt every blocking syscall. This process waits
 * for the budget, sends SIGALRM to the recorded PID only, then SIGTERM and
 * SIGKILL to that same PID if the sentinel is still present. It never
 * signals a process group it did not inherit from that PID.
 *
 * Arguments: sentinel pid budget_seconds grace_seconds log_path
 */

if ($argc < 6) {
    fwrite(STDERR, "phpunit-test-budget-watchdog: expected sentinel pid budget grace log\n");
    exit(2);
}

$sentinel = $argv[1];
$pid = (int) $argv[2];
$budget = max(1, (int) $argv[3]);
$grace = max(1, (int) $argv[4]);
$log = $argv[5];

if ($pid <= 1) {
    fwrite(STDERR, "phpunit-test-budget-watchdog: refusing pid {$pid}\n");
    exit(2);
}

if (! waitUntil($budget, $sentinel, $pid)) {
    exit(0);
}

if (function_exists('posix_kill')) {
    posix_kill($pid, SIGALRM);
}

if (! waitUntil($grace, $sentinel, $pid)) {
    exit(0);
}

appendLog($log, [
    'event' => 'budget_fallback',
    'pid' => $pid,
    'reason' => 'uninterruptible_blocking_call',
    'action' => 'SIGTERM',
    'sentinel' => $sentinel,
]);

posix_kill($pid, SIGTERM);
if (! waitUntil(2, $sentinel, $pid)) {
    exit(0);
}

appendLog($log, [
    'event' => 'budget_fallback',
    'pid' => $pid,
    'reason' => 'sigterm_did_not_exit',
    'action' => 'SIGKILL',
    'sentinel' => $sentinel,
]);
posix_kill($pid, SIGKILL);
exit(0);

function waitUntil(int $seconds, string $sentinel, int $pid): bool
{
    $deadline = time() + $seconds;
    while (time() < $deadline) {
        if (! is_file($sentinel) || ! posix_kill($pid, 0)) {
            return false;
        }
        sleep(1);
    }

    return is_file($sentinel) && posix_kill($pid, 0);
}

/**
 * @param  array<string, int|string>  $row
 */
function appendLog(string $log, array $row): void
{
    $now = microtime(true);
    $seconds = (int) floor($now);
    $milliseconds = (int) round(($now - $seconds) * 1000);
    $row['ts'] = gmdate('Y-m-d\TH:i:s', $seconds).sprintf('.%03d+00:00', $milliseconds);
    $encoded = json_encode($row, JSON_UNESCAPED_SLASHES);
    if ($encoded === false) {
        return;
    }
    file_put_contents($log, $encoded."\n", FILE_APPEND | LOCK_EX);
}
