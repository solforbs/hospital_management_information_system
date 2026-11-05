<?php

use App\Models\Facility;
use App\Models\HieCredential;
use App\Models\HieRequestLog;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Hie\HieClient;
use App\Services\Hie\HieException;
use App\Services\Hie\RegistryService;
use App\Services\Tenancy\TenantContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('SHA & HIE integration')] class extends Component {
    #[Url]
    public string $tab = 'connection';

    public string $environment = HieCredential::SIMULATOR;

    public string $base_url = '';

    public string $token_url = '';

    public string $client_id = '';

    public string $client_secret = '';

    public bool $enabled = false;

    public bool $hasSecret = false;

    public array $facilityCodes = [];

    public array $practitioners = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('integrations.manage'), 403);
        $this->loadMessaging();

        $credential = HieCredential::first();
        if ($credential) {
            $this->environment = $credential->environment;
            $this->base_url = (string) $credential->base_url;
            $this->token_url = (string) $credential->token_url;
            $this->client_id = (string) $credential->client_id;
            $this->enabled = $credential->enabled;
            $this->hasSecret = (bool) $credential->client_secret;
        }

        foreach (Facility::orderBy('name')->get() as $f) {
            $this->facilityCodes[$f->id] = ['fr_code' => (string) $f->fr_code, 'mfl_code' => (string) $f->mfl_code];
        }

        foreach ($this->staff as $u) {
            $this->practitioners[$u->id] = ['regulator' => (string) $u->regulator, 'registration_number' => (string) $u->registration_number];
        }
    }

    #[Computed]
    public function credential(): ?HieCredential
    {
        return HieCredential::first();
    }

    #[Computed]
    public function facilities()
    {
        return Facility::orderBy('name')->get();
    }

    #[Computed]
    public function staff()
    {
        return app(TenantContext::class)->tenant()->users()->orderBy('name')->get();
    }

    #[Computed]
    public function logs()
    {
        return HieRequestLog::with('user')->latest('id')->limit(50)->get();
    }

    public function saveConnection(AuditLogger $audit): void
    {
        abort_unless(Auth::user()->can('integrations.manage'), 403);

        $data = $this->validate([
            'environment' => ['required', 'in:simulator,uat,production'],
            'base_url' => ['nullable', 'url:https', 'max:255'],
            'token_url' => ['nullable', 'url:https', 'max:255'],
            'client_id' => ['nullable', 'string', 'max:255', 'required_unless:environment,simulator'],
            'client_secret' => ['nullable', 'string', 'max:500'],
            'enabled' => ['boolean'],
        ]);

        if ($data['environment'] !== 'simulator' && ! $data['client_secret'] && ! $this->hasSecret) {
            $this->addError('client_secret', __('The client secret is required.'));

            return;
        }

        $attributes = [
            'environment' => $data['environment'],
            'base_url' => $data['base_url'] ?: null,
            'token_url' => $data['token_url'] ?: null,
            'client_id' => $data['client_id'] ?: null,
            'enabled' => $data['enabled'],
        ];

        // Keep the stored secret unless a new one is typed.
        if ($data['client_secret']) {
            $attributes['client_secret'] = $data['client_secret'];
        }

        $credential = HieCredential::updateOrCreate(['tenant_id' => app(TenantContext::class)->id()], $attributes);
        $audit->log('hie.credentials_updated', $credential, ['environment' => $credential->environment, 'enabled' => $credential->enabled]);

        $this->client_secret = '';
        $this->hasSecret = (bool) $credential->client_secret;
        unset($this->credential);
        Flux::toast(variant: 'success', text: __('Integration settings saved.'));
    }

    public ?string $revealedSecret = null;

    public array $sms = ['environment' => 'simulator', 'username' => '', 'api_key' => '', 'sender_id' => '', 'enabled' => false];

    public array $mpesa = ['environment' => 'simulator', 'shortcode' => '', 'passkey' => '', 'consumer_key' => '', 'consumer_secret' => '', 'transaction_type' => 'CustomerPayBillOnline', 'party_b' => '', 'enabled' => false];

    public string $testPhone = '';

    public function loadMessaging(): void
    {
        foreach (['sms', 'mpesa'] as $provider) {
            $c = \App\Models\IntegrationCredential::where('provider', $provider)->first();
            if ($c) {
                foreach ($this->{$provider} as $k => $v) {
                    if (in_array($k, ['api_key', 'passkey', 'consumer_secret'], true)) {
                        continue; // never sent back to the browser
                    }
                    $this->{$provider}[$k] = match ($k) { 'environment' => $c->environment, 'enabled' => $c->enabled, default => (string) $c->get($k, '') };
                }
            }
        }
    }

    public function saveProvider(string $provider, AuditLogger $audit): void
    {
        abort_unless(Auth::user()->can('integrations.manage'), 403);
        abort_unless(in_array($provider, ['sms', 'mpesa'], true), 404);

        $rules = $provider === 'sms'
            ? ['sms.environment' => ['required', 'in:simulator,sandbox,production'], 'sms.username' => ['required_unless:sms.environment,simulator', 'nullable', 'string', 'max:60'], 'sms.api_key' => ['nullable', 'string', 'max:200'], 'sms.sender_id' => ['nullable', 'string', 'max:11'], 'sms.enabled' => ['boolean']]
            : ['mpesa.environment' => ['required', 'in:simulator,sandbox,production'], 'mpesa.shortcode' => ['required_unless:mpesa.environment,simulator', 'nullable', 'digits_between:5,7'], 'mpesa.passkey' => ['nullable', 'string', 'max:200'], 'mpesa.consumer_key' => ['nullable', 'string', 'max:200'], 'mpesa.consumer_secret' => ['nullable', 'string', 'max:200'], 'mpesa.transaction_type' => ['required', 'in:CustomerPayBillOnline,CustomerBuyGoodsOnline'], 'mpesa.party_b' => ['nullable', 'digits_between:5,8'], 'mpesa.enabled' => ['boolean']];
        $data = $this->validate($rules)[$provider];

        $credential = \App\Models\IntegrationCredential::firstOrNew(['tenant_id' => app(TenantContext::class)->id(), 'provider' => $provider]);
        $config = $credential->config ?? [];
        foreach ($data as $k => $v) {
            if (in_array($k, ['environment', 'enabled'], true)) {
                continue;
            }
            if (in_array($k, ['api_key', 'passkey', 'consumer_secret', 'consumer_key'], true) && $v === '') {
                continue; // keep stored secret
            }
            $config[$k] = $v === '' ? null : $v;
        }
        if ($provider === 'mpesa' && empty($config['callback_token'])) {
            $config['callback_token'] = bin2hex(random_bytes(20));
        }

        $credential->fill(['environment' => $data['environment'], 'enabled' => (bool) $data['enabled'], 'config' => $config])->save();
        $audit->log('integration.'.$provider.'_updated', $credential, ['environment' => $data['environment'], 'enabled' => (bool) $data['enabled']]);

        foreach (['api_key', 'passkey', 'consumer_secret', 'consumer_key'] as $secret) {
            if (array_key_exists($secret, $this->{$provider})) {
                $this->{$provider}[$secret] = '';
            }
        }
        Flux::toast(variant: 'success', text: __('Saved.'));
    }

    public function sendTestSms(\App\Services\Messaging\SmsService $sms): void
    {
        abort_unless(Auth::user()->can('integrations.manage'), 403);
        $this->validate(['testPhone' => ['required', 'string', 'max:20']]);
        $m = $sms->send($this->testPhone, __('Test message from :h', ['h' => app(TenantContext::class)->tenant()->displayName()]), 'manual');
        Flux::toast(variant: $m->status === 'sent' ? 'success' : 'danger', text: $m->status === 'sent' ? __('Test SMS sent.') : __('SMS failed: :e', ['e' => $m->error]));
    }

    /** Generate (or rotate) the secret SHA/HIE uses to sign status callbacks. */
    public function rotateWebhookSecret(AuditLogger $audit): void
    {
        abort_unless(Auth::user()->can('integrations.manage'), 403);
        $credential = HieCredential::firstOrCreate(['tenant_id' => app(TenantContext::class)->id()], ['environment' => HieCredential::SIMULATOR]);
        $secret = bin2hex(random_bytes(24));
        $credential->update(['webhook_secret' => $secret]);
        $audit->log('hie.webhook_secret_rotated', $credential);
        $this->revealedSecret = $secret; // shown once
        unset($this->credential);
    }

    public function testConnection(HieClient $client): void
    {
        abort_unless(Auth::user()->can('integrations.manage'), 403);
        $credential = $this->credential;

        if (! $credential) {
            Flux::toast(variant: 'danger', text: __('Save the settings first.'));

            return;
        }

        try {
            $result = $client->testConnection($credential);
            $credential->update(['last_tested_at' => now(), 'last_test_result' => $result]);
            Flux::toast(variant: 'success', text: $result);
        } catch (HieException $e) {
            $credential->update(['last_tested_at' => now(), 'last_test_result' => substr($e->getMessage(), 0, 250)]);
            Flux::toast(variant: 'danger', text: $e->getMessage());
        }

        unset($this->credential);
    }

    public function saveFacility(int $id, bool $verify = false): void
    {
        $registry = app(RegistryService::class);
        abort_unless(Auth::user()->can('integrations.manage'), 403);

        $this->validate([
            "facilityCodes.$id.fr_code" => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\-]+$/'],
            "facilityCodes.$id.mfl_code" => ['nullable', 'string', 'max:20', 'regex:/^[0-9]+$/'],
        ], [], ["facilityCodes.$id.fr_code" => 'FR code', "facilityCodes.$id.mfl_code" => 'MFL code']);

        $facility = Facility::findOrFail($id);
        $codes = $this->facilityCodes[$id];
        $facility->update(['fr_code' => strtoupper(trim($codes['fr_code'])) ?: null, 'mfl_code' => trim($codes['mfl_code']) ?: null]);

        if ($verify) {
            try {
                $info = $registry->verifyFacility($facility);
                Flux::toast(variant: 'success', text: __('Verified: :name (:level), SHA contract :status.', ['name' => $info['name'] ?? $facility->name, 'level' => $info['keph_level'] ?? '—', 'status' => $info['sha_contract_status'] ?? '—']));
            } catch (HieException $e) {
                Flux::toast(variant: 'danger', text: $e->getMessage());
            }
        } else {
            Flux::toast(variant: 'success', text: __('Facility codes saved.'));
        }

        unset($this->facilities);
    }

    public function savePractitioner(int $id, bool $verify = false): void
    {
        $registry = app(RegistryService::class);
        abort_unless(Auth::user()->can('integrations.manage'), 403);

        $this->validate([
            "practitioners.$id.regulator" => ['nullable', 'in:KMPDC,NCK,COC,PPB'],
            "practitioners.$id.registration_number" => ['nullable', 'string', 'max:40', 'regex:/^[A-Za-z0-9\/\-]+$/'],
        ], [], ["practitioners.$id.registration_number" => 'registration number']);

        // Only members of this hospital can be edited.
        $user = $this->staff->firstWhere('id', $id);
        abort_unless($user instanceof User, 404);

        $p = $this->practitioners[$id];
        $changed = $user->regulator !== ($p['regulator'] ?: null) || $user->registration_number !== (trim($p['registration_number']) ?: null);
        $user->forceFill([
            'regulator' => $p['regulator'] ?: null,
            'registration_number' => strtoupper(trim($p['registration_number'])) ?: null,
        ] + ($changed ? ['registry_verified_at' => null, 'license_valid_until' => null] : []))->save();

        if ($verify) {
            try {
                $info = $registry->verifyPractitioner($user);
                Flux::toast(variant: $info['active'] ? 'success' : 'warning', text: $info['active']
                    ? __('Licence active until :date.', ['date' => $info['license_end'] ?? '—'])
                    : __('Registry record found but the licence is not active.'));
            } catch (HieException $e) {
                Flux::toast(variant: 'danger', text: $e->getMessage());
            }
        } else {
            Flux::toast(variant: 'success', text: __('Practitioner details saved.'));
        }

        unset($this->staff);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading level="1">{{ __('SHA & HIE integration') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Connection to the DHA Health Information Exchange (AfyaLink) for Client, Facility and Health Worker Registry lookups and SHA eligibility, consent and eClaims.') }}</flux:text>
    </div>

    <div class="flex gap-1 border-b border-neutral-200 dark:border-neutral-700">
        @foreach (['connection' => __('Connection'), 'facilities' => __('Facilities'), 'practitioners' => __('Practitioners'), 'messaging' => __('SMS & M-Pesa'), 'log' => __('Request log')] as $k => $l)
            <button type="button" wire:click="$set('tab', '{{ $k }}')" class="-mb-px border-b-2 px-3 py-2 text-sm {{ $tab === $k ? 'border-indigo-500 font-medium' : 'border-transparent text-neutral-500' }}">{{ $l }}</button>
        @endforeach
    </div>

    @if ($tab === 'connection')
        <div class="grid gap-6 lg:grid-cols-3">
            <form wire:submit="saveConnection" class="grid content-start gap-4 rounded-xl border border-neutral-200 p-5 lg:col-span-2 dark:border-neutral-700">
                <flux:select wire:model.live="environment" :label="__('Environment')">
                    <flux:select.option value="simulator">{{ __('Simulator (local, no real calls)') }}</flux:select.option>
                    <flux:select.option value="uat">{{ __('DHA UAT / sandbox') }}</flux:select.option>
                    <flux:select.option value="production">{{ __('Production') }}</flux:select.option>
                </flux:select>

                @if ($environment === 'simulator')
                    <flux:callout variant="secondary" icon="beaker">
                        <flux:callout.text>{{ __('The simulator answers locally with UAT-shaped responses. OTP is always 123456; ID 00000000 is "not found"; IDs ending in 9 are not SHA-eligible.') }}</flux:callout.text>
                    </flux:callout>
                @else
                    <flux:input wire:model="base_url" :label="__('API base URL')" :placeholder="config('hms.hie.environments.'.$environment.'.base_url') ?: 'https://…/api/v1'" :description="__('Leave blank to use the default for this environment.')" />
                    <flux:input wire:model="token_url" :label="__('Token URL')" :placeholder="config('hms.hie.environments.'.$environment.'.token_url') ?: __('Issued at DHA onboarding')" />
                    <flux:input wire:model="client_id" :label="__('Client ID')" autocomplete="off" />
                    <flux:input wire:model="client_secret" type="password" :label="__('Client secret')" autocomplete="new-password" :placeholder="$hasSecret ? __('Stored — leave blank to keep') : ''" />
                @endif

                <flux:checkbox wire:model="enabled" :label="__('Enable HIE integration for this hospital')" />

                <div class="flex gap-2">
                    <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                    <flux:button wire:click="testConnection" icon="signal">{{ __('Test connection') }}</flux:button>
                </div>
            </form>

            <div class="space-y-3 text-sm">
                <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('Status') }}</flux:heading>
                    @if ($this->credential)
                        <div class="mt-2 space-y-1">
                            <div>{{ __('Environment') }}: <span class="font-medium">{{ strtoupper($this->credential->environment) }}</span></div>
                            <div>{{ __('Enabled') }}: <flux:badge size="sm" :color="$this->credential->enabled ? 'lime' : 'zinc'">{{ $this->credential->enabled ? __('Yes') : __('No') }}</flux:badge></div>
                            <div class="text-neutral-500">{{ __('Last test') }}: {{ $this->credential->last_tested_at?->diffForHumans() ?? '—' }}</div>
                            @if ($this->credential->last_test_result)<div class="text-xs text-neutral-500">{{ $this->credential->last_test_result }}</div>@endif
                        </div>
                    @else
                        <flux:text class="mt-2">{{ __('Not configured.') }}</flux:text>
                    @endif
                </div>
                <div class="space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('Status callbacks & remittances') }}</flux:heading>
                    <flux:text class="text-xs">{{ __('Give SHA/DHA this URL to push claim decisions and payment advice. Requests must be signed with HMAC-SHA256 (header X-HMS-Signature: sha256=…).') }}</flux:text>
                    <div class="break-all rounded bg-zinc-50 px-2 py-1 font-mono text-xs dark:bg-zinc-800">{{ route('hooks.sha', app(TenantContext::class)->tenant()->slug) }}</div>
                    @if ($revealedSecret)
                        <div class="rounded bg-amber-50 p-2 text-xs dark:bg-amber-950">{{ __('Secret (shown once — copy it now):') }}<div class="mt-1 break-all font-mono">{{ $revealedSecret }}</div></div>
                    @endif
                    <flux:button size="xs" icon="key" wire:click="rotateWebhookSecret" wire:confirm="{{ __('Generate a new signing secret? The old one stops working.') }}">{{ $this->credential?->webhook_secret ? __('Rotate secret') : __('Generate secret') }}</flux:button>
                </div>
                <div class="rounded-xl border border-neutral-200 p-4 text-neutral-600 dark:border-neutral-700 dark:text-neutral-300">
                    <flux:heading level="3">{{ __('Go-live checklist') }}</flux:heading>
                    <ol class="mt-2 list-decimal space-y-1 pl-4">
                        <li>{{ __('Hospital system DHA-certified (Form HMIS 4, ODPC, DPIA)') }}</li>
                        <li>{{ __('UAT client credentials from DHA onboarding') }}</li>
                        <li>{{ __('Facility Registry (FR) code on every facility') }}</li>
                        <li>{{ __('Regulator + registration number for every clinician') }}</li>
                        <li>{{ __('SHA intervention codes mapped in tariffs, lab and imaging catalogs') }}</li>
                        <li>{{ __('Full patient journey tested in UAT, then switch to production') }}</li>
                    </ol>
                </div>
            </div>
        </div>
    @elseif ($tab === 'facilities')
        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-3 py-2">{{ __('Facility') }}</th><th class="px-3 py-2">{{ __('FR code') }}</th><th class="px-3 py-2">{{ __('MFL code') }}</th><th class="px-3 py-2">{{ __('Registry') }}</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach ($this->facilities as $f)
                        <tr wire:key="f-{{ $f->id }}">
                            <td class="px-3 py-2">{{ $f->name }}<div class="font-mono text-xs text-neutral-500">{{ $f->code }}</div></td>
                            <td class="px-3 py-2"><flux:input size="sm" wire:model="facilityCodes.{{ $f->id }}.fr_code" placeholder="FID-47-115307-8" /></td>
                            <td class="px-3 py-2"><flux:input size="sm" wire:model="facilityCodes.{{ $f->id }}.mfl_code" /></td>
                            <td class="px-3 py-2 text-xs">
                                @if ($f->registry_verified_at)
                                    <flux:badge size="sm" color="lime">{{ __('Verified') }}</flux:badge>
                                    <div class="mt-1 text-neutral-500">{{ $f->keph_level }} · SHA {{ $f->sha_contract_status ?? '—' }}</div>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Not verified') }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <flux:button size="xs" wire:click="saveFacility({{ $f->id }})">{{ __('Save') }}</flux:button>
                                <flux:button size="xs" variant="primary" wire:click="saveFacility({{ $f->id }}, true)">{{ __('Save & verify') }}</flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @error('facilityCodes.*')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
    @elseif ($tab === 'practitioners')
        <flux:text>{{ __('SHA validates the attending practitioner on every claim against the Health Worker Registry.') }}</flux:text>
        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-3 py-2">{{ __('Staff') }}</th><th class="px-3 py-2">{{ __('Regulator') }}</th><th class="px-3 py-2">{{ __('Registration no.') }}</th><th class="px-3 py-2">{{ __('Licence') }}</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach ($this->staff as $u)
                        <tr wire:key="p-{{ $u->id }}">
                            <td class="px-3 py-2">{{ $u->name }}<div class="text-xs text-neutral-500">{{ $u->email }}</div></td>
                            <td class="px-3 py-2">
                                <flux:select size="sm" wire:model="practitioners.{{ $u->id }}.regulator">
                                    <flux:select.option value="">—</flux:select.option>
                                    @foreach (RegistryService::REGULATORS as $k => $l)
                                        <flux:select.option value="{{ $k }}">{{ $k }}</flux:select.option>
                                    @endforeach
                                </flux:select>
                            </td>
                            <td class="px-3 py-2"><flux:input size="sm" wire:model="practitioners.{{ $u->id }}.registration_number" /></td>
                            <td class="px-3 py-2 text-xs">
                                @if ($u->registry_verified_at && (! $u->license_valid_until || $u->license_valid_until->isFuture()))
                                    <flux:badge size="sm" color="lime">{{ __('Active') }}</flux:badge>
                                    <div class="mt-1 text-neutral-500">{{ __('until') }} {{ $u->license_valid_until?->format('d M Y') ?? '—' }}</div>
                                @elseif ($u->license_valid_until?->isPast())
                                    <flux:badge size="sm" color="red">{{ __('Expired') }}</flux:badge>
                                @else
                                    <flux:badge size="sm" color="zinc">{{ __('Not verified') }}</flux:badge>
                                @endif
                            </td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <flux:button size="xs" wire:click="savePractitioner({{ $u->id }})">{{ __('Save') }}</flux:button>
                                <flux:button size="xs" variant="primary" wire:click="savePractitioner({{ $u->id }}, true)">{{ __('Save & verify') }}</flux:button>
                            </td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
        @error('practitioners.*')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
    @elseif ($tab === 'messaging')
        <div class="grid gap-6 lg:grid-cols-2">
            <form wire:submit="saveProvider('sms')" class="grid content-start gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:heading level="3">{{ __('SMS (Africa\'s Talking)') }}</flux:heading>
                <flux:text class="text-xs">{{ __('Used for appointment, ANC and immunisation reminders (sent daily at 08:00).') }}</flux:text>
                <flux:select wire:model.live="sms.environment" :label="__('Environment')">
                    <flux:select.option value="simulator">{{ __('Simulator (no real SMS)') }}</flux:select.option>
                    <flux:select.option value="sandbox">{{ __('Sandbox') }}</flux:select.option>
                    <flux:select.option value="production">{{ __('Production') }}</flux:select.option>
                </flux:select>
                @if ($sms['environment'] !== 'simulator')
                    <flux:input wire:model="sms.username" :label="__('Username')" />
                    <flux:input wire:model="sms.api_key" type="password" :label="__('API key')" :placeholder="__('Stored — leave blank to keep')" autocomplete="new-password" />
                    <flux:input wire:model="sms.sender_id" :label="__('Sender ID (optional, approved alphanumeric)')" />
                @endif
                <flux:checkbox wire:model="sms.enabled" :label="__('Enable SMS')" />
                <div class="flex flex-wrap items-end gap-2">
                    <flux:button type="submit" size="sm" variant="primary">{{ __('Save') }}</flux:button>
                    <flux:input wire:model="testPhone" size="sm" placeholder="07XX XXX XXX" class="w-40" />
                    <flux:button size="sm" wire:click="sendTestSms">{{ __('Send test') }}</flux:button>
                </div>
            </form>
            <form wire:submit="saveProvider('mpesa')" class="grid content-start gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
                <flux:heading level="3">{{ __('M-Pesa (Daraja STK push)') }}</flux:heading>
                <flux:text class="text-xs">{{ __('Cashiers can send a payment prompt to the patient\'s phone; confirmed payments post to the invoice automatically.') }}</flux:text>
                <flux:select wire:model.live="mpesa.environment" :label="__('Environment')">
                    <flux:select.option value="simulator">{{ __('Simulator (instant success)') }}</flux:select.option>
                    <flux:select.option value="sandbox">{{ __('Daraja sandbox') }}</flux:select.option>
                    <flux:select.option value="production">{{ __('Production') }}</flux:select.option>
                </flux:select>
                @if ($mpesa['environment'] !== 'simulator')
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="mpesa.shortcode" :label="__('Business shortcode')" />
                        <flux:select wire:model="mpesa.transaction_type" :label="__('Type')"><flux:select.option value="CustomerPayBillOnline">{{ __('Paybill') }}</flux:select.option><flux:select.option value="CustomerBuyGoodsOnline">{{ __('Till (Buy goods)') }}</flux:select.option></flux:select>
                        <flux:input wire:model="mpesa.party_b" :label="__('Till / PartyB (if different)')" />
                        <flux:input wire:model="mpesa.passkey" type="password" :label="__('Passkey')" :placeholder="__('Stored — leave blank to keep')" autocomplete="new-password" />
                        <flux:input wire:model="mpesa.consumer_key" type="password" :label="__('Consumer key')" :placeholder="__('Stored — leave blank to keep')" autocomplete="new-password" />
                        <flux:input wire:model="mpesa.consumer_secret" type="password" :label="__('Consumer secret')" :placeholder="__('Stored — leave blank to keep')" autocomplete="new-password" />
                    </div>
                @endif
                <flux:checkbox wire:model="mpesa.enabled" :label="__('Enable M-Pesa prompts')" />
                <div><flux:button type="submit" size="sm" variant="primary">{{ __('Save') }}</flux:button></div>
            </form>
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-3 py-2">{{ __('Time') }}</th><th class="px-3 py-2">{{ __('Call') }}</th><th class="px-3 py-2">{{ __('Status') }}</th><th class="px-3 py-2">{{ __('User') }}</th><th class="px-3 py-2 text-right">{{ __('ms') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->logs as $log)
                        <tr wire:key="l-{{ $log->id }}">
                            <td class="px-3 py-2 text-xs text-neutral-500">{{ $log->created_at->format('d M H:i:s') }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $log->method }} {{ $log->path }} <span class="text-neutral-400">({{ $log->environment }})</span>@if ($log->error)<div class="font-sans text-red-600">{{ $log->error }}</div>@endif</td>
                            <td class="px-3 py-2"><flux:badge size="sm" :color="$log->status && $log->status < 300 ? 'lime' : 'red'">{{ $log->status ?? __('n/a') }}</flux:badge></td>
                            <td class="px-3 py-2 text-xs">{{ $log->user?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-right text-xs">{{ $log->duration_ms }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-neutral-500">{{ __('No HIE calls yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    @endif
</div>
