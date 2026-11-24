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

        if ($request->user() === null || ! $this->isHtmlResponse($response)) {
            return $response;
        }

        $response->headers->set('Cache-Control', self::CACHE_CONTROL, true);

        return $response;
    }

    private function isHtmlResponse(Response $response): bool
    {
        $contentType = strtolower($response->headers->get('Content-Type', ''));

        if (str_contains($contentType, 'text/html')) {
            return true;
        }

        $content = $response->getContent();

        return is_string($content) && str_starts_with(ltrim($content), '<');
    }
}
