<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class SetAuthenticatedHtmlCacheControl
{
    private const CACHE_CONTROL = 'no-store, no-cache, must-revalidate, private';

    /**
     * @param  Closure(Request): Response  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if ($request->user() === null) {
            return $response;
        }

        if (! $this->isHtmlResponse($response)) {
            return $response;
        }

        $response->headers->set('Cache-Control', self::CACHE_CONTROL);

        return $response;
    }

    private function isHtmlResponse(Response $response): bool
    {
        $contentType = $response->headers->get('Content-Type', '');

        return str_contains(strtolower($contentType), 'text/html');
    }
}