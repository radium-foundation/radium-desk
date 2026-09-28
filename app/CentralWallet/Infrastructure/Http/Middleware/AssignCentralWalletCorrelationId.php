<?php

namespace App\CentralWallet\Infrastructure\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Response;

final class AssignCentralWalletCorrelationId
{
    public function handle(Request $request, Closure $next): Response
    {
        $correlationId = trim((string) $request->header('X-Correlation-Id', ''));
        if ($correlationId === '') {
            $correlationId = (string) Str::uuid();
        }

        $request->attributes->set('central_wallet_correlation_id', $correlationId);

        $response = $next($request);

        if ($response instanceof Response) {
            $response->headers->set('X-Correlation-Id', $correlationId);
        }

        return $response;
    }
}
