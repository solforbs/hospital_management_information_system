<?php

use App\Models\Patient;
use App\Services\Patients\PatientMergeService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Merge duplicate patients')] class extends Component {
    #[Url]
    public ?int $keep = null;

    #[Url]
    public ?int $duplicate = null;

    public string $keepSearch = '';

    public string $dupSearch = '';

    public string $reason = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('patients.merge'), 403);
    }

    protected function search(string $term)
    {
        if (mb_strlen($term) < 2) {
            return collect();
        }
        $t = '%'.$term.'%';

        return Patient::where(fn ($q) => $q->where('first_name', 'like', $t)->orWhere('last_name', 'like', $t)->orWhere('mrn', 'like', $t)->orWhere('national_id_hash', \App\Models\Patient::identifierHash(trim(str_replace('%', '', $t))))->orWhere('phone', 'like', $t))->limit(8)->get();
    }

    #[Computed]
    public function keepMatches()
    {
        return $this->search($this->keepSearch);
    }

    #[Computed]
    public function dupMatches()
    {
        return $this->search($this->dupSearch);
    }

    #[Computed]
    public function records(): array
    {
        return [
            'keep' => $this->keep ? Patient::withCount(['encounters', 'invoices'])->find($this->keep) : null,
            'duplicate' => $this->duplicate ? Patient::withCount(['encounters', 'invoices'])->find($this->duplicate) : null,
        ];
    }

    public function swap(): void
    {
        [$this->keep, $this->duplicate] = [$this->duplicate, $this->keep];
        unset($this->records);
    }

    public function merge(PatientMergeService $service): void
    {
        abort_unless(Auth::user()->can('patients.merge'), 403);
        $this->validate(['reason' => ['required', 'string', 'min:5', 'max:255']]);
        ['keep' => $k, 'duplicate' => $d] = $this->records;
        abort_unless($k && $d, 422);

        $moved = $service->merge($k, $d, $this->reason);
        Flux::toast(variant: 'success', text: __('Merged :dup into :keep (:n records moved).', ['dup' => $d->mrn, 'keep' => $k->mrn, 'n' => array_sum($moved)]));
        $this->redirect(route('patients.show', $k), navigate: true);
    }
};
?>

@php
    ['keep' => $k, 'duplicate' => $d] = $this->records;
    $fields = ['mrn' => 'MRN', 'full_name' => 'Name', 'sex' => 'Sex', 'date_of_birth' => 'Date of birth', 'national_id' => 'ID number', 'cr_number' => 'CR number', 'sha_number' => 'SHA number', 'phone' => 'Phone', 'county' => 'County', 'next_of_kin_name' => 'Next of kin', 'encounters_count' => 'Visits', 'invoices_count' => 'Invoices'];
@endphp
<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading level="1">{{ __('Merge duplicate patients') }}</flux:heading>
        <flux:text class="mt-1">{{ __('All visits, results, prescriptions, invoices and other records move to the record you keep. Blank details on it are filled from the duplicate. This cannot be undone.') }}</flux:text>
    </div>

    <div class="grid gap-6 lg:grid-cols-2">
        @foreach (['keep' => [__('Keep this record'), 'keepSearch', 'keepMatches', $k], 'duplicate' => [__('Merge this duplicate into it'), 'dupSearch', 'dupMatches', $d]] as $side => [$title, $searchProp, $matchesProp, $rec])
            <div class="space-y-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:heading level="3">{{ $title }}</flux:heading>
                @if ($rec)
                    <div class="flex justify-end"><flux:button size="xs" variant="ghost" wire:click="$set('{{ $side }}', null)">{{ __('Change') }}</flux:button></div>
                    <dl class="divide-y divide-neutral-200 text-sm dark:divide-neutral-700">
                        @foreach ($fields as $f => $label)
                            @php
                                $val = $rec->{$f} instanceof \Carbon\CarbonInterface ? $rec->{$f}->format('d M Y') : $rec->{$f};
                                $other = $side === 'keep' ? $d : $k;
                                $otherVal = $other ? ($other->{$f} instanceof \Carbon\CarbonInterface ? $other->{$f}->format('d M Y') : $other->{$f}) : null;
                            @endphp
                            <div class="flex justify-between py-1.5 {{ $other && (string) $val !== (string) $otherVal && ! str_ends_with($f, '_count') && $f !== 'mrn' ? 'bg-amber-50 dark:bg-amber-950/40' : '' }}"><dt class="text-zinc-500">{{ __($label) }}</dt><dd class="font-medium">{{ $val ?? '—' }}</dd></div>
                        @endforeach
                    </dl>
                @else
                    <flux:input wire:model.live.debounce.300ms="{{ $searchProp }}" :placeholder="__('Name, MRN, ID or phone')" />
                    @foreach ($this->{$matchesProp} as $p)
                        <button type="button" wire:key="{{ $side }}-{{ $p->id }}" wire:click="$set('{{ $side }}', {{ $p->id }})" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-zinc-50 dark:hover:bg-zinc-800">{{ $p->full_name }} <span class="font-mono text-xs text-zinc-500">{{ $p->mrn }} · {{ $p->date_of_birth?->format('d M Y') }}</span></button>
                    @endforeach
                @endif
            </div>
        @endforeach
    </div>

    @if ($k && $d)
        <form wire:submit="merge" class="grid max-w-2xl gap-3 rounded-xl border border-red-300 p-5 dark:border-red-900">
            <div class="flex items-center gap-3 text-sm">
                <span>{{ __('Merge') }} <strong>{{ $d->mrn }}</strong> → <strong>{{ $k->mrn }}</strong></span>
                <flux:button size="xs" icon="arrows-right-left" wire:click="swap">{{ __('Swap') }}</flux:button>
            </div>
            <flux:input wire:model="reason" :label="__('Reason (audited)')" :placeholder="__('e.g. Same national ID; registered twice at reception')" />
            @error('duplicate')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
            <div><flux:button type="submit" variant="danger" wire:confirm="{{ __('Merge these records? This cannot be undone.') }}">{{ __('Merge records') }}</flux:button></div>
        </form>
    @endif
</div>
