<?php

namespace Tests\Unit;

use App\Services\ChangelogService;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ChangelogServiceTest extends TestCase
{
    public function test_entries_parse_version_and_release_date_from_header(): void
    {
        config([
            'app.version' => '4.0.0',
            'app.release_date' => '2026-07-26',
            'app.env' => 'testing',
        ]);

        Process::fake([
            '*' => Process::result(output: "abc1234\n"),
        ]);

        $entries = app(ChangelogService::class)->entries();

        $this->assertNotEmpty($entries);
        $this->assertSame('4.0.0', $entries[0]['version']);
        $this->assertSame('2026-07-26', $entries[0]['release_date']);
        $this->assertSame('P09 Workforce Platform Update', $entries[0]['title']);
        $this->assertTrue($entries[0]['is_current']);
        $this->assertSame('testing', $entries[0]['environment']);
        $this->assertSame('abc1234', $entries[0]['git_commit']);
    }

    public function test_entries_enrich_legacy_header_with_current_version_metadata(): void
    {
        $path = base_path('CHANGELOG.md');
        $original = (string) file_get_contents($path);

        file_put_contents($path, <<<'MARKDOWN'
# Changelog

## Legacy Release Title

- First item
MARKDOWN);

        try {
            config([
                'app.version' => '5.1.0',
                'app.release_date' => '2026-08-01',
                'app.env' => 'local',
            ]);

            Process::fake([
                '*' => Process::result(output: "c0ffee\n"),
            ]);

            $entries = app(ChangelogService::class)->entries();

            $this->assertSame('5.1.0', $entries[0]['version']);
            $this->assertSame('2026-08-01', $entries[0]['release_date']);
            $this->assertSame('Legacy Release Title', $entries[0]['title']);
            $this->assertSame('local', $entries[0]['environment']);
            $this->assertSame('c0ffee', $entries[0]['git_commit']);
        } finally {
            file_put_contents($path, $original);
        }
    }
}
