<?php

namespace App\CentralWallet\Infrastructure\Http\Middleware;

use App\CentralWallet\Infrastructure\Auth\CentralWalletIntegrationAuthenticator;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class AuthenticateCentralWalletIntegration
{
    public function __construct(
        private readonly CentralWalletIntegrationAuthenticator $authenticator,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if (! $this->authenticator->authenticate($request)) {
            return response()->json([
                'error' => 'unauthorized',
                'message' => CentralWalletIntegrationAuthenticator::ERROR_UNAUTHORIZED,
            ], 401);
        }

        $request->attributes->set('central_wallet_caller_id', $this->authenticator->callerId($request));

        return $next($request);
    }
}
