<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Session\Middleware\StartSession;
use Symfony\Component\HttpFoundation\Response;

/**
 * Marks session-status probe requests so Laravel skips persisting session data.
 *
 * @see StartSession::saveSession()
 */
class MarkSessionStatusProbe
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $request->attributes->set('precognitive', true);

        return $next($request);
    }
}
