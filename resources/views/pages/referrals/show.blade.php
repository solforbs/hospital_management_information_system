<?php

use App\Models\Department;
use App\Models\Referral;
use App\Services\Clinical\ReferralService;
use App\Services\Tenancy\TenantContext;
use App\Support\FacilityLevel;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Referral')] class extends Component {
    public Referral $referral;

    public string $response = 'accepted';

    public string $feedback = '';

    public string $cancel_reason = '';

    public string $payer_type = 'cash';

    public string $department_id = '';

    public function mount(Referral $referral): void
    {
        abort_unless(Auth::user()->canAny(['referrals.view', 'referrals.manage']), 403);
        $this->referral = $referral->load(['patient', 'encounter', 'receivedEncounter', 'facility', 'creator']);
    }

    public function departments()
    {
        return Department::orderBy('name')->get();
    }

    protected function act(callable $fn, string $message): void
    {
        abort_unless(Auth::user()->can('referrals.manage'), 403);
        $fn(app(ReferralService::class));
        $this->referral->refresh();
        $this->reset(['feedback', 'cancel_reason']);
        Flux::toast(variant: 'success', text: $message);
    }

    public function send(): void
    {
        $this->act(fn ($s) => $s->send($this->referral), __('Referral sent.'));
    }

    public function respond(): void
    {
        $this->validate(['response' => ['required', 'in:accepted,rejected,completed'], 'feedback' => ['nullable', 'string', 'max:5000']]);
        $this->act(fn ($s) => $s->recordResponse($this->referral, $this->response, $this->feedback ?: null), __('Response recorded.'));
    }

    public function receive(): void
    {
        $this->validate(['payer_type' => ['required', 'in:cash,insurance,corporate,waiver'], 'department_id' => ['nullable', 'integer']]);
        $this->act(fn ($s) => $s->receive($this->referral, [
            'payer_type' => $this->payer_type,
            'department_id' => $this->department_id !== '' ? Department::findOrFail((int) $this->department_id)->id : null,
        ]), __('Patient received and checked in.'));
    }

    public function backRefer(): void
    {
        $this->validate(['feedback' => ['required', 'string', 'max:5000']]);
        $this->act(fn ($s) => $s->backRefer($this->referral, $this->feedback), __('Back-referral recorded.'));
    }

    public function cancel(): void
    {
        $this->validate(['cancel_reason' => ['required', 'string', 'max:255']]);
        $this->act(fn ($s) => $s->cancel($this->referral, $this->cancel_reason), __('Referral cancelled.'));
    }
};
?>

@php
    $r = $referral;
    $here = $r->facility ?? app(TenantContext::class)->facility();
    $tenant = app(TenantContext::class)->tenant();
