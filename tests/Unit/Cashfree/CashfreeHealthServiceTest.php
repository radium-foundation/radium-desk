<?php

namespace Tests\Unit\Cashfree;

use App\Models\User;
use App\Services\Cashfree\CashfreeHealthService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class CashfreeHealthServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        config(['cashfree.verify_signature' => false]);
    }

    public function test_system_user_check_reports_missing_when_user_absent(): void
    {
        config(['cashfree.system_user_email' => 'missing-system@radium.local']);

        $check = app(CashfreeHealthService::class)->systemUserCheck();

        $this->assertSame(CashfreeHealthService::SYSTEM_USER_STATUS_MISSING, $check['status']);
        $this->assertSame('Missing', $check['label']);
        $this->assertSame('missing-system@radium.local', $check['email']);
        $this->assertNotNull($check['failure']);
    }

    public function test_system_user_check_reports_healthy_when_active_user_exists(): void
    {
        config(['cashfree.system_user_email' => 'superadmin@radium.local']);

        $user = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $check = app(CashfreeHealthService::class)->systemUserCheck();

        $this->assertSame(CashfreeHealthService::SYSTEM_USER_STATUS_HEALTHY, $check['status']);
        $this->assertSame('Healthy', $check['label']);
        $this->assertSame('superadmin@radium.local', $check['email']);
        $this->assertNull($check['failure']);
    }

    public function test_status_report_exposes_configuration_and_dependency_fields(): void
    {
        config([
            'cashfree.system_user_email' => 'superadmin@radium.local',
            'cashfree.verify_signature' => true,
            'cashfree.client_secret' => 'secret',
        ]);

        $user = User::factory()->create([
            'email' => 'superadmin@radium.local',
            'is_active' => true,
        ]);
        $user->assignRole(RolePermissionSeeder::ROLE_SUPERADMIN);

        $report = app(CashfreeHealthService::class)->status();

        $this->assertTrue($report->isHealthy());
        $this->assertSame('Configured', $report->webhookSecretStatusLabel);
        $this->assertSame('Healthy', $report->systemUserStatusLabel);
        $this->assertSame('superadmin@radium.local', $report->configuredEmail);
        $this->assertTrue($report->databaseReady);
        $this->assertArrayHasKey('system_user', $report->checks);
    }
}
