<?php

declare(strict_types=1);

namespace Tests\Support;

use PHPUnit\Framework\AssertionFailedError;

/**
 * Fail-closed budget for an explicit test id (P-15-19).
 *
 * SIGALRM plus a stack trace covers PHP-level waits such as sleep().
 * A child watchdog is the fallback when the alarmed call does not return
 * into PHP. The watchdog is signaled and reaped on disarm.
 */
final class PhpunitTestBudget
{
    private const GRACE_SECONDS = 5;

    private static ?self $active = null;

    private ?string $testId = null;

    private ?string $sentinel = null;

    private ?int $watchdogPid = null;

    /** @var resource|null */
    private $watchdogProcess = null;

    private float $startedAt = 0.0;

    private int $seconds = 0;

    private string $reason = '';

    private bool $expired = false;

    public function __construct(private string $logPath) {}

    /** @var array<string, array{seconds: int, reason: string}>|null */
    private static ?array $budgetCache = null;

    /**
     * @return array<string, array{seconds: int, reason: string}>
     */
    public static function budgets(): array
    {
        if (self::$budgetCache !== null) {
            return self::$budgetCache;
        }
        $loaded = require dirname(__DIR__).'/Support/phpunit-test-budgets.php';
        self::$budgetCache = is_array($loaded) ? $loaded : [];

        return self::$budgetCache;
    }

    /**
     * @return array{seconds: int, reason: string}|null
     */
    public static function budgetFor(string $testId): ?array
    {
        foreach (self::budgets() as $key => $spec) {
            if (! is_string($key) || ! is_array($spec)) {
                continue;
            }
            if ($testId === $key || str_starts_with($testId, $key.'#') || str_starts_with($testId, $key.' ')) {
                $seconds = (int) ($spec['seconds'] ?? 0);
                $reason = (string) ($spec['reason'] ?? '');
                if ($seconds > 0 && $reason !== '') {
                    return ['seconds' => $seconds, 'reason' => $reason];
                }
            }
        }

        return null;
    }

    public static function canArmInProcess(): bool
    {
        return function_exists('pcntl_async_signals')
            && function_exists('pcntl_signal')
            && function_exists('pcntl_alarm')
            && function_exists('posix_kill');
    }

    public function arm(string $testId, int $seconds, string $reason): void
    {
        if ($seconds < 1) {
            throw new AssertionFailedError('Refusing a non-positive per-test budget for '.$testId);
        }
        if (! self::canArmInProcess()) {
            throw new AssertionFailedError(
                'BLOCKED: pcntl/posix cannot enforce the per-test budget for '.$testId
                .'. The test is failed closed and was not skipped.'
            );
        }

        $this->disarm();
        $this->testId = $testId;
        $this->seconds = $seconds;
        $this->reason = $reason;
        $this->startedAt = microtime(true);
        $this->expired = false;
        $this->sentinel = sys_get_temp_dir().'/phpunit-budget-'.getmypid().'-'.bin2hex(random_bytes(4));
        file_put_contents($this->sentinel, $testId);
        $this->write([
            'event' => 'budget_started',
            'test' => $testId,
            'budget_seconds' => $seconds,
            'reason' => $reason,
            'pid' => getmypid(),
        ]);
        $this->startWatchdog();

        self::$active = $this;
        pcntl_async_signals(true);
        pcntl_signal(SIGALRM, static function (): void {
            self::$active?->expire('pcntl_alarm');
        });
        pcntl_alarm($seconds);
    }

    public function failClosedIfExpired(): void
    {
        if (! $this->expired || $this->testId === null) {
            return;
        }
        $testId = $this->testId;
        $this->disarm();
        fwrite(STDERR, "Per-test budget expired for {$testId} and the test did not fail. Result: NOT_PASSED\n");
        exit(2);
    }

    public function disarm(): void
    {
        $this->expired = false;
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
        if (function_exists('pcntl_signal')) {
            pcntl_signal(SIGALRM, SIG_DFL);
        }
        if ($this->sentinel !== null && is_file($this->sentinel)) {
            unlink($this->sentinel);
        }
        $this->stopWatchdog();
        if (self::$active === $this) {
            self::$active = null;
        }
        $this->sentinel = null;
        $this->testId = null;
    }

