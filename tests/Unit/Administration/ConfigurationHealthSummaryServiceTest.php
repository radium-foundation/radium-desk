<?php

namespace Tests\Unit\Administration;

use App\Services\Administration\ConfigurationHealthSummaryService;
use App\Services\Operations\OperationsGmailHealthService;
use App\Services\SystemSettingsService;
use App\Services\VersionService;
use Illuminate\Support\Facades\Config;
use Mockery;
use Tests\TestCase;

class ConfigurationHealthSummaryServiceTest extends TestCase
{
    protected function tearDown(): void
    {
        Mockery::close();

        parent::tearDown();
    }

    public function test_summary_reports_configuration_presence_without_runtime_probes(): void
    {
        Config::set('cashfree.client_secret', 'secret');
        Config::set('interakt.api_key', null);
        Config::set('mail.enabled', true);
        Config::set('mail.default', 'smtp');
        Config::set('inbound_email.enabled', true);
        Config::set('inbound_email.gmail.enabled', true);
        Config::set('app.env', 'testing');

        $systemSettings = Mockery::mock(SystemSettingsService::class);
        $systemSettings->shouldReceive('getBool')
            ->with('telegram.api_enabled', false)
            ->andReturn(true);

        $gmail = Mockery::mock(OperationsGmailHealthService::class);
        $gmail->shouldReceive('configuredMailboxes')->andReturn(['support@example.com']);

        $version = Mockery::mock(VersionService::class);
        $version->shouldReceive('version')->andReturn('4.0.0');
        $version->shouldReceive('build')->andReturn('abc1234');

        $summary = (new ConfigurationHealthSummaryService($systemSettings, $gmail, $version))->summary();
        $byKey = collect($summary['items'])->keyBy('key');

        $this->assertTrue($byKey['cashfree']['configured']);
        $this->assertTrue($byKey['gmail']['configured']);
        $this->assertTrue($byKey['telegram']['configured']);
        $this->assertFalse($byKey['interakt']['configured']);
        $this->assertFalse($byKey['meta']['configured']);
        $this->assertTrue($byKey['smtp']['configured']);
        $this->assertSame('testing', $summary['environment']);
        $this->assertSame('4.0.0', $summary['version']);
        $this->assertSame('abc1234', $summary['build']);
        $this->assertStringContainsString('/admin/platform', $summary['platform_url']);
        $this->assertStringContainsString('platform-zone-tools', $summary['platform_tools_url']);
    }
}
