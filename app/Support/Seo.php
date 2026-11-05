<?php

namespace App\Support;

use App\Models\PlatformSetting;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Storage;

/**
 * Search-engine settings, sitemap and robots rules.
 *
 * This is a clinical system: only explicitly public pages (config
 * seo.indexable_routes) may ever be indexed. Every other response carries
 * "noindex", and robots.txt disallows everything except the public pages.
 */
class Seo
{
    public const SETTINGS_KEY = 'seo';

    public const SITEMAP_PATH = 'seo/sitemap.xml';

    public static function defaults(): array
    {
        return [
            'indexing' => true,
            'site_name' => MailBrand::SYSTEM_NAME,
            'title_suffix' => MailBrand::SYSTEM_TAGLINE,
            'description' => 'Hospital management information system for Kenyan health facilities: patient records, SHA claims, billing and M-Pesa, pharmacy, laboratory and MOH reports.',
            'keywords' => 'hospital management system, HMIS, Kenya, SHA, SHIF, DHA, electronic medical records, EMR, hospital billing, pharmacy, laboratory, MOH 705, KHIS',
            'og_image' => null,
            'twitter' => null,
            'google_verification' => null,
            'bing_verification' => null,
            'organization' => 'Solforbs',
            'organization_url' => null,
            'contact_email' => null,
            'extra_urls' => [],
        ];
    }

    public static function settings(): array
    {
        return array_merge(self::defaults(), (array) PlatformSetting::get(self::SETTINGS_KEY, []));
    }

    public static function save(array $settings): void
    {
        PlatformSetting::put(self::SETTINGS_KEY, array_intersect_key($settings, self::defaults()));
    }

    public static function indexingEnabled(): bool
    {
        return (bool) self::settings()['indexing'];
    }

    /** Whether this response may appear in search results. */
    public static function isIndexable(Request $request): bool
    {
        return self::indexingEnabled()
            && in_array($request->getMethod(), ['GET', 'HEAD'], true)
            && in_array($request->route()?->getName(), config('seo.indexable_routes', []), true)
            && ! $request->query->count(); // query-string variants are never canonical
    }

    public static function ogImageUrl(): string
    {
        return self::settings()['og_image'] ?: rtrim((string) config('app.url'), '/').'/images/hmis-logo-512.png';
    }

    /** @return list<array{loc: string, changefreq: string, priority: string}> */
    public static function sitemapUrls(): array
    {
        if (! self::indexingEnabled()) {
            return [];
        }

        $urls = [];
        foreach (config('seo.indexable_routes', []) as $name) {
            if (Route::has($name)) {
                $urls[] = ['loc' => route($name), 'changefreq' => 'monthly', 'priority' => $name === 'login' ? '1.0' : '0.8'];
            }
        }

        foreach ((array) self::settings()['extra_urls'] as $url) {
            if (self::isOwnUrl($url)) {
                $urls[] = ['loc' => $url, 'changefreq' => 'monthly', 'priority' => '0.6'];
            }
        }

        return collect($urls)->unique('loc')->values()->all();
    }

    /** Builds the sitemap, stores it, and records when. Returns the URL count. */
    public static function generateSitemap(): int
    {
        $urls = self::sitemapUrls();
        $lastmod = now()->toAtomString();

        $xml = '<?xml version="1.0" encoding="UTF-8"?>'."\n"
            .'<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9">'."\n";
        foreach ($urls as $u) {
            $xml .= "  <url>\n"
                .'    <loc>'.htmlspecialchars($u['loc'], ENT_XML1)."</loc>\n"
                ."    <lastmod>{$lastmod}</lastmod>\n"
                ."    <changefreq>{$u['changefreq']}</changefreq>\n"
                ."    <priority>{$u['priority']}</priority>\n"
                ."  </url>\n";
        }
        $xml .= "</urlset>\n";

        Storage::disk('local')->put(self::SITEMAP_PATH, $xml);
        PlatformSetting::put('seo_sitemap', ['generated_at' => now()->toIso8601String(), 'count' => count($urls)]);

        return count($urls);
    }

    public static function sitemapXml(): string
    {
        if (! Storage::disk('local')->exists(self::SITEMAP_PATH)) {
            self::generateSitemap();
        }

        return Storage::disk('local')->get(self::SITEMAP_PATH);
    }

    public static function robotsTxt(): string
    {
        $base = rtrim((string) config('app.url'), '/');

        if (! self::indexingEnabled()) {
            return "# Search engine indexing is switched off.\nUser-agent: *\nDisallow: /\n";
        }

        $lines = ['# Only public pages may be indexed; patient and clinical data never is.', 'User-agent: *'];
        foreach (self::sitemapUrls() as $u) {
            $path = parse_url($u['loc'], PHP_URL_PATH) ?: '/';
            $lines[] = 'Allow: '.$path.'$';
        }
        // Let crawlers fetch what public pages need to render.
        foreach (['/build/', '/images/', '/favicon.svg', '/favicon.ico', '/apple-touch-icon.png', '/site.webmanifest'] as $asset) {
            $lines[] = 'Allow: '.$asset;
        }
        $lines[] = 'Disallow: /';
        $lines[] = '';
        $lines[] = 'Sitemap: '.$base.'/sitemap.xml';

        return implode("\n", $lines)."\n";
    }

    public static function isOwnUrl(?string $url): bool
    {
        return is_string($url)
            && filter_var($url, FILTER_VALIDATE_URL)
            && parse_url($url, PHP_URL_HOST) === parse_url((string) config('app.url'), PHP_URL_HOST);
    }
}
