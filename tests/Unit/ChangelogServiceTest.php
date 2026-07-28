<?php

namespace Tests\Unit;

use App\Services\ChangelogService;
use App\Services\Release\ReleaseManifestStore;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class ChangelogServiceTest extends TestCase
{
    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->manifestPath = storage_path('framework/testing/changelog-release-'.uniqid('', true).'.json');
        config([
            'app.release_manifest' => $this->manifestPath,
            'app.version' => null,
            'app.release_date' => null,
            'app.env' => 'testing',
        ]);

        (new ReleaseManifestStore)->write([
            'version' => '4.0.0',
            'tag' => 'v4.0.0',
            'build' => 'abc1234',
            'deployed_at' => '2026-07-26T00:00:00+00:00',
            'release_date' => '2026-07-26',
        ]);

        Process::fake([
            '*' => Process::result(output: "abc1234\n"),
        ]);
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifestPath)) {
            unlink($this->manifestPath);
        }

        parent::tearDown();
    }

    public function test_entries_parse_version_and_release_date_from_header(): void
    {
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
            (new ReleaseManifestStore)->write([
                'version' => '5.1.0',
                'tag' => 'v5.1.0',
                'build' => 'c0ffee',
                'deployed_at' => '2026-08-01T00:00:00+00:00',
                'release_date' => '2026-08-01',
            ]);

            Process::fake([
                '*' => Process::result(output: "c0ffee\n"),
            ]);

            // Force a fresh VersionService (manifest already loaded on prior resolves).
            $this->app->forgetInstance(\App\Services\VersionService::class);

            $entries = app(ChangelogService::class)->entries();

            $this->assertSame('5.1.0', $entries[0]['version']);
            $this->assertSame('2026-08-01', $entries[0]['release_date']);
            $this->assertSame('Legacy Release Title', $entries[0]['title']);
            $this->assertSame('testing', $entries[0]['environment']);
            $this->assertSame('c0ffee', $entries[0]['git_commit']);
        } finally {
            file_put_contents($path, $original);
        }
    }
}
