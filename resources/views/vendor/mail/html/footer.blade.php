@props(['brand'])
<tr>
<td>
<table class="footer" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td class="content-cell" align="center">
@if (! empty($brand['institution']))
<p style="font-weight: 600; color: #52525b; margin-bottom: 4px;">{{ $brand['name'] }}</p>
@foreach ($brand['contact'] ?? [] as $line)
<p style="margin: 0;">{{ $line }}</p>
@endforeach
<p style="margin-top: 14px;">{{ __('Sent via :system — :tagline', ['system' => \App\Support\MailBrand::SYSTEM_NAME, 'tagline' => \App\Support\MailBrand::SYSTEM_TAGLINE]) }}</p>
@else
<p style="font-weight: 600; color: #52525b; margin-bottom: 4px;">{{ \App\Support\MailBrand::SYSTEM_NAME }}</p>
<p style="margin: 0;">{{ \App\Support\MailBrand::SYSTEM_TAGLINE }}</p>
@endif
<p style="margin-top: 6px;">{{ __('This is an automated message. Please do not reply to this email.') }}</p>
<p style="margin-top: 6px;">© {{ date('Y') }} {{ $brand['name'] }}</p>
</td>
</tr>
</table>
</td>
</tr>
