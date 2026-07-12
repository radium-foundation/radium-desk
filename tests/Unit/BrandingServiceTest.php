<?php

namespace Tests\Unit;

use App\Services\BrandingService;
use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class BrandingServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
    }

    public function test_default_brand_assets_resolve_from_config(): void
    {
        $branding = app(BrandingService::class);

        $this->assertSame(asset(config('branding.logo')), $branding->logoUrl());
        $this->assertSame(asset(config('branding.icon')), $branding->iconUrl());
        $this->assertSame(asset(config('branding.favicon')), $branding->faviconUrl());
        $this->assertTrue($branding->hasLogo());
        $this->assertTrue($branding->hasIcon());
    }
}
