@props(['url', 'brand'])
<tr>
<td class="header" style="padding: 32px 0 0 0; text-align: center;">
<table class="header-card" align="center" width="570" cellpadding="0" cellspacing="0" role="presentation" style="background-color: {{ $brand['color'] }}; background-image: linear-gradient(135deg, {{ $brand['color'] }} 0%, {{ $brand['color2'] }} 100%); border-radius: 16px 16px 0 0;">
<tr>
<td style="padding: 28px 32px; text-align: left;">
<a href="{{ $url }}" style="text-decoration: none; display: block;">
<table cellpadding="0" cellspacing="0" role="presentation">
<tr>
<td style="padding-right: 16px; vertical-align: middle;">
<img src="{{ $brand['logo'] }}" width="56" height="56" alt="{{ $brand['name'] }}" class="logo" style="display: block; width: 56px; height: 56px; max-height: 56px; border-radius: 14px; background-color: #ffffff; padding: 4px; object-fit: contain;">
</td>
<td style="vertical-align: middle;">
<div class="brand-name" style="color: #ffffff; font-size: 19px; font-weight: 700; line-height: 1.3;">{{ $brand['name'] }}</div>
@if (! empty($brand['tagline']))
<div class="brand-tagline" style="color: rgba(255,255,255,0.85); font-size: 13px; line-height: 1.4; margin-top: 2px;">{{ $brand['tagline'] }}</div>
@endif
</td>
</tr>
</table>
</a>
</td>
</tr>
</table>
</td>
</tr>
