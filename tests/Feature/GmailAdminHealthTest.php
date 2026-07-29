<?php

namespace Tests\Feature;

use App\Enums\OperationsHealthStatus;
use App\Models\GmailMailboxSyncState;
use App\Models\User;
use App\Services\Operations\OperationsGmailHealthService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
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

    public function test_administration_api_health_page_shows_gmail_card(): void
    {
        $admin = User::factory()->create([
            'email' => 'gmail-admin@test.com',
        ]);
        $admin->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        GmailMailboxSyncState::query()->create([
            'mailbox' => 'mail@radiumbox.com',
            'history_id' => '115205853',
            'profile_history_id' => '115484641',
            'enabled_at' => now()->subDay(),
            'baselined_at' => now()->subDay(),
            'last_synced_at' => now()->subMinute(),
        ]);

        $this->actingAs($admin)
            ->get(route('admin.administration.index'))
            ->assertOk()
            ->assertSee('API Health')
            ->assertSee('Gmail API Health')
            ->assertSee('Run Gmail Sync Now')
            ->assertSee('Re-baseline Cursor')
            ->assertSee('115205853');
    }

    public function test_gmail_sync_now_endpoint_requires_permission(): void
    {
        $user = User::factory()->create();

        $this->actingAs($user)
            ->postJson(route('admin.gmail.sync-now'))
            ->assertForbidden();
    }
}
