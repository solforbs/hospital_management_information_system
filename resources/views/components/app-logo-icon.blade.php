{{--
    System mark: a rounded tile in the brand gradient (Primary 1 → Primary 2)
    carrying a white medical cross with a coral heartbeat line running through it.
    Self-coloured, so it reads the same on light and dark backgrounds.
--}}
@php
    $gid = 'hmsLogo'.substr(md5(uniqid('', true)), 0, 6);
@endphp
<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 48 48" role="img" aria-label="{{ config('app.name') }}" {{ $attributes }}>
    <defs>
        <linearGradient id="{{ $gid }}" x1="4" y1="2" x2="44" y2="46" gradientUnits="userSpaceOnUse">
            <stop offset="0" stop-color="#4E7CFF" />
            <stop offset="0.65" stop-color="#6A4DFF" />
            <stop offset="1" stop-color="#7033FF" />
        </linearGradient>
    </defs>
    <rect width="48" height="48" rx="13" fill="url(#{{ $gid }})" />
    <circle cx="41" cy="7" r="10" fill="#FFFFFF" opacity="0.10" />
    <circle cx="6" cy="44" r="9" fill="#F65164" opacity="0.22" />
    <rect x="19" y="9.5" width="10" height="29" rx="3.2" fill="#FFFFFF" />
    <rect x="9.5" y="19" width="29" height="10" rx="3.2" fill="#FFFFFF" />
    <polyline points="5,25 15,25 18,25 20.6,19.2 24,31.5 27.2,15.5 30,25 43,25" fill="none" stroke="#FFFFFF" stroke-width="5" stroke-linecap="round" stroke-linejoin="round" />
    <polyline points="5,25 15,25 18,25 20.6,19.2 24,31.5 27.2,15.5 30,25 43,25" fill="none" stroke="#F65164" stroke-width="2.6" stroke-linecap="round" stroke-linejoin="round" />
</svg>
