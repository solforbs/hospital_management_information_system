@props(['brand' => null])
@php
    $brand = $brand ?: \App\Support\MailBrand::system()->toArray();
@endphp
<x-mail::layout :brand="$brand">
{{-- Header --}}
<x-slot:header>
<x-mail::header :url="config('app.url')" :brand="$brand" />
</x-slot:header>

{{-- Body --}}
{!! $slot !!}

{{-- Subcopy --}}
@isset($subcopy)
<x-slot:subcopy>
<x-mail::subcopy>
{!! $subcopy !!}
</x-mail::subcopy>
</x-slot:subcopy>
@endisset

{{-- Footer --}}
<x-slot:footer>
<x-mail::footer :brand="$brand" />
</x-slot:footer>
</x-mail::layout>
