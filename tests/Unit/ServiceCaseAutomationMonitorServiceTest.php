<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\ServiceCaseAutomationMonitorService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class ServiceCaseAutomationMonitorServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'cache.default' => 'file',
            'cashfree.system_user_email' => 'superadmin@radium.local',
        ]);
    }

    public function test_resolve_automation_actor_reloads_user_from_cached_id_after_serialization(): void
    {
        $systemUser = User::factory()->create([
            'email' => 'superadmin@radium.local',
        ]);

        $monitor = app(ServiceCaseAutomationMonitorService::class);

        $first = $monitor->resolveAutomationActor();
        $this->assertSame($systemUser->id, $first->id);
        $this->assertInstanceOf(User::class, $first);

        Cache::flush();

        $cachedId = Cache::remember(
            'automation.monitor.actor_id.superadmin@radium.local',
            now()->addDay(),
            fn (): int => $systemUser->id,
        );
        $this->assertSame($systemUser->id, $cachedId);

        $second = $monitor->resolveAutomationActor();
        $this->assertInstanceOf(User::class, $second);
        $this->assertSame($systemUser->id, $second->id);
        $this->assertSame('superadmin@radium.local', $second->email);
    }

    public function test_resolve_automation_actor_falls_back_when_cached_user_is_deleted(): void
    {
        $systemUser = User::factory()->create([
            'email' => 'superadmin@radium.local',
        ]);
        $fallbackUser = User::factory()->create([
            'email' => 'fallback@radium.local',
        ]);

        Cache::put('automation.monitor.actor_id.superadmin@radium.local', $systemUser->id, now()->addDay());
        $systemUser->delete();

        $monitor = app(ServiceCaseAutomationMonitorService::class);
        $actor = $monitor->resolveAutomationActor($fallbackUser);

        $this->assertInstanceOf(User::class, $actor);
        $this->assertSame($fallbackUser->id, $actor->id);
    }
}
