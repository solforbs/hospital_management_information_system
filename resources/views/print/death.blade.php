<x-print-document :tenant="$tenant" :facility="$facility" :title="__('Death notification')" :number="$death->notification_number" :date="$death->died_at" :patient="$death->patient" document="death_notification">
    <h2>{{ __('Death') }}</h2>
    <div class="grid">
        <div><div class="label">{{ __('Date & time of death') }}</div><div class="value">{{ $death->died_at->format('d M Y H:i') }}</div></div>
        <div><div class="label">{{ __('Place') }}</div><div class="value">{{ \App\Models\DeathNotification::PLACES[$death->place] ?? $death->place }}</div></div>
        <div><div class="label">{{ __('Manner') }}</div><div class="value">{{ \App\Models\DeathNotification::MANNERS[$death->manner] ?? $death->manner }}</div></div>
    </div>

    <h2>{{ __('Cause of death') }}</h2>
    <table>
        <thead><tr><th>{{ __('Part I') }}</th><th>{{ __('Condition') }}</th><th>{{ __('ICD-11') }}</th></tr></thead>
        <tbody>
            <tr><td>{{ __('(a) Immediate cause') }}</td><td>{{ $death->immediate_cause }}</td><td style="font-family: monospace;">{{ $death->immediate_icd }}</td></tr>
            <tr><td>{{ __('(b) Underlying cause') }}</td><td>{{ $death->underlying_cause ?? '—' }}</td><td style="font-family: monospace;">{{ $death->underlying_icd }}</td></tr>
        </tbody>
    </table>
    @if ($death->other_conditions)
        <div class="box"><span class="label">{{ __('Part II — other significant conditions') }}</span><br>{{ $death->other_conditions }}</div>
    @endif
    <div class="muted" style="margin-top: 6px;">
        {{ __('Maternal death') }}: <strong>{{ $death->is_maternal ? __('Yes') : __('No') }}</strong>
        · {{ __('Post-mortem requested') }}: <strong>{{ $death->autopsy_requested ? __('Yes') : __('No') }}</strong>
    </div>

    @if ($death->released_at)
        <h2>{{ __('Release of body') }}</h2>
        <div class="grid">
            <div><div class="label">{{ __('Released to') }}</div><div class="value">{{ $death->released_to }}</div></div>
            <div><div class="label">{{ __('ID number') }}</div><div class="value">{{ $death->released_to_id_number }}</div></div>
            <div><div class="label">{{ __('Relationship') }}</div><div class="value">{{ $death->released_to_relationship }}</div></div>
            <div><div class="label">{{ __('Released') }}</div><div class="value">{{ $death->released_at->format('d M Y H:i') }}</div></div>
            <div><div class="label">{{ __('Burial permit') }}</div><div class="value">{{ $death->burial_permit_number ?? '—' }}</div></div>
        </div>
    @endif

    <div class="muted" style="margin-top: 10px;">{{ __('Certified by') }}: <strong>{{ $death->certifier?->name }}</strong>@if ($death->certifier?->registration_number) · {{ $death->certifier->regulator }} {{ $death->certifier->registration_number }}@endif</div>
</x-print-document>
