<?php

namespace Tests\Unit\Support;

use PHPUnit\Framework\TestCase;

class PhpunitProgressExtensionTest extends TestCase
{
    public function test_progress_file_records_prepared_when_env_set(): void
    {
        $path = getenv('PHPUNIT_PROGRESS_FILE');
        if ($path === false || $path === '') {
            $this->markTestSkipped('PHPUNIT_PROGRESS_FILE is not set for this run.');
        }

        $this->assertFileExists($path);

        $lines = file($path, FILE_IGNORE_NEW_LINES) ?: [];
        $this->assertNotEmpty($lines);

        $last = json_decode($lines[count($lines) - 1], true);
        $this->assertSame('prepared', $last['event'] ?? null);
        $this->assertStringContainsString(__CLASS__.'::test_progress_file_records_prepared_when_env_set', (string) ($last['test'] ?? ''));
    }
}
