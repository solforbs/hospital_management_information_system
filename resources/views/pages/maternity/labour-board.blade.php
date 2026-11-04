<?php

use App\Models\Labour;
use App\Services\Maternity\MaternityService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Labour ward')] class extends Component {
    public function mount(): void
    {
        abort_unless(Auth::user()->can('maternity.deliver'), 403);
    }

    #[Computed]
    public function active()
    {
        $service = app(MaternityService::class);

        return Labour::with(['pregnancy.patient', 'entries'])->where('status', 'in_labour')->orderBy('admitted_at')->get()
            ->map(fn ($l) => ['labour' => $l, 'alerts' => $service->partographAlerts($l), 'last' => $l->entries->last()]);
    }

    #[Computed]
    public function recent()
    {
        return Labour::with(['pregnancy.patient', 'newborns'])->where('status', '!=', 'in_labour')->where('updated_at', '>=', now()->subDays(3))->latest('delivered_at')->limit(20)->get();
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6" wire:poll.30s>
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Labour ward') }}</flux:heading>
        <flux:button size="sm" :href="route('maternity.index')" wire:navigate>{{ __('ANC / PNC clinic') }}</flux:button>
    </div>
    <flux:text class="text-sm">{{ __('Admit a mother from her pregnancy record (Labour & delivery tab).') }}</flux:text>

    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
        @forelse ($this->active as $row)
            @php($l = $row['labour'])
            @php($a = $row['alerts'])
            @php($last = $row['last'])
            <a wire:key="al-{{ $l->id }}" href="{{ route('labour.show', $l) }}" wire:navigate class="block rounded-xl border p-4 text-sm {{ $a['action'] || $a['critical'] ? 'border-red-400 bg-red-50 dark:bg-red-950/40' : ($a['alert'] ? 'border-amber-400 bg-amber-50 dark:bg-amber-950/40' : 'border-neutral-200 dark:border-neutral-700') }}">
                <div class="flex items-center justify-between">
                    <span class="font-medium">{{ $l->pregnancy->patient->full_name }}</span>
                    @if ($l->pregnancy->isHighRisk())<flux:badge size="sm" color="red">{{ __('High risk') }}</flux:badge>@endif
                </div>
                <div class="text-xs text-neutral-500">{{ $l->pregnancy->obstetricFormula() }} · {{ $l->gestation_weeks ?? '—' }} {{ __('wk') }} · {{ __('in') }} {{ $l->admitted_at->diffForHumans(short: true, syntax: \Carbon\CarbonInterface::DIFF_ABSOLUTE) }}</div>
                <div class="mt-2 text-xs">
                    @if ($last)
                        {{ __('Cervix') }} {{ $l->entries->whereNotNull('cervix_cm')->last()?->cervix_cm ?? '—' }} cm · FHR {{ $last->fetal_heart_rate ?? '—' }} · {{ $last->recorded_at->diffForHumans() }}
                    @else
                        <span class="text-amber-600">{{ __('No partograph entries yet') }}</span>
                    @endif
                </div>
                @if ($a['action'])<div class="mt-1 text-xs font-semibold text-red-600">{{ __('ACTION LINE crossed') }}</div>@elseif ($a['alert'])<div class="mt-1 text-xs font-semibold text-amber-600">{{ __('Alert line crossed') }}</div>@endif
                @foreach ($a['critical'] as $c)<div class="text-xs text-red-600">{{ $c }}</div>@endforeach
            </a>
        @empty
            <flux:text>{{ __('No mothers in labour.') }}</flux:text>
        @endforelse
    </div>

    <div>
        <flux:heading level="3" class="mb-2">{{ __('Last 3 days') }}</flux:heading>
        <div class="space-y-1">
            @foreach ($this->recent as $l)
                <a wire:key="r-{{ $l->id }}" href="{{ route('labour.show', $l) }}" wire:navigate class="block rounded-lg border border-neutral-200 px-3 py-2 text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                    {{ $l->pregnancy->patient->full_name }} · <flux:badge size="sm">{{ $l->status }}</flux:badge>
                    @if ($l->delivered_at) · {{ \App\Models\Labour::MODES[$l->delivery_mode] ?? '' }} {{ $l->delivered_at->format('d M H:i') }} · {{ $l->newborns->count() }} {{ __('baby/babies') }} @endif
                </a>
            @endforeach
        </div>
    </div>
</div>
