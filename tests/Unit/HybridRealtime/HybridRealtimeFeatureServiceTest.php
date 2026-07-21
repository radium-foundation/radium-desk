<?php

namespace Tests\Unit\HybridRealtime;

use App\Services\HybridRealtime\HybridRealtimeFeature;
use App\Services\HybridRealtime\HybridRealtimeFeatureService;
use App\Services\SystemSettingsService;
use Database\Seeders\SystemSettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use InvalidArgumentException;
use Tests\TestCase;

class HybridRealtimeFeatureServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SystemSettingsSeeder::class);
    }

    public function test_reference_number_defaults_to_off(): void
    {
        $service = app(HybridRealtimeFeatureService::class);

        $this->assertFalse($service->enabled(HybridRealtimeFeature::REFERENCE_NUMBER));
    }

    public function test_reference_number_enabled_when_system_setting_on(): void
    {
        app(SystemSettingsService::class)->set('hybrid_realtime.reference_number', true);

        $service = app(HybridRealtimeFeatureService::class);

        $this->assertTrue($service->enabled(HybridRealtimeFeature::REFERENCE_NUMBER));
    }

    public function test_env_kill_switch_disables_even_when_setting_on(): void
    {
        app(SystemSettingsService::class)->set('hybrid_realtime.reference_number', true);

        config([
            'hybrid_realtime.features.'.HybridRealtimeFeature::REFERENCE_NUMBER.'.env_kill_switch' => false,
        ]);

        $service = app(HybridRealtimeFeatureService::class);

        $this->assertFalse($service->enabled(HybridRealtimeFeature::REFERENCE_NUMBER));
    }

    public function test_assignment_and_close_resolve_can_be_enabled(): void
    {
        app(SystemSettingsService::class)->set('hybrid_realtime.assignment', true);
        app(SystemSettingsService::class)->set('hybrid_realtime.close_resolve', true);

        $service = app(HybridRealtimeFeatureService::class);

        $this->assertTrue($service->enabled(HybridRealtimeFeature::ASSIGNMENT));
        $this->assertTrue($service->enabled(HybridRealtimeFeature::CLOSE_RESOLVE));
    }

    public function test_unwired_features_remain_disabled(): void
    {
        app(SystemSettingsService::class)->set('hybrid_realtime.incoming_calls', true);
        app(SystemSettingsService::class)->set('hybrid_realtime.desktop_notifications', true);
        app(SystemSettingsService::class)->set('hybrid_realtime.operator_alerts', true);

        $service = app(HybridRealtimeFeatureService::class);

        $this->assertFalse($service->enabled(HybridRealtimeFeature::INCOMING_CALLS));
        $this->assertFalse($service->enabled(HybridRealtimeFeature::DESKTOP_NOTIFICATIONS));
        $this->assertFalse($service->enabled(HybridRealtimeFeature::OPERATOR_ALERTS));
    }

    public function test_unknown_feature_throws(): void
    {
        $this->expectException(InvalidArgumentException::class);

        app(HybridRealtimeFeatureService::class)->enabled('not_a_feature');
    }
}
