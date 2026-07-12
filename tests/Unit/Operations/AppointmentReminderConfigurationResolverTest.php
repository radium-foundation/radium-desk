<?php

namespace Tests\Unit\Operations;

use App\Services\Operations\AppointmentReminderConfigurationResolver;
use App\Models\User;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class AppointmentReminderConfigurationResolverTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);

        config([
            'team_telegram.appointment_reminders.enabled' => true,
            'team_telegram.appointment_reminders.role_thresholds_minutes' => [
                'default' => [30, 10, 0],
                'support_specialist' => [30, 10, 0],
                'manager' => [],
            ],
        ]);
    }

    public function test_resolver_returns_default_engineer_thresholds(): void
    {
        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $configuration = app(AppointmentReminderConfigurationResolver::class)->forUser($agent);

        $this->assertTrue($configuration->enabled);
        $this->assertSame([30, 10, 0], $configuration->thresholdsMinutes);
    }

    public function test_resolver_can_disable_role_specific_reminders(): void
    {
        $manager = User::factory()->create(['is_active' => true]);
        $manager->assignRole(RolePermissionSeeder::ROLE_ADMIN);

        config([
            'team_telegram.appointment_reminders.role_thresholds_minutes' => [
                'default' => [30, 10, 0],
                'admin' => [],
            ],
        ]);

        $configuration = app(AppointmentReminderConfigurationResolver::class)->forUser($manager);

        $this->assertTrue($configuration->isDisabled());
    }

    public function test_resolver_returns_disabled_configuration_when_globally_disabled(): void
    {
        config(['team_telegram.appointment_reminders.enabled' => false]);

        $agent = User::factory()->create(['is_active' => true]);
        $agent->assignRole(RolePermissionSeeder::ROLE_SUPPORT_SPECIALIST);

        $configuration = app(AppointmentReminderConfigurationResolver::class)->forUser($agent);

        $this->assertTrue($configuration->isDisabled());
    }
}
