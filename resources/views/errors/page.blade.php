{{--
    Shared error page. Self-contained (inline CSS, no Vite) so it still renders
    when assets or the database are unavailable. Branded with the current
    institution when one is known, otherwise HMIS.
--}}
@php
    try {
        $tenant = app(\App\Services\Tenancy\TenantContext::class)->tenant();
        $brand = $tenant
            ? \App\Support\MailBrand::forTenant($tenant)
            : (auth()->check() ? \App\Support\MailBrand::forUser(auth()->user()) : \App\Support\MailBrand::system());
    } catch (\Throwable) {
        $brand = \App\Support\MailBrand::system();
    }

    try {
        $signedIn = auth()->check();
    } catch (\Throwable) {
        $signedIn = false;
    }

    $icons = [
        'lock' => '<rect x="5" y="11" width="14" height="10" rx="2.5"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/><circle cx="12" cy="16" r="1.2" fill="currentColor"/>',
        'compass' => '<circle cx="12" cy="12" r="9"/><path d="m15.5 8.5-2 5-5 2 2-5z"/>',
        'clock' => '<circle cx="12" cy="12" r="9"/><path d="M12 7v5l3 2"/>',
        'gauge' => '<path d="M4.5 16a8 8 0 1 1 15 0"/><path d="m12 13 3.5-4"/><circle cx="12" cy="13.5" r="1.2" fill="currentColor"/>',
        'wrench' => '<path d="M14.7 6.3a4 4 0 0 0 5 5L13 18a2.1 2.1 0 0 1-3-3l6.7-6.7"/><path d="M9.5 15.5 5 20"/>',
        'tools' => '<path d="M3 21h18"/><path d="M6 21V10l6-5 6 5v11"/><path d="M10 21v-5h4v5"/>',
        'link' => '<path d="M9 15 15 9"/><path d="M11 6.5 12.5 5a4.2 4.2 0 0 1 6 6L17 12.5"/><path d="M13 17.5 11.5 19a4.2 4.2 0 0 1-6-6L7 11.5"/>',
        'alert' => '<path d="M12 3 2.5 20h19z"/><path d="M12 10v4"/><circle cx="12" cy="17" r="1" fill="currentColor"/>',
    ];
