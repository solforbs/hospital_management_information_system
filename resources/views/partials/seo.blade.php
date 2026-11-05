@php
    $seo = \App\Support\Seo::settings();
    $indexable = \App\Support\Seo::isIndexable(request());
    $pageTitle = filled($title ?? null) ? $title.' · '.$seo['site_name'] : $seo['site_name'].' — '.$seo['title_suffix'];
    $canonical = url()->current();
    $ogImage = \App\Support\Seo::ogImageUrl();
@endphp
{{-- Search-engine verification (harmless on every page; checked on the public ones). --}}
@if ($seo['google_verification'])
<meta name="google-site-verification" content="{{ $seo['google_verification'] }}">
@endif
@if ($seo['bing_verification'])
<meta name="msvalidate.01" content="{{ $seo['bing_verification'] }}">
@endif
<meta name="theme-color" content="#4E7CFF">
<link rel="manifest" href="/site.webmanifest">
@if ($indexable)
<meta name="robots" content="index, follow, max-image-preview:large">
<meta name="description" content="{{ $seo['description'] }}">
@if ($seo['keywords'])
<meta name="keywords" content="{{ $seo['keywords'] }}">
@endif
<link rel="canonical" href="{{ $canonical }}">
<meta property="og:type" content="website">
<meta property="og:site_name" content="{{ $seo['site_name'] }}">
<meta property="og:title" content="{{ $pageTitle }}">
<meta property="og:description" content="{{ $seo['description'] }}">
<meta property="og:url" content="{{ $canonical }}">
<meta property="og:image" content="{{ $ogImage }}">
<meta property="og:locale" content="en_KE">
<meta name="twitter:card" content="summary_large_image">
<meta name="twitter:title" content="{{ $pageTitle }}">
<meta name="twitter:description" content="{{ $seo['description'] }}">
<meta name="twitter:image" content="{{ $ogImage }}">
@if ($seo['twitter'])
<meta name="twitter:site" content="{{ '@'.ltrim($seo['twitter'], '@') }}">
@endif
<script type="application/ld+json">
{!! json_encode([
    '@context' => 'https://schema.org',
    '@graph' => array_values(array_filter([
        [
            '@type' => 'WebSite',
            'name' => $seo['site_name'],
            'alternateName' => $seo['title_suffix'],
            'url' => url('/'),
            'description' => $seo['description'],
            'inLanguage' => 'en-KE',
        ],
        [
            '@type' => 'SoftwareApplication',
            'name' => $seo['site_name'].' — '.$seo['title_suffix'],
            'applicationCategory' => 'HealthApplication',
            'operatingSystem' => 'Web browser',
            'url' => url('/'),
            'image' => $ogImage,
            'description' => $seo['description'],
            'areaServed' => ['@type' => 'Country', 'name' => 'Kenya'],
        ],
        $seo['organization'] ? array_filter([
            '@type' => 'Organization',
            'name' => $seo['organization'],
            'url' => $seo['organization_url'] ?: url('/'),
            'logo' => \App\Support\MailBrand::systemLogoUrl(),
            'email' => $seo['contact_email'] ?: null,
        ]) : null,
    ])),
], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_HEX_TAG | JSON_HEX_AMP | JSON_PRETTY_PRINT) !!}
</script>
@else
<meta name="robots" content="noindex, nofollow, noarchive">
@endif
