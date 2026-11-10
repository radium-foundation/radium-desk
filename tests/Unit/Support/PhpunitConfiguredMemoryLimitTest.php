<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\Attributes\Group;
use PHPUnit\Framework\TestCase;

/**
 * Guards PHPUnit XML memory ceiling for full-suite runs (P-10-30).
 */
#[Group('p1030-memory-harness')]
class PhpunitConfiguredMemoryLimitTest extends TestCase
{
    public function test_effective_memory_limit_is_at_least_one_gigabyte(): void
    {
        $limit = ini_get('memory_limit');
        $this->assertNotSame('', $limit);
        $bytes = $this->parseLimit($limit);
        $this->assertGreaterThanOrEqual(1024 * 1024 * 1024, $bytes, "memory_limit={$limit}");
    }

    private function parseLimit(string $limit): int
    {
        if (preg_match('/^(\d+)([KMG])?$/i', trim($limit), $m)) {
            $n = (int) $m[1];
            $u = strtoupper($m[2] ?? '');

            return match ($u) {
                'G' => $n * 1024 * 1024 * 1024,
                'M' => $n * 1024 * 1024,
                'K' => $n * 1024,
                default => $n,
            };
        }

        return 0;
    }
}
