<?php

namespace App\Support\Testing;

use DateTimeImmutable;
use DateTimeZone;
use JsonException;
use Throwable;

/**
 * P-12-40 — default-deny gate for repository-wide PHPUnit runs.
 */
final class PhpunitGuard
{
    public const EXIT_ALLOWED = 0;

    public const EXIT_BLOCKED = 2;

    public const SCOPE_FULL = 'full';

    public const SCOPE_FOCUSED = 'focused';

    public const SCOPE_INFO = 'info';

    public const AUTH_RELATIVE_PATH = 'storage/app/tmp/phpunit-full-suite-auth.json';

    public const LOG_RELATIVE_PATH = 'storage/logs/phpunit-guard.log';

    public function __construct(
        private readonly string $projectRoot,
        private readonly ?DateTimeImmutable $now = null,
    ) {}

    /**
     * @param  list<string>  $argv  PHPUnit arguments (not including php binary or phpunit path)
     * @return array{allowed: bool, scope: string, reason: string, code: int}
     */
    public function evaluate(array $argv): array
    {
        if ($this->isInfoOnlyInvocation($argv)) {
            return $this->verdict(true, self::SCOPE_INFO, 'info_only', self::EXIT_ALLOWED);
        }

        if ($this->isFocusedInvocation($argv)) {
            return $this->verdict(true, self::SCOPE_FOCUSED, 'focused_run', self::EXIT_ALLOWED);
        }

        $auth = $this->readAuthFile();
        if ($auth === null) {
            return $this->verdict(false, self::SCOPE_FULL, 'full_suite_denied_missing_auth', self::EXIT_BLOCKED);
        }

        $validation = $this->validateAuth($auth);
        if ($validation !== null) {
            return $this->verdict(false, self::SCOPE_FULL, $validation, self::EXIT_BLOCKED);
        }

        return $this->verdict(true, self::SCOPE_FULL, 'full_suite_authorized', self::EXIT_ALLOWED);
    }

