<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ChangelogService;
use App\Services\VersionService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PlatformIdentityTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
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
        $entries = app(ChangelogService::class)->entries();

        $this->assertNotEmpty($entries);
        $this->assertSame('P09 Workforce Platform Update', $entries[0]['title']);
        $this->assertSame(config('app.version'), $entries[0]['version']);
        $this->assertContains('Better assignment accuracy', $entries[0]['items']);
        $this->assertTrue($entries[0]['is_current']);
    }

    public function test_robots_txt_disallows_all_crawling(): void
    {
        $contents = (string) file_get_contents(public_path('robots.txt'));

        $this->assertStringContainsString('Disallow: /', $contents);
    }
}
