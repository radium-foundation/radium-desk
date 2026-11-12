<?php

namespace Tests\Feature\Platform;

use Tests\TestCase;

/**
 * Platform admin HTTP feature tests render Blade layouts that reference Vite;
 * PHPUnit worktrees do not ship a production manifest.
 */
abstract class PlatformFeatureTestCase extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->withoutVite();
    }
}
