{{--
    Signature block for a printed document: every active signatory assigned
    to $document, with their signature image, plus the official stamp when
    the institution enabled it.
--}}
@props(['tenant', 'document'])

@php
    $people = $tenant ? $tenant->signatoriesFor($document) : collect();
    $stamp = $tenant && $tenant->setting('documents.show_stamp') ? $tenant->stampUrl() : null;
@endphp

@if ($people->isNotEmpty() || $stamp)
    <div {{ $attributes->merge(['style' => 'display: flex; flex-wrap: wrap; align-items: flex-end; gap: 28px; margin-top: 18px; color: #33394C;']) }}>
        @foreach ($people as $p)
            <div style="min-width: 150px;">
                <div style="height: 44px; display: flex; align-items: flex-end;">
                    @if ($p->signatureUrl())<img src="{{ $p->signatureUrl() }}" alt="" style="max-height: 44px; max-width: 160px; object-fit: contain;">@endif
                </div>
                <div style="border-top: 1px solid #9CA4B8; padding-top: 3px; font-size: 11px; font-weight: 600;">{{ $p->name }}</div>
                <div style="font-size: 10px; color: #6E768A;">{{ $p->title }}@if ($p->qualifications), {{ $p->qualifications }}@endif</div>
                @if ($p->registration)<div style="font-size: 10px; color: #9CA4B8;">{{ $p->registration }}</div>@endif
            </div>
        @endforeach
        @if ($stamp)
            <img src="{{ $stamp }}" alt="" style="height: 70px; width: auto; opacity: 0.85; margin-inline-start: auto;">
        @endif
    </div>
@endif
