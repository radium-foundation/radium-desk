<?php

namespace Tests\Unit\Support;

use App\Support\Scheduling\HostCronLockReleaser;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

class HostCronLockReleaserTest extends TestCase
{
    #[DataProvider('hostCronLockPaths')]
    public function test_recognizes_host_cron_lock_paths(string $path, bool $expected): void
    {
        $this->assertSame($expected, HostCronLockReleaser::isHostCronLockPath($path));
    }

    /**
     * @return array<string, array{0: string, 1: bool}>
     */
    public static function hostCronLockPaths(): array
    {
        return [
            'hostinger lock' => ['/tmp/cron_lock_12345', true],
            'basename only' => ['cron_lock_abc', true],
            'nested cron_lock' => ['/var/lock/cron_lock/foo', true],
            'socket' => ['socket:[12345]', false],
            'mysql' => ['/var/run/mysqld/mysqld.sock', false],
            'pipe' => ['pipe:[ gro]', false],
        ];
    }
}
