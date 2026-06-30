<?php

namespace Tests;

use Illuminate\Foundation\Testing\TestCase as BaseTestCase;

abstract class TestCase extends BaseTestCase
{
    protected function tearDown(): void
    {
        \Illuminate\Support\Carbon::setTestNow();

        parent::tearDown();
    }
}
