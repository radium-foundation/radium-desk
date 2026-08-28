<?php

namespace App\Http\Middleware\Commerce;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnsureAuthenticatedCommerceSiteMatchesRoute
{
    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $routeSite = (string) $request->route('site');
        $authenticatedSite = $request->attributes->get('commerce_site');

        if ($authenticatedSite === null || $routeSite === '' || $authenticatedSite->site_id !== $routeSite) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        return $next($request);
    }
}
