<?php

use App\Models\Admission;
use App\Models\Bed;
use App\Services\Inpatient\AdmissionService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Admissions')] class extends Component {
    public array $bedFor = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['wards.beds.allocate', 'wards.admissions.create']), 403);
    }

    #[Computed]
    public function requests()
    {
        return Admission::with(['patient', 'ward', 'doctor', 'encounter'])->where('status', 'requested')->orderBy('requested_at')->get();
    }

    #[Computed]
    public function current()
    {
        return Admission::with(['patient', 'ward', 'bed', 'doctor'])->where('status', 'admitted')->orderBy('admitted_at')->get();
    }

    #[Computed]
    public function recentDischarges()
    {
        return Admission::with(['patient', 'ward'])->where('status', 'discharged')->where('discharged_at', '>=', now()->subDays(7))->latest('discharged_at')->limit(20)->get();
    }

    #[Computed]
    public function availableBeds()
    {
        return Bed::with('ward')->where('status', 'available')->whereHas('ward', fn ($q) => $q->where('active', true))->orderBy('ward_id')->orderBy('bed_number')->get();
    }

    public function allocate(int $id, AdmissionService $admissions): void
    {
        abort_unless(Auth::user()->can('wards.beds.allocate'), 403);
        $this->validate(["bedFor.$id" => ['required', 'integer']], [], ["bedFor.$id" => __('bed')]);

        try {
            $admission = $admissions->admit(Admission::findOrFail($id), (int) $this->bedFor[$id]);
        } catch (\Illuminate\Validation\ValidationException $e) {
            // Show the service's error (e.g. bed just taken) on this request's row.
            $this->addError("bedFor.$id", collect($e->errors())->flatten()->first());
            unset($this->availableBeds);

            return;
        }

        Flux::toast(variant: 'success', text: __(':name admitted to bed :bed.', ['name' => $admission->patient->full_name, 'bed' => $admission->bed->bed_number]));
        unset($this->bedFor[$id], $this->requests, $this->current, $this->availableBeds);
    }

    public function cancel(int $id, AdmissionService $admissions): void
    {
        abort_unless(Auth::user()->can('wards.admissions.create'), 403);
        $admissions->cancel(Admission::findOrFail($id), __('Cancelled at admissions desk'));
        unset($this->requests);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6" wire:poll.30s>
    <flux:heading level="1">{{ __('Admissions desk') }}</flux:heading>

    <div>
        <flux:heading level="3" class="mb-2">{{ __('Pending admission requests') }} ({{ $this->requests->count() }})</flux:heading>
        <div class="space-y-2">
            @forelse ($this->requests as $a)
                <div wire:key="req-{{ $a->id }}" class="flex flex-wrap items-center justify-between gap-3 rounded-xl border border-amber-300 p-4 text-sm dark:border-amber-800">
                    <div>
                        <div class="font-medium">{{ $a->patient->full_name }} <span class="font-mono text-xs text-neutral-500">{{ $a->patient->mrn }}</span> · {{ ucfirst($a->patient->sex) }} {{ $a->patient->age }}</div>
                        <div class="text-neutral-500">{{ $a->reason }}</div>
                        <div class="text-xs text-neutral-500">{{ $a->admission_number }} · {{ ucfirst($a->type) }} · {{ __('requested by') }} {{ $a->doctor?->name }} {{ $a->requested_at->diffForHumans() }} @if ($a->ward) · {{ __('preferred') }}: {{ $a->ward->name }} @endif · {{ ucfirst($a->encounter->payer_type) }}</div>
                    </div>
                    @can('wards.beds.allocate')
                        <div class="flex items-center gap-2">
                            <flux:select size="sm" wire:model="bedFor.{{ $a->id }}" class="w-64">
                                <flux:select.option value="">{{ __('Choose bed') }}</flux:select.option>
                                @foreach ($this->availableBeds->sortByDesc(fn ($b) => $b->ward_id === $a->ward_id) as $b)
                                    <flux:select.option value="{{ $b->id }}">{{ $b->ward->name }} — {{ $b->bed_number }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:button size="sm" variant="primary" wire:click="allocate({{ $a->id }})">{{ __('Admit') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="cancel({{ $a->id }})" wire:confirm="{{ __('Cancel this admission request?') }}">{{ __('Cancel') }}</flux:button>
                        </div>
                    @endcan
                    @error("bedFor.{$a->id}") <flux:text class="w-full text-xs text-red-500">{{ $message }}</flux:text> @enderror
                </div>
            @empty
                <flux:text>{{ __('No pending requests.') }}</flux:text>
            @endforelse
        </div>
    </div>

    <div>
        <flux:heading level="3" class="mb-2">{{ __('Current inpatients') }} ({{ $this->current->count() }})</flux:heading>
        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-3 py-2">{{ __('Patient') }}</th><th class="px-3 py-2">{{ __('Ward / bed') }}</th><th class="px-3 py-2">{{ __('Admitted') }}</th><th class="px-3 py-2">{{ __('Doctor') }}</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->current as $a)
                        <tr wire:key="cur-{{ $a->id }}">
                            <td class="px-3 py-2">{{ $a->patient->full_name }} <div class="font-mono text-xs text-neutral-500">{{ $a->admission_number }}</div></td>
                            <td class="px-3 py-2">{{ $a->ward?->name }} · {{ $a->bed?->bed_number }}</td>
                            <td class="px-3 py-2">{{ $a->admitted_at->format('d M H:i') }} ({{ __('day') }} {{ $a->lengthOfStayDays() }})</td>
                            <td class="px-3 py-2">{{ $a->doctor?->name }}</td>
                            <td class="px-3 py-2 text-right"><flux:button size="xs" :href="route('wards.admission', $a)" wire:navigate>{{ __('Chart') }}</flux:button></td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-neutral-500">{{ __('No inpatients.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </div>

    <div>
        <flux:heading level="3" class="mb-2">{{ __('Discharged in the last 7 days') }}</flux:heading>
        <div class="space-y-1 text-sm">
            @forelse ($this->recentDischarges as $a)
                <a wire:key="dis-{{ $a->id }}" href="{{ route('wards.admission', $a) }}" wire:navigate class="block hover:underline">{{ $a->discharged_at->format('d M H:i') }} — {{ $a->patient->full_name }} ({{ $a->ward?->name }}, {{ str_replace('_', ' ', $a->discharge_type) }})</a>
            @empty
                <flux:text>{{ __('None.') }}</flux:text>
            @endforelse
        </div>
    </div>
</div>
