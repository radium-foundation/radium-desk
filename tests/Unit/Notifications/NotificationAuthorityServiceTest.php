<?php

namespace Tests\Unit\Notifications;

use App\Enums\NotificationCategory;
use App\Enums\NotificationChannelType;
use App\Models\SystemSetting;
use App\Models\TeamMemberWorkSchedule;
use App\Models\User;
use App\Services\Notifications\NotificationAuthorityService;
use App\Services\SettingService;
use Database\Seeders\RolePermissionSeeder;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Tests\TestCase;

class NotificationAuthorityServiceTest extends TestCase
{
    use RefreshDatabase;

    private NotificationAuthorityService $authority;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        $this->seed(SettingsSeeder::class);

        $this->authority = app(NotificationAuthorityService::class);

        Carbon::setTestNow(Carbon::parse('2026-07-06 10:00:00', 'Asia/Kolkata'));
    }

    protected function tearDown(): void
    {
        Carbon::setTestNow();

        parent::tearDown();
    }

    public function test_superadmin_is_always_allowed_outside_work_hours(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 20:00:00', 'Asia/Kolkata'));

        $superadmin = $this->createSuperadmin();

        $this->assertTrue($this->authority->shouldDeliver(
            $superadmin,
            NotificationCategory::Assignment,
            NotificationChannelType::InApp,
        ));
    }

    public function test_telegram_disabled_user_is_blocked_for_telegram_channel(): void
    {
        $this->enableTelegramChannel();

        $agent = $this->createScheduledAgent([
            'telegram_notifications_enabled' => false,
            'telegram_chat_id' => '123456789',
        ]);

        $this->assertFalse($this->authority->shouldDeliver(
            $agent,
            NotificationCategory::Assignment,
            NotificationChannelType::Telegram,
        ));
    }

    public function test_work_hours_user_outside_schedule_is_blocked(): void
    {
        Carbon::setTestNow(Carbon::parse('2026-07-06 20:00:00', 'Asia/Kolkata'));

        $agent = $this->createScheduledAgent();

        $this->assertFalse($this->authority->shouldDeliver(
            $agent,
            NotificationCategory::Assignment,
            NotificationChannelType::InApp,
        ));
    }

    public function test_existing_enabled_user_still_works_during_work_hours(): void
    {
        $this->enableTelegramChannel();

        $agent = $this->createScheduledAgent([
            'telegram_notifications_enabled' => true,
            'telegram_chat_id' => '123456789',
        ]);

        $this->assertTrue($this->authority->shouldDeliver(
            $agent,
            NotificationCategory::Assignment,
            NotificationChannelType::Telegram,
        ));

        $this->assertTrue($this->authority->shouldDeliver(
            $agent,
            NotificationCategory::Assignment,
            NotificationChannelType::InApp,
        ));
    }

    public function test_channel_disabled_globally_blocks_delivery(): void
    {
        $this->setSystemSetting('notifications.telegram.enabled', false);

        $agent = $this->createScheduledAgent([
            'telegram_notifications_enabled' => true,
            'telegram_chat_id' => '123456789',
        ]);

        $this->assertFalse($this->authority->channelEnabled(NotificationChannelType::Telegram));
        $this->assertFalse($this->authority->shouldDeliver(
            $agent,
            NotificationCategory::Assignment,
            NotificationChannelType::Telegram,
        ));
    }

    public function test_unknown_category_safe_default_allows_delivery_when_other_gates_pass(): void
    {
        $agent = $this->createScheduledAgent();

        $this->assertTrue($this->authority->categoryEnabled(NotificationCategory::Ivr));
        $this->assertTrue($this->authority->shouldDeliver(
            $agent,
            NotificationCategory::Ivr,
            NotificationChannelType::InApp,
        ));
    }

    public function test_org_assignment_toggle_blocks_assignment_category(): void
    {
        app(SettingService::class)->set('notifications.assignment_enabled', '0');

        $agent = $this->createScheduledAgent();

        $this->assertFalse($this->authority->shouldDeliver(
            $agent,
            NotificationCategory::Assignment,
            NotificationChannelType::InApp,
        ));
    }

    public function test_default_schedule_mode_follows_role(): void
    {
        $superadmin = $this->createSuperadmin();
        $agent = $this->createScheduledAgent();
        $operationsAdmin = User::factory()->create(['is_active' => true]);
        $operationsAdmin->assignRole(RolePermissionSeeder::ROLE_OPERATIONS_ADMIN);

        $this->assertSame(
            \App\Enums\NotificationScheduleMode::Always,
            $this->authority->defaultScheduleModeFor($superadmin),
        );
        $this->assertSame(
            \App\Enums\NotificationScheduleMode::WorkHours,
            $this->authority->defaultScheduleModeFor($agent),
        );
        $this->assertSame(
            \App\Enums\NotificationScheduleMode::ExtendedHours,
            $this->authority->defaultScheduleModeFor($operationsAdmin),
        );
    }

    private function createSuperadmin(): User
    {
        $user = User::factory()->create([
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        return $user->fresh();
    }

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function createScheduledAgent(array $overrides = []): User
    {
        $user = User::factory()->create(array_merge([
            'is_active' => true,
        ], $overrides));
        $user->assignRole(RolePermissionSeeder::ROLE_AGENT);

        TeamMemberWorkSchedule::query()->create([
            'user_id' => $user->id,
            'work_start_time' => '09:00:00',
            'work_end_time' => '18:00:00',
            'lunch_start_time' => '13:30:00',
            'lunch_end_time' => '14:00:00',
            'short_break_count' => 2,
            'short_break_minutes' => 10,
            'weekly_off_days' => [Carbon::SUNDAY],
        ]);

        return $user->fresh(['workSchedule']);
    }

    private function enableTelegramChannel(): void
    {
        $this->setSystemSetting('notifications.telegram.enabled', true);
    }

    private function setSystemSetting(string $key, bool $value): void
    {
        SystemSetting::query()->updateOrCreate(
            ['key' => $key],
            ['value' => $value ? '1' : '0'],
        );

        app(\App\Services\SystemSettingsService::class)->forget($key);
    }
}
