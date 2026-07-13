<?php

namespace Tests\Concerns;

use Illuminate\Foundation\Http\Middleware\PreventRequestForgery;

trait DisablesRequestForgeryProtection
{
    protected function disableRequestForgeryProtection(): void
    {
        $this->withoutMiddleware(PreventRequestForgery::class);
    }
}
