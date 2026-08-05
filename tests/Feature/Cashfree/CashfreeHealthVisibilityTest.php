<?php

namespace Tests\Feature\Cashfree;

use App\Services\Cashfree\CashfreeHealthService;
use App\Services\Operations\OperationsCashfreeHealthService;
use Database\Seeders\RolePermissionSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\Concerns\EnsuresCashfreeSystemUser;
use Tests\TestCase;

class CashfreeHealthVisibilityTest extends TestCase
{
    use EnsuresCashfreeSystemUser;
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(RolePermissionSeeder::class);
        Cache::flush();
    }

    public function test_operations_cashfree_widget_exposes_system_user_and_dependency_fields(): void
    {
        $this->ensureCashfreeSystemUser();

        $widget = app(OperationsCashfreeHealthService::class)->widget(useCache: false);

        $this->assertSame('Healthy', $widget['system_user_status_label']);
        $this->assertSame('superadmin@radium.local', $widget['system_user_email']);
        $this->assertArrayHasKey('webhook_secret_status_label', $widget);
        $this->assertArrayHasKey('queue_pending', $widget);
        $this->assertArrayHasKey('outbox_pending', $widget);
        $this->assertArrayHasKey('latest_webhook_at', $widget);
        $this->assertArrayHasKey('last_failed_webhook_at', $widget);
        $this->assertTrue($widget['database_ready']);
    }

    public function test_operations_cashfree_widget_marks_missing_system_user(): void
    {
        config([
            'cashfree.system_user_email' => 'ghost@radium.local',
            'cashfree.verify_signature' => false,
        ]);

        $widget = app(OperationsCashfreeHealthService::class)->widget(useCache: false);

        $this->assertSame(CashfreeHealthService::SYSTEM_USER_STATUS_MISSING, $widget['system_user_status']);
        $this->assertSame('Missing', $widget['system_user_status_label']);
        $this->assertSame('ghost@radium.local', $widget['system_user_email']);
        $this->assertFalse($widget['is_healthy']);
        $this->assertStringContainsString('System user missing', $widget['detail']);
    }
}
