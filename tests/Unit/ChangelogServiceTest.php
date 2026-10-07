<?php

namespace Tests\Unit;

use App\Services\ChangelogService;
use App\Services\Release\ReleaseManifestStore;
use App\Services\VersionService;
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

        $this->app->forgetInstance(VersionService::class);
    }

    protected function tearDown(): void
    {
        if (is_file($this->manifestPath)) {
            unlink($this->manifestPath);
        }

        parent::tearDown();
    }

    public function test_entries_mark_only_matching_version_as_current(): void
    {
        $entries = app(ChangelogService::class)->entries();

        $this->assertNotEmpty($entries);

        $current = $this->entryForVersion($entries, '4.0.0');

        $this->assertNotNull($current);
        $this->assertSame('2026-07-26', $current['release_date']);
        $this->assertSame('P09 Workforce Platform Update', $current['title']);
        $this->assertTrue($current['is_current']);
        $this->assertSame('testing', $current['environment']);
        $this->assertSame('abc1234', $current['git_commit']);

        foreach ($entries as $entry) {
            if ($entry['version'] === '4.0.0') {
                continue;
            }

            $this->assertFalse($entry['is_current'], 'Only the manifest version should be marked current.');
        }
    }

    public function test_newest_changelog_entry_is_not_necessarily_current(): void
    {
        $entries = app(ChangelogService::class)->entries();

        $this->assertSame('4.1.5', $entries[0]['version']);
        $this->assertFalse($entries[0]['is_current']);
    }

    public function test_current_release_entries_returns_only_matching_version(): void
    {
        $service = app(ChangelogService::class);

        $current = $service->currentReleaseEntries();

        $this->assertCount(1, $current);
        $this->assertSame('4.0.0', $current[0]['version']);
        $this->assertSame('P09 Workforce Platform Update', $current[0]['title']);
    }

    public function test_current_release_entries_empty_when_version_has_no_changelog_section(): void
    {
        (new ReleaseManifestStore)->write([
            'version' => '4.99.99',
            'tag' => 'v4.99.99',
            'build' => '6b1b3f7',
            'deployed_at' => '2026-07-28T00:00:00+00:00',
            'release_date' => '2026-07-26',
        ]);

        $this->app->forgetInstance(VersionService::class);

        $service = app(ChangelogService::class);

        $this->assertSame([], $service->currentReleaseEntries());
        $this->assertFalse($service->entries()[0]['is_current']);
        $this->assertSame('Release notes for v4.99.99 are not available.', $service->missingReleaseNotesMessage());
        $this->assertStringNotContainsString('Workforce availability intelligence', json_encode($service->currentReleaseEntries()));
    }

    public function test_has_entry_for_version(): void
    {
        $service = app(ChangelogService::class);

        $this->assertTrue($service->hasEntryForVersion('4.0.0'));
        $this->assertTrue($service->hasEntryForVersion('4.1.5'));
        $this->assertTrue($service->hasEntryForVersion('4.1.0'));
        $this->assertTrue($service->hasEntryForVersion('4.0.2'));
        $this->assertFalse($service->hasEntryForVersion('4.99.99'));
    }

    public function test_legacy_header_without_version_is_never_marked_current(): void
    {
        $path = base_path('CHANGELOG.md');
        $original = (string) file_get_contents($path);

        file_put_contents($path, <<<'MARKDOWN'
# Changelog

## Legacy Release Title

- First item
MARKDOWN);

        try {
            $entries = app(ChangelogService::class)->entries();

            $this->assertSame([], app(ChangelogService::class)->currentReleaseEntries());
            $this->assertFalse($entries[0]['is_current']);
            $this->assertNull($entries[0]['version']);
        } finally {
            file_put_contents($path, $original);
        }
    }

    /**
     * @param  list<array{version: string|null, title: string, release_date: string|null, environment: string|null, git_commit: string|null, is_current: bool, items: list<string>}>  $entries
     * @return array{version: string|null, title: string, release_date: string|null, environment: string|null, git_commit: string|null, is_current: bool, items: list<string>}|null
     */
    private function entryForVersion(array $entries, string $version): ?array
    {
        foreach ($entries as $entry) {
            if ($entry['version'] === $version) {
                return $entry;
            }
        }

        return null;
    }
}
