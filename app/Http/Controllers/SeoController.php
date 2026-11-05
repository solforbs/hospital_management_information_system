<?php

namespace App\Http\Controllers;

use App\Support\Seo;

class SeoController extends Controller
{
    public function sitemap()
    {
        return response(Seo::sitemapXml(), 200, [
            'Content-Type' => 'application/xml; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
            'X-Robots-Tag' => 'noindex', // the file itself should not show in results
        ]);
    }

    public function robots()
    {
        return response(Seo::robotsTxt(), 200, [
            'Content-Type' => 'text/plain; charset=UTF-8',
            'Cache-Control' => 'public, max-age=3600',
        ]);
    }

    public function manifest()
    {
        $s = Seo::settings();

        return response()->json([
            'name' => $s['site_name'].' — '.$s['title_suffix'],
            'short_name' => $s['site_name'],
            'description' => $s['description'],
            'start_url' => '/',
            'display' => 'standalone',
            'background_color' => '#eef1f8',
            'theme_color' => '#4E7CFF',
            'icons' => [
                ['src' => '/apple-touch-icon.png', 'sizes' => '180x180', 'type' => 'image/png'],
                ['src' => '/images/hmis-logo-512.png', 'sizes' => '512x512', 'type' => 'image/png', 'purpose' => 'any'],
                ['src' => '/favicon.svg', 'sizes' => 'any', 'type' => 'image/svg+xml'],
            ],
        ], 200, ['Content-Type' => 'application/manifest+json', 'Cache-Control' => 'public, max-age=86400']);
    }
}
