<?php

use App\Models\Referral;
use App\Services\Tenancy\TenantContext;
use App\Support\FacilityLevel;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Referrals')] class extends Component {
    #[Url]
    public string $direction = 'outgoing';

    #[Url]
    public string $status = 'open';

    public string $search = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['referrals.view', 'referrals.manage']), 403);
    }

    #[Computed]
    public function referrals()
    {
        $open = $this->direction === 'outgoing' ? ['draft', 'sent', 'accepted'] : ['sent', 'accepted', 'arrived'];

        return Referral::with(['patient', 'creator'])
            ->where('direction', $this->direction)
            ->when($this->status === 'open', fn ($q) => $q->whereIn('status', $open))
            ->when($this->status === 'closed', fn ($q) => $q->whereNotIn('status', $open))
            ->when(mb_strlen($this->search) >= 2, function ($q) {
                $term = '%'.$this->search.'%';
                $q->where(fn ($w) => $w->where('referral_number', 'like', $term)->orWhere('other_facility_name', 'like', $term)
                    ->orWhereHas('patient', fn ($p) => $p->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('mrn', 'like', $term)));
            })
            ->orderByRaw("case urgency when 'emergency' then 0 when 'urgent' then 1 else 2 end")
            ->latest('id')->limit(100)->get();
    }

    #[Computed]
    public function counts(): array
    {
        return [
            'outgoing' => Referral::where('direction', 'outgoing')->whereIn('status', ['draft', 'sent', 'accepted'])->count(),
            'incoming' => Referral::where('direction', 'incoming')->whereIn('status', ['sent', 'accepted', 'arrived'])->count(),
        ];
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6" data-live="referrals">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading level="1">{{ __('Referrals') }}</flux:heading>
            <flux:text>{{ app(TenantContext::class)->facility()?->name }} · {{ FacilityLevel::label(app(TenantContext::class)->facility()?->level) }}</flux:text>
        </div>
        @can('referrals.manage')
            <flux:button variant="primary" icon="arrow-down-tray" :href="route('referrals.create', ['direction' => 'incoming'])" wire:navigate>{{ __('Register incoming referral') }}</flux:button>
        @endcan
    </div>

    <div class="flex flex-wrap items-center gap-3">
        <div class="flex gap-1 border-b border-neutral-200 dark:border-neutral-700">
            @foreach (['outgoing' => __('Outgoing'), 'incoming' => __('Incoming')] as $k => $l)
                <button type="button" wire:click="$set('direction', '{{ $k }}')" class="-mb-px border-b-2 px-3 py-2 text-sm {{ $direction === $k ? 'border-indigo-500 font-medium' : 'border-transparent text-neutral-500' }}">{{ $l }} <flux:badge size="sm">{{ $this->counts[$k] }}</flux:badge></button>
            @endforeach
        </div>
        <flux:select wire:model.live="status" class="max-w-40" size="sm">
            <flux:select.option value="open">{{ __('Open') }}</flux:select.option>
            <flux:select.option value="closed">{{ __('Closed') }}</flux:select.option>
            <flux:select.option value="all">{{ __('All') }}</flux:select.option>
        </flux:select>
        <flux:input wire:model.live.debounce.300ms="search" size="sm" icon="magnifying-glass" :placeholder="__('Patient, facility or number')" class="max-w-xs" />
    </div>

    @if ($direction === 'outgoing')
        <flux:text class="text-sm">{{ __('Outgoing referrals are started from a patient\'s visit: "Refer" on the consultation page or inpatient chart.') }}</flux:text>
    @endif

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                <tr><th class="px-3 py-2">{{ __('Referral') }}</th><th class="px-3 py-2">{{ __('Patient') }}</th><th class="px-3 py-2">{{ $direction === 'outgoing' ? __('To') : __('From') }}</th><th class="px-3 py-2">{{ __('Urgency') }}</th><th class="px-3 py-2">{{ __('Status') }}</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse ($this->referrals as $r)
                    <tr wire:key="r-{{ $r->id }}">
                        <td class="px-3 py-2 font-mono text-xs">{{ $r->referral_number }}<div class="font-sans text-neutral-500">{{ $r->created_at->format('d M H:i') }}</div></td>
                        <td class="px-3 py-2">{{ $r->patient->full_name }}<div class="font-mono text-xs text-neutral-500">{{ $r->patient->mrn }}</div></td>
                        <td class="px-3 py-2">{{ $r->other_facility_name }}<div class="text-xs text-neutral-500">{{ FacilityLevel::short($r->other_facility_level) }} @if ($r->department) · {{ $r->department }} @endif</div></td>
                        <td class="px-3 py-2"><flux:badge size="sm" :color="match($r->urgency) { 'emergency' => 'red', 'urgent' => 'amber', default => 'zinc' }">{{ ucfirst($r->urgency) }}</flux:badge></td>
                        <td class="px-3 py-2"><flux:badge size="sm" :color="\App\Models\Referral::statusColor($r->status)">{{ $direction === 'incoming' && $r->status === 'sent' ? __('Expected') : ucfirst($r->status) }}</flux:badge></td>
                        <td class="px-3 py-2 text-right"><flux:button size="xs" variant="ghost" :href="route('referrals.show', $r)" wire:navigate>{{ __('Open') }}</flux:button></td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-8 text-center text-neutral-500">{{ __('No referrals.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
