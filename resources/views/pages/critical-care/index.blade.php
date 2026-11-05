<?php

use App\Models\Admission;
use App\Models\Ward;
use App\Support\FacilityLevel;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Critical care')] class extends Component {
    public function mount(): void
    {
        abort_unless(Auth::user()->can('critical_care.chart'), 403);
    }

    #[Computed]
    public function units()
    {
        return Ward::whereIn('type', FacilityLevel::CRITICAL_CARE_WARD_TYPES)->where('active', true)
            ->withCount(['beds', 'beds as occupied_count' => fn ($q) => $q->where('status', 'occupied')])
            ->orderByRaw("case type when 'icu' then 0 when 'hdu' then 1 else 2 end")->get();
    }

    #[Computed]
    public function patients()
    {
        return Admission::with(['patient', 'ward', 'bed', 'doctor', 'criticalCareObservations' => fn ($q) => $q->latest('recorded_at')->limit(1)])
            ->where('status', 'admitted')
            ->whereHas('ward', fn ($q) => $q->whereIn('type', FacilityLevel::CRITICAL_CARE_WARD_TYPES))
            ->get()
            ->sortBy(fn ($a) => [$a->ward?->name, $a->bed?->bed_number]);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6" data-live="critical,wards">
    <flux:heading level="1">{{ __('Critical care') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-3">
        @forelse ($this->units as $u)
            <div wire:key="u-{{ $u->id }}" class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <div class="text-sm font-medium">{{ $u->name }} <span class="text-xs uppercase text-neutral-500">{{ $u->type }}</span></div>
                <div class="mt-1 text-2xl font-semibold">{{ $u->occupied_count }} / {{ $u->beds_count }}</div>
                <div class="text-xs text-neutral-500">{{ __('beds occupied') }}</div>
            </div>
        @empty
            <flux:text>{{ __('No ICU, HDU or NICU wards are set up. Add one under Wards & beds (type ICU, HDU or NICU).') }}</flux:text>
        @endforelse
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                <tr><th class="px-3 py-2">{{ __('Bed') }}</th><th class="px-3 py-2">{{ __('Patient') }}</th><th class="px-3 py-2">{{ __('Day') }}</th><th class="px-3 py-2">{{ __('Last observation') }}</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse ($this->patients as $a)
                    @php($o = $a->criticalCareObservations->first())
                    @php($alerts = $o?->alerts() ?? [])
                    @php($overdue = ! $o || $o->recorded_at->lt(now()->subHours(2)))
                    <tr wire:key="a-{{ $a->id }}" class="{{ $alerts ? 'bg-red-50 dark:bg-red-950/40' : '' }}">
                        <td class="px-3 py-2">{{ $a->ward?->name }}<div class="font-mono text-xs text-neutral-500">{{ $a->bed?->bed_number }}</div></td>
                        <td class="px-3 py-2">{{ $a->patient->full_name }}<div class="text-xs text-neutral-500">{{ $a->doctor?->name ?? '—' }}</div></td>
                        <td class="px-3 py-2">{{ $a->lengthOfStayDays() }}</td>
                        <td class="px-3 py-2 text-xs">
                            @if ($o)
                                HR {{ $o->heart_rate ?? '—' }} · BP {{ $o->systolic ?? '—' }}/{{ $o->diastolic ?? '—' }} (MAP {{ $o->map ?? '—' }}) · SpO₂ {{ $o->spo2 ?? '—' }}% · RR {{ $o->resp_rate ?? '—' }} · GCS {{ $o->gcs ?? '—' }}
                                <div class="{{ $overdue ? 'text-amber-600' : 'text-neutral-500' }}">{{ $o->recorded_at->diffForHumans() }} @if ($o->ventilation) · {{ str_replace('_', ' ', $o->ventilation) }} @endif</div>
                                @if ($alerts)<div class="font-medium text-red-600">{{ __('Abnormal') }}: {{ implode(', ', array_map(fn ($x) => str_replace('_', ' ', $x), $alerts)) }}</div>@endif
                            @else
                                <span class="text-amber-600">{{ __('No observations yet') }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right"><flux:button size="xs" variant="primary" :href="route('critical-care.chart', $a)" wire:navigate>{{ __('Chart') }}</flux:button></td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-8 text-center text-neutral-500">{{ __('No patients in critical care.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
