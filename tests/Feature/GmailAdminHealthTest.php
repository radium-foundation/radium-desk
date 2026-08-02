<?php

namespace Tests\Feature;

use App\Models\GmailMailboxSyncState;
use App\Models\User;
use App\Enums\OperationsHealthStatus;
use App\Services\Operations\OperationsGmailHealthService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class GmailAdminHealthTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'inbound_email.enabled' => true,
            'inbound_email.gmail.enabled' => true,
            'inbound_email.gmail.sync_mailboxes' => ['mail@radiumbox.com'],
            'inbound_email.gmail.service_account_json' => '{"client_email":"sa@test.iam.gserviceaccount.com","private_key":"unused"}',
            'cache.default' => 'array',
        ]);

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);
    }

    public function test_health_status_failed_when_last_error_present(): void
    {
        GmailMailboxSyncState::query()->create([
            'mailbox' => 'mail@radiumbox.com',
            'history_id' => '100',
            'profile_history_id' => '200',
            'enabled_at' => now()->subHour(),
            'baselined_at' => now()->subHour(),
            'last_synced_at' => now()->subMinutes(2),
            'last_error' => 'Gmail API GET failed: HTTP 400',
            'consecutive_failures' => 1,
        ]);

        $status = app(OperationsGmailHealthService::class)->resolveStatus(
            GmailMailboxSyncState::query()->first(),
        );

        $this->assertSame(OperationsHealthStatus::Failed, $status);
    }

    public function test_health_status_healthy_when_recent_sync_without_errors(): void
    {
        GmailMailboxSyncState::query()->create([
            'mailbox' => 'mail@radiumbox.com',
            'history_id' => '100',
            'profile_history_id' => '100',
            'enabled_at' => now()->subHour(),
            'baselined_at' => now()->subHour(),
            'last_synced_at' => now()->subMinute(),
            'last_error' => null,
            'consecutive_failures' => 0,
        ]);

        $widget = app(OperationsGmailHealthService::class)->widget();

        $this->assertSame('healthy', $widget['status']);
        $this->assertSame('mail@radiumbox.com', $widget['mailbox']);
        $this->assertSame('100', $widget['history_cursor']);
        $this->assertSame(0, $widget['cursor_lag']);
    }

    public function test_administration_no_longer_renders_gmail_diagnostics(): void
    {
        $admin = User::factory()->create([
            'email' => 'gmail-admin@test.com',
            'is_active' => true,
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        Cache::flush();

        $this->actingAs($admin)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('System Health')
            ->assertDontSee('Gmail Health')
            ->assertDontSee('Run Gmail Sync Now')
            ->assertDontSee('Reset Sync Position');
    }

    public function test_gmail_health_partial_shows_renamed_labels(): void
    {
        GmailMailboxSyncState::query()->create([
            'mailbox' => 'mail@radiumbox.com',
            'history_id' => '115205853',
            'profile_history_id' => '115484641',
            'enabled_at' => now()->subDay(),
            'baselined_at' => now()->subDay(),
            'last_synced_at' => now()->subMinute(),
        ]);

        $html = view('admin.operations.partials.gmail-health', [
            'health' => app(OperationsGmailHealthService::class)->widget(),
            'showActions' => true,
        ])->render();

        $this->assertStringContainsString('Gmail Health', $html);
        $this->assertStringContainsString('Run Gmail Sync Now', $html);
        $this->assertStringContainsString('Reset Sync Position', $html);
        $this->assertStringContainsString('Sync Delay', $html);
        $this->assertStringContainsString('Credentials', $html);
        $this->assertStringContainsString('Advanced Diagnostics', $html);
        $this->assertStringContainsString('Sync Position', $html);
        $this->assertStringContainsString('Mailbox Position', $html);
        $this->assertStringContainsString('115205853', $html);
        $this->assertStringNotContainsString('History Cursor', $html);
        $this->assertStringNotContainsString('Re-baseline Cursor', $html);
    }

    public function test_gmail_sync_now_endpoint_requires_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('admin.gmail.sync-now'))
            ->assertForbidden();
    }
}