    public function expire(string $mechanism): void
    {
        if ($this->expired || $this->testId === null) {
            return;
        }
        $this->expired = true;
        if (function_exists('pcntl_alarm')) {
            pcntl_alarm(0);
        }
        $elapsedMs = (int) round((microtime(true) - $this->startedAt) * 1000);
        $trace = self::trace();
        $this->write([
            'event' => 'budget_expired',
            'test' => $this->testId,
            'budget_seconds' => $this->seconds,
            'elapsed_ms' => $elapsedMs,
            'mechanism' => $mechanism,
            'reason' => $this->reason,
            'trace' => $trace,
            'result' => 'NOT_PASSED',
        ]);

        throw new AssertionFailedError(sprintf(
            "Per-test budget expired after %d ms (limit %d s) via %s.\nConfigured reason: %s\n%s",
            $elapsedMs,
            $this->seconds,
            $mechanism,
            $this->reason,
            implode("\n", $trace),
        ));
    }

    private function startWatchdog(): void
    {
        $script = __DIR__.'/phpunit-test-budget-watchdog.php';
        $command = [
            PHP_BINARY,
            '-n',
            $script,
            (string) $this->sentinel,
            (string) getmypid(),
            (string) $this->seconds,
            (string) self::GRACE_SECONDS,
            $this->logPath,
        ];
        $process = proc_open($command, [
            0 => ['file', '/dev/null', 'r'],
            1 => ['file', '/dev/null', 'w'],
            2 => ['file', '/dev/null', 'w'],
        ], $pipes);
        if (! is_resource($process)) {
            throw new AssertionFailedError('BLOCKED: could not start the per-test budget watchdog for '.$this->testId);
        }
        $status = proc_get_status($process);
        $this->watchdogPid = (int) ($status['pid'] ?? 0);
        if ($this->watchdogPid <= 0) {
            proc_close($process);
            throw new AssertionFailedError('BLOCKED: budget watchdog did not report a pid for '.$this->testId);
        }
        $this->watchdogProcess = $process;
    }

    private function stopWatchdog(): void
    {
        $pid = $this->watchdogPid;
        $process = $this->watchdogProcess;
        $this->watchdogPid = null;
        $this->watchdogProcess = null;
        if (is_int($pid) && $pid > 1 && function_exists('posix_kill')) {
            posix_kill($pid, SIGTERM);
        }
        if (! is_resource($process)) {
            return;
        }
        $status = proc_get_status($process);
        if (($status['running'] ?? false) === true && is_int($pid) && $pid > 1 && function_exists('posix_kill')) {
            $deadline = microtime(true) + 1.0;
            do {
                $status = proc_get_status($process);
                if (($status['running'] ?? false) !== true) {
                    break;
                }
                usleep(20000);
            } while (microtime(true) < $deadline);
            $status = proc_get_status($process);
            if (($status['running'] ?? false) === true) {
                posix_kill($pid, SIGKILL);
            }
        }
        proc_close($process);
    }

    /**
     * @param  array<string, int|string|array<int, string>>  $row
     */
    private function write(array $row): void
    {
        $row['ts'] = self::timestamp();
        $encoded = json_encode($row, JSON_UNESCAPED_SLASHES);
        if ($encoded === false) {
            return;
        }
        file_put_contents($this->logPath, $encoded."\n", FILE_APPEND | LOCK_EX);
    }

    /**
     * @return list<string>
     */
    private static function trace(): array
    {
        $frames = debug_backtrace(DEBUG_BACKTRACE_IGNORE_ARGS);
        $lines = [];
        foreach ($frames as $frame) {
            $call = ($frame['class'] ?? '').($frame['type'] ?? '').($frame['function'] ?? '?');
            $at = ($frame['file'] ?? '').':'.(string) ($frame['line'] ?? '');
            $lines[] = trim($call.' '.$at);
        }

        return $lines;
    }

    private static function timestamp(): string
    {
        $now = microtime(true);
        $seconds = (int) floor($now);
        $milliseconds = (int) round(($now - $seconds) * 1000);
        if ($milliseconds === 1000) {
            $seconds++;
            $milliseconds = 0;
        }

        return gmdate('Y-m-d\TH:i:s', $seconds).sprintf('.%03d+00:00', $milliseconds);
    }
}
