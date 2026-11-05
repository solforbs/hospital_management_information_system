{{-- Parameters of a multi-parameter lab result (e.g. full blood count). --}}
<table class="text-xs">
    <tbody>
        @foreach ($components as $c)
            <tr>
                <td class="pe-3 text-neutral-500">{{ $c->name }}</td>
                <td class="pe-1 text-right font-semibold {{ $c->flag === 'critical' ? 'text-red-600' : (in_array($c->flag, ['high', 'low'], true) ? 'text-amber-600' : '') }}">{{ $c->value }}</td>
                <td class="pe-2 text-neutral-500">{{ $c->unit }}</td>
                <td class="pe-2 text-neutral-400">{{ $c->reference_range }}</td>
                <td class="font-semibold {{ $c->flag === 'critical' ? 'text-red-600' : 'text-amber-600' }}">{{ match ($c->flag) { 'high' => 'H', 'low' => 'L', 'critical' => '!!', default => '' } }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