@endphp
<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <meta name="robots" content="noindex">
    @if (! empty($refresh))<meta http-equiv="refresh" content="{{ (int) $refresh }}">@endif
    <title>{{ $title }} · {{ $brand->name }}</title>
    <link rel="icon" href="{{ $brand->institution && $brand->logoUrl !== \App\Support\MailBrand::systemLogoUrl() ? $brand->logoUrl : '/favicon.svg' }}">
    <style>
        :root { --c1: {{ $brand->color }}; --c2: {{ $brand->color2 }}; --bg: #eef1f8; --card: #ffffff; --text: #18181b; --muted: #6b7084; --line: #e4e7f0; }
        @media (prefers-color-scheme: dark) { :root { --bg: #0f1220; --card: #181c2e; --text: #f4f4f5; --muted: #a1a5b8; --line: #2a2f45; } }
        * { box-sizing: border-box; }
        html, body { height: 100%; margin: 0; }
        body {
            font-family: 'Poppins', 'Segoe UI', -apple-system, BlinkMacSystemFont, Roboto, Helvetica, Arial, sans-serif;
            background: radial-gradient(1200px 600px at 10% -10%, color-mix(in srgb, var(--c1) 18%, transparent), transparent 60%),
                        radial-gradient(900px 500px at 110% 110%, color-mix(in srgb, var(--c2) 16%, transparent), transparent 60%),
                        var(--bg);
            color: var(--text);
            display: flex; align-items: center; justify-content: center;
            padding: 24px; -webkit-font-smoothing: antialiased;
        }
        .card {
            width: 100%; max-width: 520px; background: var(--card); border: 1px solid var(--line);
            border-radius: 24px; box-shadow: 0 20px 60px rgba(30, 41, 89, .12); overflow: hidden;
        }
        .band { height: 6px; background: linear-gradient(90deg, var(--c1), var(--c2)); }
        .inner { padding: 36px 36px 32px; text-align: center; }
        .brand { display: inline-flex; align-items: center; gap: 10px; color: var(--muted); font-size: 14px; font-weight: 600; text-decoration: none; }
        .brand img { width: 32px; height: 32px; border-radius: 9px; object-fit: contain; background: #fff; }
        .icon {
            width: 76px; height: 76px; margin: 28px auto 6px; border-radius: 22px; display: grid; place-items: center; color: #fff;
            background: linear-gradient(135deg, var(--c1), var(--c2)); box-shadow: 0 12px 30px color-mix(in srgb, var(--c1) 35%, transparent);
        }
        .icon svg { width: 38px; height: 38px; fill: none; stroke: currentColor; stroke-width: 1.8; stroke-linecap: round; stroke-linejoin: round; }
        .code {
            font-size: 64px; font-weight: 800; letter-spacing: -2px; line-height: 1; margin: 18px 0 6px;
            background: linear-gradient(135deg, var(--c1), var(--c2)); -webkit-background-clip: text; background-clip: text; color: transparent;
        }
        h1 { font-size: 22px; margin: 6px 0 10px; }
        p { color: var(--muted); font-size: 15px; line-height: 1.65; margin: 0 auto; max-width: 400px; }
        .detail { margin-top: 16px; padding: 12px 14px; border-radius: 12px; background: color-mix(in srgb, var(--c1) 8%, transparent); color: var(--text); font-size: 14px; text-align: left; }
        .actions { display: flex; flex-wrap: wrap; gap: 10px; justify-content: center; margin-top: 28px; }
        .btn {
            display: inline-flex; align-items: center; gap: 8px; padding: 11px 20px; border-radius: 12px; font-size: 14px; font-weight: 600;
            text-decoration: none; border: 1px solid transparent; cursor: pointer; font-family: inherit;
        }
        .btn-primary { color: #fff; background: linear-gradient(135deg, var(--c1), var(--c2)); box-shadow: 0 8px 20px color-mix(in srgb, var(--c1) 30%, transparent); }
        .btn-primary:hover { filter: brightness(1.06); }
        .btn-ghost { color: var(--text); background: transparent; border-color: var(--line); }
        .btn-ghost:hover { background: color-mix(in srgb, var(--c1) 6%, transparent); }
        .foot { border-top: 1px solid var(--line); padding: 14px 24px; font-size: 12px; color: var(--muted); text-align: center; }
        @media (max-width: 480px) { .inner { padding: 28px 22px 26px; } .code { font-size: 52px; } }
    </style>
</head>
<body>
    <main class="card" role="main">
        <div class="band"></div>
        <div class="inner">
            <a class="brand" href="{{ url('/') }}">
                <img src="{{ $brand->logoUrl }}" alt="">
                <span>{{ $brand->name }}</span>
            </a>

            <div class="icon" aria-hidden="true">
                <svg viewBox="0 0 24 24">{!! $icons[$icon ?? 'alert'] ?? $icons['alert'] !!}</svg>
            </div>

            <div class="code">{{ $code }}</div>
            <h1>{{ $title }}</h1>
            <p>{{ $message }}</p>

            @if (! empty($detail))
                <div class="detail">{{ $detail }}</div>
            @endif

            <div class="actions">
                @if (! empty($primary))
                    <a class="btn btn-primary" href="{{ $primary['url'] }}">{{ $primary['label'] }}</a>
                @elseif ($signedIn)
                    <a class="btn btn-primary" href="{{ url('/') }}">{{ __('Go to my dashboard') }}</a>
                @else
                    <a class="btn btn-primary" href="{{ url('/login') }}">{{ __('Go to login') }}</a>
                @endif
                @if ($back ?? true)
                    <button type="button" class="btn btn-ghost" onclick="history.length > 1 ? history.back() : location.assign('{{ url('/') }}')">{{ __('Go back') }}</button>
                @endif
            </div>
        </div>
        <div class="foot">
            @if ($brand->institution)
                {{ $brand->name }} · {{ \App\Support\MailBrand::SYSTEM_NAME }}
            @else
                {{ \App\Support\MailBrand::SYSTEM_NAME }} · {{ \App\Support\MailBrand::SYSTEM_TAGLINE }}
            @endif
        </div>
    </main>
</body>
</html>