@endphp

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-center justify-between gap-3 print:hidden">
        <div class="flex items-center gap-3">
            <flux:heading level="1">{{ $r->isOutgoing() ? __('Outgoing referral') : __('Incoming referral') }}</flux:heading>
            <flux:badge :color="Referral::statusColor($r->status)">{{ ! $r->isOutgoing() && $r->status === 'sent' ? __('Expected') : ucfirst($r->status) }}</flux:badge>
        </div>
        <div class="flex gap-2">
            <flux:button size="sm" icon="printer" onclick="window.print()">{{ $r->kind === 'back_referral' ? __('Print back-referral') : __('Print referral letter') }}</flux:button>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('referrals.index', ['direction' => $r->direction])" wire:navigate>{{ __('Referrals') }}</flux:button>
        </div>
    </div>

    @error('status')<flux:callout variant="danger" icon="x-circle" class="print:hidden"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>@enderror

    <div class="grid gap-6 lg:grid-cols-3">
        {{-- Referral letter (print layout) --}}
        <article class="space-y-4 rounded-xl border border-neutral-200 p-6 text-sm lg:col-span-2 dark:border-neutral-700 print:border-0 print:p-0">
            <header>
                <x-letterhead :tenant="$tenant" :facility="$here" :title="$r->kind === 'back_referral' ? __('Back-referral') : __('Referral letter')" :number="$r->referral_number" :date="$r->sent_at ?? $r->created_at" />
                <div class="mt-1 text-xs text-neutral-500">{{ FacilityLevel::label($here?->level) }}</div>
            </header>

            <div class="grid gap-3 sm:grid-cols-2">
                <div>
                    <div class="text-xs uppercase text-neutral-500">{{ $r->isOutgoing() ? __('To') : __('From') }}</div>
                    <div class="font-medium">{{ $r->other_facility_name }}</div>
                    <div class="text-xs text-neutral-500">{{ FacilityLevel::label($r->other_facility_level) }} @if ($r->other_facility_code) · {{ $r->other_facility_code }} @endif</div>
                    @if ($r->department)<div class="text-xs">{{ __('Service') }}: {{ $r->department }}</div>@endif
                </div>
                <div>
                    <div class="text-xs uppercase text-neutral-500">{{ __('Patient') }}</div>
                    <div class="font-medium">{{ $r->patient->full_name }}</div>
                    <div class="text-xs text-neutral-500">{{ $r->patient->mrn }} · {{ ucfirst($r->patient->sex) }} · {{ $r->patient->date_of_birth?->format('d M Y') ?? '—' }}</div>
                    <div class="text-xs text-neutral-500">{{ __('ID') }} {{ $r->patient->national_id ?? '—' }} @if ($r->patient->cr_number) · CR {{ $r->patient->cr_number }} @endif @if ($r->patient->sha_number) · SHA {{ $r->patient->sha_number }} @endif</div>
                </div>
            </div>

            <div class="flex flex-wrap gap-4 text-xs">
                <span>{{ __('Urgency') }}: <strong>{{ ucfirst($r->urgency) }}</strong></span>
                @if ($r->transport)<span>{{ __('Transport') }}: <strong>{{ ucfirst($r->transport) }}</strong></span>@endif
                @if ($r->encounter)<span>{{ __('Visit') }}: <span class="font-mono">{{ $r->encounter->encounter_number }}</span></span>@endif
                @if ($r->receivedEncounter)<span>{{ __('Received as visit') }}: <span class="font-mono">{{ $r->receivedEncounter->encounter_number }}</span></span>@endif
            </div>

            @foreach ([__('Reason for referral') => $r->reason, __('Clinical summary') => $r->clinical_summary, __('Investigations') => $r->investigations, __('Treatment given') => $r->treatment_given, ($r->isOutgoing() ? __('Feedback from receiving facility') : __('Back-referral / outcome')) => $r->feedback] as $label => $text)
                @if ($text)
                    <section>
                        <div class="text-xs uppercase text-neutral-500">{{ $label }}</div>
                        <div class="whitespace-pre-line">{{ $text }}</div>
                    </section>
                @endif
            @endforeach

            @if ($r->status_reason)<div class="text-xs text-red-600">{{ __('Cancelled') }}: {{ $r->status_reason }}</div>@endif

            <footer class="border-t border-neutral-200 pt-3 text-xs dark:border-neutral-700">
                {{ __('Referring clinician') }}: <strong>{{ $r->referring_clinician ?? $r->creator?->name ?? '—' }}</strong>
                <span class="ml-6">{{ __('Signature') }}: ____________________</span>
                <x-signatures :tenant="$tenant" document="referral" />
                <x-document-footer :tenant="$tenant" />
            </footer>
        </article>

        {{-- Actions --}}
        <div class="space-y-4 print:hidden">
            @can('referrals.manage')
                @if ($r->isOutgoing())
                    @if ($r->status === 'draft')
                        <div class="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                            <flux:text class="text-sm">{{ __('Sending moves an outpatient visit to billing. For an admitted patient, discharge them from the ward with type "Referred" after sending.') }}</flux:text>
                            <flux:button variant="primary" wire:click="send">{{ __('Send referral') }}</flux:button>
                        </div>
                    @elseif (in_array($r->status, ['sent', 'accepted', 'arrived']))
                        <form wire:submit="respond" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                            <flux:heading level="3">{{ __('Response from receiving facility') }}</flux:heading>
                            <flux:select wire:model="response" :label="__('Outcome')">
                                @if ($r->status === 'sent')
                                    <flux:select.option value="accepted">{{ __('Accepted') }}</flux:select.option>
                                    <flux:select.option value="rejected">{{ __('Declined') }}</flux:select.option>
                                @endif
                                <flux:select.option value="completed">{{ __('Completed (feedback received)') }}</flux:select.option>
                            </flux:select>
                            <flux:textarea wire:model="feedback" :label="__('Feedback / back-referral notes')" rows="3" />
                            @error('feedback')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                            <flux:button type="submit" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                        </form>
                    @endif
                @else
                    @if (in_array($r->status, ['sent', 'accepted']))
                        <form wire:submit="receive" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                            <flux:heading level="3">{{ __('Patient has arrived') }}</flux:heading>
                            <flux:select wire:model="payer_type" :label="__('Payer')">
                                <flux:select.option value="cash">{{ __('Cash') }}</flux:select.option>
                                <flux:select.option value="insurance">{{ __('Insurance / SHA') }}</flux:select.option>
                                <flux:select.option value="corporate">{{ __('Corporate') }}</flux:select.option>
                                <flux:select.option value="waiver">{{ __('Waiver') }}</flux:select.option>
                            </flux:select>
                            <flux:select wire:model="department_id" :label="__('Department')">
                                <flux:select.option value="">—</flux:select.option>
                                @foreach ($this->departments() as $d)
                                    <flux:select.option value="{{ $d->id }}">{{ $d->name }}</flux:select.option>
                                @endforeach
                            </flux:select>
                            <flux:button type="submit" size="sm" variant="primary">{{ __('Receive & check in') }}</flux:button>
                        </form>
                    @elseif ($r->status === 'arrived')
                        <form wire:submit="backRefer" class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                            <flux:heading level="3">{{ __('Back-referral') }}</flux:heading>
                            <flux:text class="text-sm">{{ __('Findings, treatment given and the follow-up plan for :facility.', ['facility' => $r->other_facility_name]) }}</flux:text>
                            <flux:textarea wire:model="feedback" rows="5" />
                            @error('feedback')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                            <flux:button type="submit" size="sm" variant="primary">{{ __('Complete with back-referral') }}</flux:button>
                        </form>
                    @endif
                @endif

                @if (in_array($r->status, ['draft', 'sent']))
                    <form wire:submit="cancel" class="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                        <flux:input wire:model="cancel_reason" :label="__('Cancel referral — reason')" />
                        @error('cancel_reason')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                        <flux:button type="submit" size="sm" variant="danger">{{ __('Cancel referral') }}</flux:button>
                    </form>
                @endif
            @endcan

            <div class="rounded-xl border border-neutral-200 p-4 text-xs text-neutral-500 dark:border-neutral-700">
                <div>{{ __('Created') }} {{ $r->created_at->format('d M Y H:i') }} {{ __('by') }} {{ $r->creator?->name ?? '—' }}</div>
                @if ($r->sent_at)<div>{{ $r->isOutgoing() ? __('Sent') : __('Registered') }} {{ $r->sent_at->format('d M Y H:i') }}</div>@endif
                @if ($r->arrived_at)<div>{{ __('Arrived') }} {{ $r->arrived_at->format('d M Y H:i') }}</div>@endif
                @if ($r->completed_at)<div>{{ __('Completed') }} {{ $r->completed_at->format('d M Y H:i') }}</div>@endif
            </div>
        </div>
    </div>
</div>
