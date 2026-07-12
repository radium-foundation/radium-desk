<?php

namespace Tests\Unit\Notifications;

use Database\Seeders\SettingsSeeder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class EmailMasterLayoutTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        $this->seed(SettingsSeeder::class);
    }

    public function test_master_layout_renders_core_structure_and_logo(): void
    {
        $html = view('emails.layouts.master')->render();

        $this->assertStringContainsString('max-width: 600px', $html);
        $this->assertStringContainsString('background-color: #ffffff', $html);
        $this->assertStringContainsString('border: 1px solid #e9ecef', $html);
        $this->assertStringContainsString('brand/icon.svg', $html);
        $this->assertStringContainsString('All rights reserved.', $html);
    }

    public function test_master_layout_renders_optional_sections(): void
    {
        $html = view('emails.previews.section-variants')->render();

        $this->assertStringContainsString('Notification Design System', $html);
        $this->assertStringContainsString('Dear Customer,', $html);
        $this->assertStringContainsString('background-color: #e7f1ff', $html);
        $this->assertStringContainsString('background-color: #d1e7dd', $html);
        $this->assertStringContainsString('background-color: #fff3cd', $html);
        $this->assertStringContainsString('background-color: #f8d7da', $html);
        $this->assertStringContainsString('Example CTA Button', $html);
        $this->assertStringContainsString('support@radiumbox.com', $html);
        $this->assertStringContainsString('Team Radium Box', $html);
    }

    public function test_master_layout_renders_info_box_status_variant(): void
    {
        $html = view('emails.previews.info-box-warning')->render();

        $this->assertStringContainsString('background-color: #fff3cd', $html);
        $this->assertStringContainsString('border-left: 4px solid #997404', $html);
        $this->assertStringContainsString('Device setup is pending.', $html);
    }
}
