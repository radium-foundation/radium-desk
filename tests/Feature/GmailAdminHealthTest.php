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
            'last_attempted_at' => now()->subMinute(),
            'last_error' => 'Gmail API GET failed: HTTP 400',
            'consecutive_failures' => 1,
            'oauth_status' => 'auth_error',
        ]);

        $status = app(OperationsGmailHealthService::class)->resolveStatus(
            GmailMailboxSyncState::query()->first(),
        );

        $this->assertSame(OperationsHealthStatus::Failed, $status);
    }

    public function test_health_recovers_to_healthy_after_successful_sync_clears_active_error(): void
    {
        GmailMailboxSyncState::query()->create([
            'mailbox' => 'mail@radiumbox.com',
            'history_id' => '100',
            'profile_history_id' => '100',
            'enabled_at' => now()->subHour(),
            'baselined_at' => now()->subHour(),
            'last_synced_at' => now()->subHour(),
            'last_attempted_at' => now()->subHour(),
            'last_error' => null,
            'consecutive_failures' => 0,
            'oauth_status' => 'ok',
            'messages_failed_last_run' => 0,
        ]);

        $provider = app(\App\Services\IncomingEmail\Providers\GmailInboundEmailProvider::class)
            ->forMailbox('mail@radiumbox.com');

        $provider->recordError('Gmail OAuth token request failed: invalid_grant');

        $before = app(OperationsGmailHealthService::class)->widget();
        $this->assertSame('failed', $before['status']);
        $this->assertNotNull($before['last_error']);
        $this->assertSame('invalid_grant', $before['oauth_status']);

        $provider->recordRunMetrics(
            processed: 0,
            skipped: 0,
            retried: 0,
            failed: 0,
            pages: 1,
            cursorAdvances: 0,
            durationMs: 50,
            latencyMs: 20,
            oauthStatus: 'ok',
        );
        app(OperationsGmailHealthService::class)->invalidateCachedHealth();

        $widget = app(OperationsGmailHealthService::class)->widget();

        $this->assertSame('healthy', $widget['status']);
        $this->assertNull($widget['last_error']);
        $this->assertSame('ok', $widget['oauth_status']);
        $this->assertSame('Gmail inbound sync is healthy.', $widget['detail']);
        $this->assertSame(0, $widget['consecutive_failures']);
    }

    public function test_historical_failures_do_not_keep_health_red_after_latest_success(): void
    {
        GmailMailboxSyncState::query()->create([
            'mailbox' => 'mail@radiumbox.com',
            'history_id' => '100',
            'profile_history_id' => '100',
            'enabled_at' => now()->subHour(),
            'baselined_at' => now()->subHour(),
            'last_synced_at' => now()->subMinute(),
            'last_attempted_at' => now()->subMinute(),
            'last_error' => null,
            'consecutive_failures' => 0,
            'oauth_status' => 'ok',
            'messages_failed_last_run' => 0,
        ]);

        \App\Models\GmailSyncMessageFailure::query()->create([
            'mailbox' => 'mail@radiumbox.com',
            'message_id' => 'msg-old',
            'endpoint' => 'messages.get',
            'http_status' => 400,
            'error_payload' => ['message' => 'Invalid id value'],
            'attempt_count' => 3,
            'elapsed_ms' => 120,
        ]);

        $widget = app(OperationsGmailHealthService::class)->widget();

        $this->assertSame('healthy', $widget['status']);
        $this->assertNull($widget['last_error']);
        $this->assertNotEmpty($widget['recent_failures']);
        $this->assertSame('msg-old', $widget['recent_failures'][0]['message_id']);
    }

    public function test_platform_integration_cache_invalidation_clears_stale_needs_attention(): void
    {
        GmailMailboxSyncState::query()->create([
            'mailbox' => 'mail@radiumbox.com',
            'history_id' => '100',
            'profile_history_id' => '100',
            'enabled_at' => now()->subHour(),
            'baselined_at' => now()->subHour(),
            'last_synced_at' => now()->subMinute(),
            'last_error' => 'Gmail OAuth token request failed: invalid_grant',
            'consecutive_failures' => 3,
            'oauth_status' => 'invalid_grant',
        ]);

        $overview = app(\App\Services\Platform\PlatformIntegrationHealthOverviewService::class);
        $stale = $overview->refreshItem('gmail');
        $this->assertSame('critical', $stale['status']);

        Cache::put(
            \App\Services\Platform\PlatformCachePolicy::KEY_INTEGRATION_ITEM_PREFIX.'gmail',
            $stale,
            120,
        );

        GmailMailboxSyncState::query()->where('mailbox', 'mail@radiumbox.com')->update([
            'last_error' => null,
            'consecutive_failures' => 0,
            'oauth_status' => 'ok',
            'last_synced_at' => now(),
            'messages_failed_last_run' => 0,
        ]);

        app(OperationsGmailHealthService::class)->invalidateCachedHealth();

        $this->assertNull(Cache::get(
            \App\Services\Platform\PlatformCachePolicy::KEY_INTEGRATION_ITEM_PREFIX.'gmail',
        ));

        $fresh = $overview->refreshItem('gmail');
        $this->assertSame('healthy', $fresh['status']);
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
            ->assertSee('Open Integration Health')
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
