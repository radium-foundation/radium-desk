<?php

namespace Tests\Unit\Navigation;

use Tests\TestCase;

class SidebarScrollLayoutCssTest extends TestCase
{
    public function test_sidebar_uses_viewport_bounded_scroll_container(): void
    {
        $css = file_get_contents(resource_path('css/app.css'));

        $this->assertNotFalse($css);
        $this->assertMatchesRegularExpression(
            '/\.app-sidebar\s*\{[^}]*max-height:\s*100dvh;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.app-sidebar\s*\{[^}]*overflow:\s*hidden;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.app-sidebar nav\s*\{[^}]*min-height:\s*0;/s',
            $css,
        );
        $this->assertMatchesRegularExpression(
            '/\.app-sidebar nav\s*\{[^}]*overflow-y:\s*auto;/s',
            $css,
        );
    }
}
