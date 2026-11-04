<?php

use App\Models\Pregnancy;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('ANC / PNC clinic')] class extends Component {
    #[Url]
    public string $filter = 'active';

    public string $search = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['maternity.view', 'maternity.manage']), 403);
    }

    #[Computed]
    public function pregnancies()
    {
        return Pregnancy::with(['patient', 'ancVisits' => fn ($q) => $q->reorder('visit_number', 'desc')])
            ->when($this->filter === 'active', fn ($q) => $q->where('status', 'active'))
            ->when($this->filter === 'high', fn ($q) => $q->where('status', 'active')->where('risk_level', 'high'))
            ->when($this->filter === 'due', fn ($q) => $q->where('status', 'active')->whereBetween('edd', [now()->subWeeks(2)->toDateString(), now()->addWeeks(4)->toDateString()]))
            ->when($this->filter === 'postnatal', fn ($q) => $q->where('status', 'delivered')->where('closed_at', '>=', now()->subWeeks(8)))
            ->when(mb_strlen($this->search) >= 2, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($w) => $w->where('anc_number', 'like', $term)->orWhereHas('patient', fn ($p) => $p->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('mrn', 'like', $term)->orWhere('phone', 'like', $term)));
            })
            ->orderByRaw("case risk_level when 'high' then 0 else 1 end")->orderBy('edd')
            ->limit(150)->get();
    }

    #[Computed]
    public function stats(): array
    {
        return [
            'active' => Pregnancy::where('status', 'active')->count(),
            'high' => Pregnancy::where('status', 'active')->where('risk_level', 'high')->count(),
            'due' => Pregnancy::where('status', 'active')->whereBetween('edd', [now()->subWeeks(2)->toDateString(), now()->addWeeks(4)->toDateString()])->count(),
            'overdue_visit' => Pregnancy::where('status', 'active')->whereHas('ancVisits', fn ($q) => $q->whereNotNull('next_visit_date')->where('next_visit_date', '<', now()->toDateString()))->count(),
        ];
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading level="1">{{ __('ANC / PNC clinic') }}</flux:heading>
        @can('maternity.manage')
            <flux:button variant="primary" icon="plus" :href="route('maternity.register')" wire:navigate>{{ __('Register pregnancy') }}</flux:button>
        @endcan
    </div>

    <div class="grid gap-3 sm:grid-cols-4">
        @foreach ([__('Active pregnancies') => $this->stats['active'], __('High risk') => $this->stats['high'], __('Due in ≤ 4 weeks') => $this->stats['due'], __('Missed ANC appointment') => $this->stats['overdue_visit']] as $label => $n)
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700"><div class="text-xs text-neutral-500">{{ $label }}</div><div class="text-2xl font-semibold">{{ $n }}</div></div>
        @endforeach
    </div>

    <div class="flex flex-wrap items-center gap-2">
        @foreach (['active' => __('Active'), 'high' => __('High risk'), 'due' => __('Due soon'), 'postnatal' => __('Postnatal (8 wk)'), 'all' => __('All')] as $k => $l)
            <flux:button size="sm" :variant="$filter === $k ? 'primary' : 'ghost'" wire:click="$set('filter', '{{ $k }}')">{{ $l }}</flux:button>
        @endforeach
        <flux:input wire:model.live.debounce.300ms="search" size="sm" icon="magnifying-glass" :placeholder="__('Name, ANC no., MRN, phone')" class="max-w-xs" />
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                <tr><th class="px-3 py-2">{{ __('Mother') }}</th><th class="px-3 py-2">{{ __('Obstetric') }}</th><th class="px-3 py-2">{{ __('Gestation / EDD') }}</th><th class="px-3 py-2">{{ __('ANC') }}</th><th class="px-3 py-2">{{ __('Risk') }}</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse ($this->pregnancies as $p)
                    @php($last = $p->ancVisits->first())
                    <tr wire:key="p-{{ $p->id }}">
                        <td class="px-3 py-2">{{ $p->patient->full_name }}<div class="font-mono text-xs text-neutral-500">{{ $p->anc_number }} · {{ $p->patient->mrn }}</div></td>
                        <td class="px-3 py-2 font-mono text-xs">{{ $p->obstetricFormula() }}</td>
                        <td class="px-3 py-2">
                            @if ($p->status === 'active')
                                {{ $p->gestationWeeksOn() ?? '—' }} {{ __('wk') }}
                            @else
                                <flux:badge size="sm">{{ ucfirst($p->outcome ?? $p->status) }}</flux:badge>
                            @endif
                            <div class="text-xs text-neutral-500">EDD {{ $p->edd?->format('d M Y') }}</div>
                        </td>
                        <td class="px-3 py-2 text-xs">{{ $p->ancVisits->count() }} {{ __('visit(s)') }}
                            @if ($last?->next_visit_date)<div class="{{ $last->next_visit_date->isPast() && $p->status === 'active' ? 'text-red-600' : 'text-neutral-500' }}">{{ __('next') }} {{ $last->next_visit_date->format('d M') }}</div>@endif
                        </td>
                        <td class="px-3 py-2"><flux:badge size="sm" :color="$p->isHighRisk() ? 'red' : 'lime'">{{ $p->isHighRisk() ? __('High') : __('Low') }}</flux:badge></td>
                        <td class="px-3 py-2 text-right"><flux:button size="xs" variant="ghost" :href="route('maternity.pregnancy', $p)" wire:navigate>{{ __('Open') }}</flux:button></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-8 text-center text-neutral-500">{{ __('No pregnancies.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
