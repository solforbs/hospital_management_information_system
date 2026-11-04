<?php

use App\Models\Surgery;
use App\Services\Theatre\TheatreService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Theatre case')] class extends Component {
    public Surgery $surgery;

    public array $checked = [];

    public bool $consent = false;

    public string $operative_notes = '';

    public string $complications = '';

    public string $cancel_reason = '';

    public function mount(Surgery $surgery): void
    {
        abort_unless(Auth::user()->canAny(['theatre.schedule', 'theatre.procedures.manage']), 403);
        $this->surgery = $surgery;
        $this->checked = $surgery->checklist ?? [];
        $this->consent = $surgery->consent_obtained;
        $this->operative_notes = (string) $surgery->operative_notes;
        $this->complications = (string) $surgery->complications;
    }

    public function saveChecklist(TheatreService $theatre): void
    {
        abort_unless(Auth::user()->can('theatre.procedures.manage'), 403);
        abort_unless($this->surgery->status === 'scheduled', 422);
        $this->surgery = $theatre->updateChecklist($this->surgery, $this->checked, $this->consent);
        Flux::toast(text: __('Checklist saved.'));
    }

    public function start(TheatreService $theatre): void
    {
        abort_unless(Auth::user()->can('theatre.procedures.manage'), 403);
        $theatre->updateChecklist($this->surgery, $this->checked, $this->consent);
        $this->surgery = $theatre->start($this->surgery->fresh());
        Flux::toast(variant: 'success', text: __('Case started.'));
    }

    public function complete(TheatreService $theatre): void
    {
        abort_unless(Auth::user()->can('theatre.records.sign'), 403);
        $this->validate(['operative_notes' => ['required', 'string', 'max:20000'], 'complications' => ['nullable', 'string', 'max:5000']]);
        $this->surgery = $theatre->complete($this->surgery, $this->operative_notes, $this->complications ?: null);
        Flux::toast(variant: 'success', text: __('Case completed and billed.'));
    }

    public function cancel(TheatreService $theatre): void
    {
        abort_unless(Auth::user()->can('theatre.schedule'), 403);
        $this->validate(['cancel_reason' => ['required', 'string', 'max:255']]);
        $this->surgery = $theatre->cancel($this->surgery, $this->cancel_reason);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading level="1">{{ $surgery->procedure_name }}</flux:heading>
            <flux:text><span class="font-mono">{{ $surgery->surgery_number }}</span> · {{ $surgery->scheduled_at->format('d M Y H:i') }} · {{ $surgery->room?->name ?? __('No room') }} · <flux:badge size="sm">{{ str_replace('_', ' ', $surgery->status) }}</flux:badge></flux:text>
        </div>
        <flux:button variant="ghost" :href="route('theatre.index', ['date' => $surgery->scheduled_at->toDateString()])" wire:navigate>{{ __('Back to schedule') }}</flux:button>
    </div>

    <x-patient-banner :patient="$surgery->patient" />

    <div class="grid gap-3 text-sm sm:grid-cols-4">
        <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-700"><div class="text-neutral-500">{{ __('Surgeon') }}</div>{{ $surgery->surgeon?->name ?? '—' }}</div>
        <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-700"><div class="text-neutral-500">{{ __('Anaesthetist') }}</div>{{ $surgery->anesthetist?->name ?? '—' }}</div>
        <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-700"><div class="text-neutral-500">{{ __('Anaesthesia') }}</div>{{ ucfirst($surgery->anesthesia_type ?? '—') }}</div>
        <div class="rounded-lg border border-neutral-200 p-3 dark:border-neutral-700"><div class="text-neutral-500">{{ __('Fee') }}</div><x-money :amount="$surgery->fee" /></div>
    </div>

    @if ($surgery->status === 'scheduled')
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:heading level="3" class="mb-3">{{ __('WHO surgical safety checklist') }}</flux:heading>
                <div class="space-y-2">
                    <flux:checkbox wire:model="consent" :label="__('Informed consent signed')" />
                    @foreach (\App\Services\Theatre\TheatreService::CHECKLIST as $key => $label)
                        <flux:checkbox wire:model="checked" value="{{ $key }}" :label="__($label)" />
                    @endforeach
                </div>
                @error('status') <flux:text class="mt-2 text-sm text-red-500">{{ $message }}</flux:text> @enderror
                @can('theatre.procedures.manage')
                    <div class="mt-4 flex gap-2">
                        <flux:button size="sm" wire:click="saveChecklist">{{ __('Save checklist') }}</flux:button>
                        <flux:button size="sm" variant="primary" wire:click="start">{{ __('Start case') }}</flux:button>
                    </div>
                @endcan
            </div>
            @can('theatre.schedule')
                <form wire:submit="cancel" class="grid content-start gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('Cancel / postpone') }}</flux:heading>
                    <flux:input wire:model="cancel_reason" :label="__('Reason')" />
                    <div><flux:button size="sm" variant="danger" type="submit">{{ __('Cancel case') }}</flux:button></div>
                </form>
            @endcan
        </div>
    @elseif ($surgery->status === 'in_progress')
        <form wire:submit="complete" class="grid max-w-3xl gap-3 rounded-xl border border-amber-300 p-4 dark:border-amber-800">
            <flux:text>{{ __('Started at') }} {{ $surgery->started_at->format('H:i') }} ({{ $surgery->started_at->diffForHumans() }})</flux:text>
            <flux:textarea wire:model="operative_notes" rows="8" :label="__('Operative notes')" />
            <flux:textarea wire:model="complications" rows="2" :label="__('Complications')" />
            @can('theatre.records.sign')
                <div><flux:button type="submit" variant="primary">{{ __('Complete & sign') }}</flux:button></div>
            @endcan
        </form>
    @else
        <div class="rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-700">
            @if ($surgery->status === 'completed')
                <div>{{ $surgery->started_at?->format('H:i') }} – {{ $surgery->ended_at?->format('H:i') }}</div>
                <div class="mt-2 font-medium">{{ __('Operative notes') }}</div>
                <div>{!! nl2br(e($surgery->operative_notes)) !!}</div>
                @if ($surgery->complications)<div class="mt-2 font-medium">{{ __('Complications') }}</div><div>{{ $surgery->complications }}</div>@endif
            @else
                {{ __('Cancelled') }}: {{ $surgery->cancellation_reason }}
            @endif
        </div>
    @endif
</div>
