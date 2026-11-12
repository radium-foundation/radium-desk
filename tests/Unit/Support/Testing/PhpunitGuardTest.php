<?php

namespace Tests\Unit\Support\Testing;

use App\Support\Testing\PhpunitGuard;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\TestCase;

class PhpunitGuardTest extends TestCase
{
    private string $tmpdir;

    protected function setUp(): void
    {
        $this->tmpdir = sys_get_temp_dir().'/phpunit-guard-'.bin2hex(random_bytes(6));
        mkdir($this->tmpdir.'/storage/logs', 0775, true);
        mkdir($this->tmpdir.'/storage/app/tmp', 0775, true);
    }

    protected function tearDown(): void
    {
        $this->rmTree($this->tmpdir);
    }

    public function test_focused_file_path_is_allowed_without_auth(): void
    {
        $guard = $this->guard();
        $verdict = $guard->evaluate(['tests/Unit/Support/Testing/PhpunitGuardTest.php']);

        $this->assertTrue($verdict['allowed']);
        $this->assertSame(PhpunitGuard::SCOPE_FOCUSED, $verdict['scope']);
    }

    public function test_narrow_filter_is_allowed_without_auth(): void
    {
        $guard = $this->guard();
        $verdict = $guard->evaluate(['--filter', 'test_focused_file_path_is_allowed_without_auth']);

        $this->assertTrue($verdict['allowed']);
        $this->assertSame(PhpunitGuard::SCOPE_FOCUSED, $verdict['scope']);
    }

    public function test_broad_filter_is_blocked_without_auth(): void
    {
        $guard = $this->guard();
        $verdict = $guard->evaluate(['--filter', '.*']);

        $this->assertFalse($verdict['allowed']);
        $this->assertSame(PhpunitGuard::EXIT_BLOCKED, $verdict['code']);
    }

    public function test_full_suite_without_auth_is_blocked(): void
    {
        $guard = $this->guard();
        $verdict = $guard->evaluate([]);

        $this->assertFalse($verdict['allowed']);
        $this->assertSame('full_suite_denied_missing_auth', $verdict['reason']);
    }

    public function test_full_suite_with_valid_auth_is_allowed(): void
    {
        $now = new DateTimeImmutable('2026-11-12T10:00:00+00:00');
        $this->writeAuth([
            'authorized_by' => 'Owner',
            'authorized_at' => '2026-11-12T09:55:00+00:00',
            'expires_at' => '2026-11-12T12:00:00+00:00',
            'prompt_id' => 'RadiumDesk-P-12-40',
            'sha' => str_repeat('a', 40),
        ]);

        $guard = new PhpunitGuard($this->tmpdir, $now);
        $verdict = $guard->evaluate([]);

        $this->assertTrue($verdict['allowed']);
        $this->assertSame('full_suite_authorized', $verdict['reason']);
    }

    public function test_expired_auth_is_blocked(): void
    {
        $now = new DateTimeImmutable('2026-11-12T13:00:00+00:00');
        $this->writeAuth([
            'authorized_by' => 'Owner',
            'authorized_at' => '2026-11-12T09:55:00+00:00',
            'expires_at' => '2026-11-12T12:00:00+00:00',
            'prompt_id' => 'RadiumDesk-P-12-40',
            'sha' => str_repeat('b', 40),
        ]);

        $guard = new PhpunitGuard($this->tmpdir, $now);
        $verdict = $guard->evaluate([]);

        $this->assertFalse($verdict['allowed']);
        $this->assertSame('full_suite_denied_auth_expired', $verdict['reason']);
    }

    public function test_blocked_attempt_writes_log_without_secrets(): void
    {
        $guard = $this->guard();
        $verdict = $guard->evaluate([]);
        $guard->appendBlockedLog($verdict, [], 'unit-test');

        $logPath = $guard->logFilePath();
        $this->assertFileExists($logPath);
        $line = trim((string) file_get_contents($logPath));
        $this->assertStringNotContainsString('secret', strtolower($line));
        $this->assertStringContainsString('full_suite_denied_missing_auth', $line);
    }

    public function test_is_broad_filter_detects_catch_all_patterns(): void
    {
        $guard = $this->guard();
        $this->assertTrue($guard->isBroadFilter('.*'));
        $this->assertTrue($guard->isBroadFilter('.'));
        $this->assertFalse($guard->isBroadFilter('PhpunitGuardTest'));
    }

    private function guard(?DateTimeImmutable $now = null): PhpunitGuard
    {
        return new PhpunitGuard(
            $this->tmpdir,
            $now ?? new DateTimeImmutable('2026-11-12T10:00:00', new DateTimeZone('UTC')),
        );
    }

    /**
     * @param  array<string, string>  $payload
     */
    private function writeAuth(array $payload): void
    {
        $path = $this->tmpdir.'/'.PhpunitGuard::AUTH_RELATIVE_PATH;
        file_put_contents($path, json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function rmTree(string $dir): void
    {
        if (! is_dir($dir)) {
            return;
        }

        $items = scandir($dir);
        if ($items === false) {
            return;
        }

        foreach ($items as $item) {
            if ($item === '.' || $item === '..') {
                continue;
            }
            $path = $dir.'/'.$item;
            if (is_dir($path)) {
                $this->rmTree($path);
            } else {
                @unlink($path);
            }
        }

        @rmdir($dir);
    }
}
