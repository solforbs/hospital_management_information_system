<?php

use App\Models\Encounter;
use App\Services\Hie\HieClient;
use App\Services\Hie\HieException;
use App\Services\Hie\ShaService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('SHA verification')] class extends Component {
    public Encounter $encounter;

    public array $benefits = [];

    public array $selected = [];

    public string $service_type = 'OUTPATIENT';

    public bool $otpSent = false;

    public string $otp = '';

    public ?string $notice = null;

    public function mount(Encounter $encounter): void
    {
        abort_unless(Auth::user()->can('sha.verify'), 403);
        $this->encounter = $encounter->load('patient');
        $this->selected = $encounter->sha_interventions ?? [];
        $this->service_type = $encounter->sha_service_type ?? match ($encounter->type) {
            'inpatient' => 'INPATIENT',
            'emergency' => 'EMERGENCY',
            default => 'OUTPATIENT',
        };
    }

    public function hieEnabled(): bool
    {
        return app(HieClient::class)->isEnabled();
    }

    public function checkEligibility(ShaService $sha): void
    {
        $this->guard(function () use ($sha) {
            $result = $sha->checkEligibility($this->encounter);
            $this->encounter->refresh();

            if ($result['eligible']) {
                $this->benefits = $sha->interventions($this->encounter->fresh('patient'));
                Flux::toast(variant: 'success', text: __('Member is eligible for SHA cover.'));
            } else {
                Flux::toast(variant: 'warning', text: $result['found'] ? __('Member found but not currently eligible.') : __('Not found as an SHA member.'));
            }
        });
    }

    public function loadBenefits(ShaService $sha): void
    {
        $this->guard(fn () => $this->benefits = $sha->interventions($this->encounter));
    }

    public function requestOtp(ShaService $sha): void
    {
        abort_unless($this->encounter->status === 'open', 422);

        $this->guard(function () use ($sha) {
            $this->notice = $sha->requestVisitOtp($this->encounter, $this->selected, $this->service_type);
            $this->otpSent = true;
            $this->encounter->refresh();
        });
    }

    public function confirmOtp(ShaService $sha): void
    {
        $this->validate(['otp' => ['required', 'string', 'regex:/^[0-9]{4,8}$/']], [], ['otp' => 'OTP']);

        $this->guard(function () use ($sha) {
            $sha->confirmVisitOtp($this->encounter, $this->otp);
            $this->reset(['otp', 'otpSent', 'notice']);
            $this->encounter->refresh();
            Flux::toast(variant: 'success', text: __('SHA visit consent recorded.'));
        });
    }

    protected function guard(callable $fn): void
    {
        abort_unless(Auth::user()->can('sha.verify'), 403);

        try {
            $fn();
        } catch (HieException $e) {
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('SHA verification') }}</flux:heading>
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('reception.index')" wire:navigate>{{ __('Reception') }}</flux:button>
    </div>

    <x-patient-banner :patient="$encounter->patient" :encounter="$encounter" />

    @unless ($this->hieEnabled())
        <flux:callout variant="warning" icon="exclamation-triangle">
            <flux:callout.text>{{ __('The HIE integration is not enabled for this hospital. An administrator can configure it under Administration → SHA & HIE integration.') }}</flux:callout.text>
        </flux:callout>
    @endunless

    @error('sha')<flux:callout variant="danger" icon="x-circle"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>@enderror

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <flux:heading level="3">{{ __('1. Eligibility') }}</flux:heading>
            <div class="text-sm">
                <div>{{ __('ID') }}: <span class="font-mono">{{ $encounter->patient->identification_type ?? 'National ID' }} {{ $encounter->patient->national_id ?? '—' }}</span></div>
                <div>{{ __('CR number') }}: <span class="font-mono">{{ $encounter->patient->cr_number ?? '—' }}</span></div>
            </div>
            @php($e = $encounter->sha_eligibility)
            @if ($e)
                <div class="rounded-lg p-3 text-sm {{ $e['eligible'] ? 'bg-lime-50 dark:bg-lime-950/40' : 'bg-red-50 dark:bg-red-950/40' }}">
                    <div class="font-medium">{{ $e['eligible'] ? __('Eligible') : __('Not eligible') }} · {{ $e['status'] }}</div>
                    @if ($e['message'])<div class="text-xs">{{ $e['message'] }}</div>@endif
                    <div class="text-xs text-neutral-500">{{ __('Checked') }} {{ $encounter->sha_checked_at?->diffForHumans() }}</div>
                </div>
            @endif
            <flux:button variant="primary" size="sm" wire:click="checkEligibility" icon="magnifying-glass">{{ $e ? __('Re-check') : __('Check eligibility') }}</flux:button>
        </div>

        <div class="space-y-3 rounded-xl border border-neutral-200 p-5 lg:col-span-2 dark:border-neutral-700">
            <flux:heading level="3">{{ __('2. Visit consent') }}</flux:heading>

            @if ($encounter->sha_consent_token)
                <flux:callout variant="success" icon="check-circle">
                    <flux:callout.heading>{{ __('Consent recorded') }}</flux:callout.heading>
                    <flux:callout.text>
                        {{ strtoupper($encounter->sha_consent_method) }} · {{ $encounter->sha_consented_at?->format('d M Y H:i') }} · {{ \App\Services\Hie\ShaService::SERVICE_TYPES[$encounter->sha_service_type] ?? $encounter->sha_service_type }}
                        <div class="mt-1 font-mono text-xs">{{ implode(', ', $encounter->sha_interventions ?? []) }}</div>
                    </flux:callout.text>
                </flux:callout>
            @elseif (! ($e['eligible'] ?? false))
                <flux:text>{{ __('Confirm eligibility first.') }}</flux:text>
            @else
                <flux:select wire:model="service_type" :label="__('Service type')" class="max-w-xs">
                    @foreach (\App\Services\Hie\ShaService::SERVICE_TYPES as $k => $l)
                        <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                    @endforeach
                </flux:select>

                @if (! $benefits)
                    <flux:button size="sm" wire:click="loadBenefits">{{ __('Load benefits') }}</flux:button>
                @endif

                <div class="space-y-3">
                    @foreach ($benefits as $b)
                        <div wire:key="b-{{ $b['code'] }}">
                            <div class="text-sm font-medium">{{ $b['name'] }} <span class="font-mono text-xs text-neutral-500">{{ $b['code'] }}</span></div>
                            <div class="mt-1 grid gap-1 sm:grid-cols-2">
                                @foreach ($b['interventions'] as $i)
                                    <label wire:key="i-{{ $i['code'] }}" class="flex items-center gap-2 text-sm">
                                        <input type="checkbox" wire:model="selected" value="{{ $i['code'] }}" class="rounded" />
                                        <span>{{ $i['name'] }} <span class="font-mono text-xs text-neutral-500">{{ $i['code'] }}</span>@if ($i['needs_preauth']) <flux:badge size="sm" color="amber">{{ __('pre-auth') }}</flux:badge>@endif</span>
                                    </label>
                                @endforeach
                            </div>
                        </div>
                    @endforeach
                </div>

                <div class="flex flex-wrap items-end gap-3">
                    <flux:button variant="primary" size="sm" wire:click="requestOtp" :disabled="! $benefits">{{ $otpSent ? __('Resend OTP') : __('Send OTP to member') }}</flux:button>
                    @if ($otpSent)
                        <form wire:submit="confirmOtp" class="flex items-end gap-2">
                            <flux:input wire:model="otp" :label="__('OTP from member')" inputmode="numeric" autocomplete="one-time-code" class="w-36" />
                            <flux:button type="submit" size="sm" variant="primary">{{ __('Confirm') }}</flux:button>
                        </form>
                    @endif
                </div>
                @if ($notice)<flux:text class="text-xs">{{ $notice }}</flux:text>@endif
                @error('otp')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
            @endif
        </div>
    </div>
</div>
