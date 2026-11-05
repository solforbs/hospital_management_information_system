<?php

namespace App\Http\Middleware;

use App\Support\Seo;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Tells search engines not to index anything except explicitly public pages.
 * The header also covers non-HTML responses (JSON, PDFs, images of records)
 * that a meta tag cannot.
 */
class SeoHeaders
{
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        if (! $response->headers->has('X-Robots-Tag') && ! Seo::isIndexable($request)) {
            $response->headers->set('X-Robots-Tag', 'noindex, nofollow, noarchive');
        }

        return $response;
    }
}
