<?php

namespace App\Http\Middleware\Commerce;

use App\Models\Commerce\CommerceSite;
use App\Services\Commerce\CommerceSiteAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuthenticateCommerceSite
{
    public function __construct(
        private readonly CommerceSiteAuthenticator $authenticator,
    ) {}

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $site = $this->authenticator->authenticate($request);

        if ($site === null) {
            return response()->json([
                'message' => 'Unauthenticated.',
            ], Response::HTTP_UNAUTHORIZED);
        }

        $request->attributes->set('commerce_site', $site);

        return $next($request);
    }
}