    /**
     * @param  list<string>  $argv
     */
    public function isFocusedInvocation(array $argv): bool
    {
        $filter = $this->extractOptionValue($argv, '--filter');
        if ($filter !== null && $filter !== '' && ! $this->isBroadFilter($filter)) {
            return true;
        }

        foreach ($argv as $arg) {
            if ($this->looksLikeTestSelectionArg($arg)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param  list<string>  $argv
     */
    public function isInfoOnlyInvocation(array $argv): bool
    {
        if ($argv === []) {
            return false;
        }

        $allowed = [
            '--help',
            '-h',
            '--version',
            '--check-version',
            '--atleast-version',
        ];

        foreach ($argv as $arg) {
            if (in_array($arg, $allowed, true)) {
                continue;
            }
            if (str_starts_with($arg, '--atleast-version=')) {
                continue;
            }

            return false;
        }

        return true;
    }

    public function authFilePath(): string
    {
        return $this->projectRoot.'/'.self::AUTH_RELATIVE_PATH;
    }

    public function logFilePath(): string
    {
        return $this->projectRoot.'/'.self::LOG_RELATIVE_PATH;
    }

    /**
     * @param  array{allowed: bool, scope: string, reason: string, code: int}  $verdict
     * @param  list<string>  $argv
     */
    public function appendBlockedLog(array $verdict, array $argv, ?string $invokedVia = null): void
    {
        if ($verdict['allowed']) {
            return;
        }

        $logPath = $this->logFilePath();
        $dir = dirname($logPath);
        if (! is_dir($dir)) {
            @mkdir($dir, 0775, true);
        }

        $entry = [
            'at' => $this->clock()->format(DateTimeImmutable::ATOM),
            'scope' => $verdict['scope'],
            'reason' => $verdict['reason'],
            'via' => $invokedVia ?? 'unknown',
            'argv_summary' => $this->argvSummary($argv),
        ];

        $line = json_encode($entry, JSON_THROW_ON_ERROR);
        @file_put_contents($logPath, $line.PHP_EOL, FILE_APPEND | LOCK_EX);

        $artifactDir = $this->projectRoot.'/.artifacts';
        if (is_dir($artifactDir) || @mkdir($artifactDir, 0775, true)) {
            @file_put_contents(
                $artifactDir.'/phpunit-guard-blocked.log',
                $line.PHP_EOL,
                FILE_APPEND | LOCK_EX
            );
        }
    }

    /**
     * @return array<string, mixed>|null
     */
    public function readAuthFile(): ?array
    {
        $path = $this->authFilePath();
        if (! is_readable($path)) {
            return null;
        }

        try {
            $raw = file_get_contents($path);
            if ($raw === false || trim($raw) === '') {
                return null;
            }

            /** @var array<string, mixed> $decoded */
            $decoded = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return $decoded;
        } catch (JsonException|Throwable) {
            return null;
        }
    }

    /**
     * @param  array<string, mixed>  $auth
     */
    public function validateAuth(array $auth): ?string
    {
        foreach (['authorized_by', 'authorized_at', 'expires_at', 'prompt_id', 'sha'] as $field) {
            if (! isset($auth[$field]) || ! is_string($auth[$field]) || trim($auth[$field]) === '') {
                return 'full_suite_denied_invalid_auth_field:'.$field;
            }
        }

        try {
            $expires = new DateTimeImmutable($auth['expires_at']);
        } catch (Throwable) {
            return 'full_suite_denied_invalid_expires_at';
        }

        if ($expires <= $this->clock()) {
            return 'full_suite_denied_auth_expired';
        }

        $head = $this->resolveHeadSha();
        if ($head !== null && ! hash_equals(strtolower($head), strtolower($auth['sha']))) {
            return 'full_suite_denied_sha_mismatch';
        }

        return null;
    }

    public function isBroadFilter(string $pattern): bool
    {
        $pattern = trim($pattern);
        if ($pattern === '') {
            return true;
        }

        $normalized = strtolower($pattern);
        $broadLiterals = ['.', '.*', '^.*$', '.*$', '^.*', '.+', '^.+$'];
        if (in_array($normalized, $broadLiterals, true)) {
            return true;
        }

        if (preg_match('/^[\.\*\^\$\[\]\(\)\|\+\?\\\\\s]+$/', $pattern) === 1) {
            return true;
        }

        return false;
    }

    private function looksLikeTestSelectionArg(string $arg): bool
    {
        if ($arg === '' || str_starts_with($arg, '-')) {
            return false;
        }

        if (str_ends_with($arg, 'Test.php') || str_ends_with($arg, '.php')) {
            return str_contains($arg, 'tests/') || str_contains($arg, 'Tests\\');
        }

        if (str_contains($arg, '::')) {
            return true;
        }

        if (preg_match('/^[A-Za-z0-9_\\\\]+Test$/', $arg) === 1) {
            return true;
        }

        return false;
    }

    /**
     * @param  list<string>  $argv
     */
    private function extractOptionValue(array $argv, string $option): ?string
    {
        $prefix = $option.'=';
        foreach ($argv as $index => $arg) {
            if (str_starts_with($arg, $prefix)) {
                return substr($arg, strlen($prefix));
            }
            if ($arg === $option && isset($argv[$index + 1])) {
                return $argv[$index + 1];
            }
        }

        return null;
    }

    /**
     * @return array{allowed: bool, scope: string, reason: string, code: int}
     */
    private function verdict(bool $allowed, string $scope, string $reason, int $code): array
    {
        return [
            'allowed' => $allowed,
            'scope' => $scope,
            'reason' => $reason,
            'code' => $code,
        ];
    }

    private function clock(): DateTimeImmutable
    {
        return $this->now ?? new DateTimeImmutable('now', new DateTimeZone('UTC'));
    }

    private function resolveHeadSha(): ?string
    {
        $headFile = $this->projectRoot.'/.git/HEAD';
        if (! is_readable($headFile)) {
            return null;
        }

        $head = trim((string) file_get_contents($headFile));
        if (preg_match('/^[0-9a-f]{40}$/i', $head) === 1) {
            return strtolower($head);
        }

        if (str_starts_with($head, 'ref: ')) {
            $ref = substr($head, 5);
            $refPath = $this->projectRoot.'/.git/'.$ref;
            if (is_readable($refPath)) {
                $sha = trim((string) file_get_contents($refPath));
                if (preg_match('/^[0-9a-f]{40}$/i', $sha) === 1) {
                    return strtolower($sha);
                }
            }
        }

        return null;
    }

    /**
     * @param  list<string>  $argv
     */
    private function argvSummary(array $argv): string
    {
        $redacted = [];
        foreach ($argv as $arg) {
            if (strlen($arg) > 120) {
                $redacted[] = substr($arg, 0, 117).'...';

                continue;
            }
            $redacted[] = $arg;
        }

        return implode(' ', $redacted);
    }
}
