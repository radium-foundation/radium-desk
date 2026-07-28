<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ChangelogService;
use App\Services\Release\ReleaseManifestStore;
use App\Services\VersionService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Process;
use Tests\TestCase;

class PlatformIdentityTest extends TestCase
{
    use RefreshDatabase;

    private string $manifestPath;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->manifestPath = storage_path('framework/testing/platform-release-'.uniqid('', true).'.json');
        config([
            'app.release_manifest' => $this->manifestPath,
            'app.version' => null,
            'app.release_date' => null,
            'app.name' => 'Radium Desk',
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

    public function test_authenticated_layout_renders_version_footer(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee(app(VersionService::class)->applicationLabel(), false);
        $response->assertSee('Build abc1234', false);
        $response->assertSee('data-bs-target="#whatsNewModal"', false);
        $response->assertSee('data-short-version="'.app(VersionService::class)->shortVersionLabel().'"', false);
    }

    public function test_layout_includes_favicon_link(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $response = $this->actingAs($user)->get(route('dashboard'));

        $response->assertOk();
        $response->assertSee('<link rel="icon"', false);
        $response->assertSee('brand/favicon.ico', false);
    }

    public function test_authenticated_sidebar_uses_brand_icon(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $this->actingAs($user)
            ->get(route('dashboard'))
            ->assertOk()
            ->assertSee('brand/icon.svg', false);
    }

    public function test_layout_includes_robots_meta_tag(): void
    {
        $loginResponse = $this->get(route('login'));
        $loginResponse->assertOk();
        $loginResponse->assertSee('<meta name="robots" content="noindex,nofollow">', false);

        $user = User::factory()->create([
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $dashboardResponse = $this->actingAs($user)->get(route('dashboard'));
        $dashboardResponse->assertOk();
        $dashboardResponse->assertSee('<meta name="robots" content="noindex,nofollow">', false);
    }

    public function test_login_page_uses_radium_desk_branding(): void
    {
        $response = $this->get(route('login'));

        $response->assertOk();
        $response->assertSee('brand/logo.svg', false);
        $response->assertSee('Internal Operations Portal', false);
        $response->assertDontSee('<h1 class="h4 fw-bold text-primary mb-1">', false);
    }

    public function test_changelog_page_is_accessible_and_renders_entries(): void
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $response = $this->actingAs($user)->get(route('changelog.index'));

        $response->assertOk();
        $response->assertSee(app(VersionService::class)->applicationLabel(), false);
        $response->assertSee('Build abc1234', false);
        $response->assertSee('P09 Workforce Platform Update', false);
        $response->assertSee('Version:', false);
        $response->assertSee('4.0.0', false);
        $response->assertSee('Release date:', false);
        $response->assertSee('2026-07-26', false);
        $response->assertSee('Environment:', false);
        $response->assertSee('Git commit:', false);
        $response->assertSee('Workforce availability intelligence', false);
        $response->assertSee('IVR foundation improvements', false);
    }

    public function test_changelog_service_reads_source_file(): void
    {
        $service = app(ChangelogService::class);
        $entries = $service->currentReleaseEntries();

        $this->assertNotEmpty($entries);
        $this->assertSame('P09 Workforce Platform Update', $entries[0]['title']);
        $this->assertSame(app(VersionService::class)->version(), $entries[0]['version']);
        $this->assertContains('Better assignment accuracy', $entries[0]['items']);
        $this->assertTrue($entries[0]['is_current']);
    }

    public function test_changelog_page_shows_empty_state_when_release_notes_missing(): void
    {
        (new ReleaseManifestStore)->write([
            'version' => '4.0.2',
            'tag' => 'v4.0.2',
            'build' => '6b1b3f7',
            'deployed_at' => '2026-07-28T00:00:00+00:00',
            'release_date' => '2026-07-26',
        ]);

        $this->app->forgetInstance(VersionService::class);

        $user = User::factory()->create([
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $response = $this->actingAs($user)->get(route('changelog.index'));

        $response->assertOk();
        $response->assertSee('Release notes for v4.0.2 are not available.', false);
        $response->assertDontSee('Workforce availability intelligence', false);
    }

    public function test_robots_txt_disallows_all_crawling(): void
    {
        $contents = (string) file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /', $contents);
    }
}
