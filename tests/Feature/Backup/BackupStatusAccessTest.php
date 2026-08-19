<?php

namespace Tests\Feature\Backup;

use App\Models\User;
use App\Services\Backup\BackupStatusService;
use App\Support\Administration\BackupAccess;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\File;
use Tests\TestCase;

class BackupStatusAccessTest extends TestCase
{
    use RefreshDatabase;

    private string $stagingRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
        $this->seed(RolePermissionSeeder::class);

        $this->stagingRoot = storage_path('framework/testing/backup-feature-'.uniqid());
        File::makeDirectory($this->stagingRoot.'/runs', 0755, true);

        config([
            'backup.staging_root' => $this->stagingRoot,
            'backup.history_limit' => 5,
        ]);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->stagingRoot);

        parent::tearDown();
    }

    public function test_only_superadmin_has_backups_view_permission(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_AGENT);

        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $super = User::factory()->create(['is_active' => true]);
        $super->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->assertFalse(BackupAccess::canView($agent));
        $this->assertFalse(BackupAccess::canView($admin));
        $this->assertTrue(BackupAccess::canView($super));
    }

    public function test_superadmin_can_view_backup_status_page(): void
    {
        $this->writeManifest();

        $super = User::factory()->create(['is_active' => true]);
        $super->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $this->actingAs($super)
            ->get(route('admin.backups.index'))
            ->assertOk()
            ->assertSee('Backup Status')
            ->assertSee('Phase 1 — read-only status')
            ->assertSee('20260818T185214Z')
            ->assertSee('Uploaded to cloud')
            ->assertDontSee('Run Backup')
            ->assertDontSee('Restore');
    }

    public function test_admin_is_denied_backup_status_page(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->actingAs($admin)
            ->get(route('admin.backups.index'))
            ->assertForbidden();
    }

    public function test_guest_is_redirected_from_backup_status_page(): void
    {
        $this->get(route('admin.backups.index'))
            ->assertRedirect(route('login'));
    }

    public function test_service_is_not_invoked_for_unauthorized_users(): void
    {
        $admin = User::factory()->create(['is_active' => true]);
        $admin->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        $this->mock(BackupStatusService::class)
            ->shouldNotReceive('summary');

        $this->actingAs($admin)
            ->get(route('admin.backups.index'))
            ->assertForbidden();
    }

    private function writeManifest(): void
    {
        $runDir = $this->stagingRoot.'/runs/20260818T185214Z';
        File::makeDirectory($runDir, 0755, true);
        File::put($runDir.'/manifest.json', json_encode([
            'backup_id' => '20260818T185214Z',
            'created_at' => '2026-08-18T18:52:14Z',
            'phase' => 'cloud_uploaded',
            'application' => [
                'version' => '4.0.42',
                'build' => '800ed734',
            ],
            'artifacts' => [
                [
                    'role' => 'database',
                    'filename' => 'database.sql.gz.gpg',
                    'size_bytes' => 1024,
                    'sha256' => 'aaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaaa',
                ],
                [
                    'role' => 'secrets',
                    'filename' => 'secrets.tar.gz.gpg',
                    'size_bytes' => 512,
                    'sha256' => 'bbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbbb',
                ],
            ],
            'upload' => [
                'status' => 'completed',
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES));
    }
}
