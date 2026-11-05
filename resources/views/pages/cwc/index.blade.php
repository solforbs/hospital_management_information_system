<?php

use App\Models\HeiEnrolment;
use App\Models\Immunization;
use App\Models\Patient;
use App\Support\KepiSchedule;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Child welfare clinic')] class extends Component {
    public string $search = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('cwc.manage'), 403);
    }

    protected function regional(): bool
    {
        return (bool) data_get(app(\App\Services\Tenancy\TenantContext::class)->tenant()?->settings, 'cwc.regional_vaccines', false);
    }

    #[Computed]
    public function children()
    {
        $q = Patient::where('date_of_birth', '>=', now()->subYears(5)->toDateString());

        if (mb_strlen($this->search) >= 2) {
            $term = '%'.$this->search.'%';
            $q->where(fn ($w) => $w->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('middle_name', 'like', $term)->orWhere('mrn', 'like', $term)->orWhere('phone', 'like', $term));
        }

        return $q->orderByDesc('date_of_birth')->limit(200)->get();
    }

    /** Children with vaccines due this week or overdue (defaulter tracing). */
    #[Computed]
    public function dueList()
    {
        $given = Immunization::whereIn('patient_id', $this->children->pluck('id'))->get()->groupBy('patient_id');

        return $this->children->map(function ($child) use ($given) {
            $map = ($given[$child->id] ?? collect())->mapWithKeys(fn ($i) => [$i->vaccine => $i->given_on])->all();
            $schedule = KepiSchedule::forChild($child->date_of_birth, $map, $this->regional());
            $overdue = collect($schedule)->where('status', 'overdue');
            $due = collect($schedule)->where('status', 'due');

            return ['child' => $child, 'overdue' => $overdue, 'due' => $due];
        })->filter(fn ($r) => $r['overdue']->isNotEmpty() || $r['due']->isNotEmpty())->sortByDesc(fn ($r) => $r['overdue']->count())->values();
    }

    #[Computed]
    public function heiActive()
    {
        return HeiEnrolment::with(['patient', 'tests'])->where('status', 'active')->orderBy('enrolled_on')->get();
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:heading level="1">{{ __('Child welfare clinic') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-3">
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700"><div class="text-xs text-zinc-500">{{ __('Children under 5') }}</div><div class="text-2xl font-semibold">{{ $this->children->count() }}</div></div>
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700"><div class="text-xs text-zinc-500">{{ __('Due or defaulted vaccines') }}</div><div class="text-2xl font-semibold">{{ $this->dueList->count() }}</div></div>
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700"><div class="text-xs text-zinc-500">{{ __('HIV-exposed infants in follow-up') }}</div><div class="text-2xl font-semibold">{{ $this->heiActive->count() }}</div></div>
    </div>

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Find a child: name, MRN or caregiver phone')" class="max-w-md" />

    <div class="grid gap-6 xl:grid-cols-2">
        <div>
            <flux:heading level="3" class="mb-2">{{ __('Vaccines due / defaulters') }}</flux:heading>
            <div class="space-y-2">
                @forelse ($this->dueList->take(40) as $row)
                    <a wire:key="d-{{ $row['child']->id }}" href="{{ route('cwc.child', $row['child']) }}" wire:navigate class="block rounded-xl border border-neutral-200 p-3 text-sm hover:border-indigo-400 dark:border-neutral-700">
                        <div class="flex justify-between"><span class="font-medium">{{ $row['child']->full_name }}</span><span class="text-xs text-zinc-500">{{ $row['child']->date_of_birth->diffForHumans(null, true) }} · {{ $row['child']->phone }}</span></div>
                        <div class="mt-1 flex flex-wrap gap-1">
                            @foreach ($row['overdue'] as $v)<flux:badge size="sm" color="red">{{ $v['label'] }}</flux:badge>@endforeach
                            @foreach ($row['due'] as $v)<flux:badge size="sm" color="amber">{{ $v['label'] }}</flux:badge>@endforeach
                        </div>
                    </a>
                @empty
                    <flux:text>{{ __('No vaccines due.') }}</flux:text>
                @endforelse
            </div>
        </div>
        <div class="space-y-6">
            <div>
                <flux:heading level="3" class="mb-2">{{ __('HEI follow-up') }}</flux:heading>
                <div class="space-y-2">
                    @forelse ($this->heiActive as $h)
                        <a wire:key="h-{{ $h->id }}" href="{{ route('cwc.child', $h->patient) }}" wire:navigate class="block rounded-xl border border-neutral-200 p-3 text-sm hover:border-indigo-400 dark:border-neutral-700">
                            <span class="font-mono text-xs">{{ $h->hei_number }}</span> · {{ $h->patient->full_name }}
                            <div class="text-xs text-zinc-500">{{ __('Enrolled') }} {{ $h->enrolled_on->format('d M Y') }} · {{ $h->tests->count() }} {{ __('test(s)') }} · {{ __('last') }}: {{ $h->tests->last()?->result ?? '—' }}</div>
                        </a>
                    @empty
                        <flux:text>{{ __('No infants in HEI follow-up.') }}</flux:text>
                    @endforelse
                </div>
            </div>
            <div>
                <flux:heading level="3" class="mb-2">{{ __('Children') }}</flux:heading>
                <div class="divide-y divide-neutral-200 rounded-xl border border-neutral-200 dark:divide-neutral-700 dark:border-neutral-700">
                    @forelse ($this->children->take(30) as $c)
                        <a wire:key="c-{{ $c->id }}" href="{{ route('cwc.child', $c) }}" wire:navigate class="flex justify-between px-3 py-2 text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">
                            <span>{{ $c->full_name }} <span class="font-mono text-xs text-zinc-500">{{ $c->mrn }}</span></span>
                            <span class="text-xs text-zinc-500">{{ $c->date_of_birth->format('d M Y') }}</span>
                        </a>
                    @empty
                        <div class="px-3 py-6 text-center text-sm text-zinc-500">{{ __('No children under 5 found.') }}</div>
                    @endforelse
                </div>
            </div>
        </div>
    </div>
</div>
