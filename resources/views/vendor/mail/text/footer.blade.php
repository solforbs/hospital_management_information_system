@props(['brand'])
--
{{ $brand['name'] }}
@foreach ($brand['contact'] ?? [] as $line)
{{ $line }}
@endforeach
@if (! empty($brand['institution']))
{{ __('Sent via :system — :tagline', ['system' => \App\Support\MailBrand::SYSTEM_NAME, 'tagline' => \App\Support\MailBrand::SYSTEM_TAGLINE]) }}
@endif
{{ __('This is an automated message. Please do not reply to this email.') }}
