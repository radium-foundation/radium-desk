<?php

namespace App\Http\Middleware;

use App\Services\PreShipmentCancellation\PreShipmentCancellationFeature;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

final class EnsurePreShipmentCancellationEnabled
{
    public function __construct(
        private readonly PreShipmentCancellationFeature $feature,
    ) {}

    public function handle(Request $request, Closure $next): Response
    {
        if ($this->feature->enabled()) {
            return $next($request);
        }

        abort($request->isMethod('GET') ? 404 : 403, 'Pre-shipment cancellation is not enabled.');
    }
}
