<?php

use App\Models\Facility;
use App\Models\LabGateway;
use App\Models\LabInstrument;
use App\Models\LabInstrumentMapping;
use App\Models\LabInstrumentMessage;
use App\Models\LabTest;
use App\Services\Audit\AuditLogger;
use App\Support\AnalyserPresets;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

/**
 * Lab → Analysers: device gateways, connected instruments, analyser code
 * mappings and the message log. See docs/lab-analyser-integration.md.
 */
new #[Title('Lab analysers')] class extends Component {
    public array $gw = ['name' => '', 'facility_id' => null];

    public ?string $newToken = null;

    public ?string $newTokenFor = null;

    public ?int $editingInstrumentId = null;

    public array $inst = ['name' => '', 'manufacturer' => '', 'model' => '', 'code' => '', 'protocol' => 'astm', 'lab_gateway_id' => null, 'bidirectional' => false];

    public bool $showInstrumentForm = false;

    public ?int $instrumentId = null;

    public array $map = ['instrument_code' => '', 'lab_test_id' => null, 'component' => ''];

    public string $preset = '';

    public ?int $openMessageId = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('lab.catalog.manage'), 403);
        $this->instrumentId = LabInstrument::orderBy('name')->value('id');
    }

    #[Computed]
    public function gateways()
    {
        return LabGateway::with('facility')->withCount('instruments')->orderBy('name')->get();
    }

    #[Computed]
    public function instruments()
    {
        return LabInstrument::with('gateway')->withCount('mappings')->orderBy('name')->get();
    }

    #[Computed]
    public function instrument(): ?LabInstrument
    {
        return $this->instrumentId ? LabInstrument::find($this->instrumentId) : null;
    }

    #[Computed]
    public function mappings()
    {
        return $this->instrumentId ? LabInstrumentMapping::with('test')->where('lab_instrument_id', $this->instrumentId)->orderBy('sort')->orderBy('instrument_code')->get() : collect();
    }

    #[Computed]
    public function tests()
    {
        return LabTest::where('active', true)->orderBy('name')->get(['id', 'code', 'name']);
    }

    #[Computed]
    public function messages()
    {
        return LabInstrumentMessage::with('instrument')->latest()->limit(30)->get();
    }

    #[Computed]
    public function attention(): int
    {
        return LabInstrumentMessage::whereIn('status', ['unmatched', 'rejected'])->where('created_at', '>=', now()->subDay())->count();
    }

    // Gateways

    public function addGateway(AuditLogger $audit): void
    {
        $data = $this->validate(['gw.name' => ['required', 'string', 'max:80'], 'gw.facility_id' => ['nullable', 'exists:facilities,id']])['gw'];

        $gateway = LabGateway::create(['name' => $data['name'], 'facility_id' => $data['facility_id'] ?: null, 'token_hash' => hash('sha256', uniqid('', true)), 'token_hint' => '']);
        $this->showToken($gateway, $gateway->issueToken());
        $audit->log('lab.gateway_created', $gateway);
        $this->reset('gw');
        unset($this->gateways);
    }

    public function rotateToken(int $id, AuditLogger $audit): void
    {
        $gateway = LabGateway::findOrFail($id);
        $this->showToken($gateway, $gateway->issueToken());
        $audit->log('lab.gateway_token_rotated', $gateway);
        unset($this->gateways);
    }

    public function toggleGateway(int $id): void
    {
        $gateway = LabGateway::findOrFail($id);
        $gateway->update(['active' => ! $gateway->active]);
        unset($this->gateways);
    }

    protected function showToken(LabGateway $gateway, string $token): void
    {
        $this->newToken = $token;
        $this->newTokenFor = $gateway->name;
    }

    public function dismissToken(): void
    {
        $this->reset('newToken', 'newTokenFor');
    }

    // Instruments

    public function newInstrument(): void
    {
        $this->reset('inst', 'editingInstrumentId');
        $this->inst['lab_gateway_id'] = $this->gateways->first()?->id;
        $this->showInstrumentForm = true;
    }

    public function editInstrument(int $id): void
    {
        $i = LabInstrument::findOrFail($id);
        $this->editingInstrumentId = $i->id;
        $this->inst = $i->only(['name', 'manufacturer', 'model', 'code', 'protocol', 'lab_gateway_id', 'bidirectional']);
        $this->inst['manufacturer'] ??= '';
        $this->inst['model'] ??= '';
        $this->showInstrumentForm = true;
    }

    public function saveInstrument(AuditLogger $audit): void
    {
        $tenantId = app(\App\Services\Tenancy\TenantContext::class)->id();
        $data = $this->validate([
            'inst.name' => ['required', 'string', 'max:80'],
            'inst.manufacturer' => ['nullable', 'string', 'max:60'],
            'inst.model' => ['nullable', 'string', 'max:60'],
            'inst.code' => ['required', 'string', 'max:40', 'regex:/^[A-Za-z0-9_\-]+$/', Rule::unique('lab_instruments', 'code')->where('tenant_id', $tenantId)->ignore($this->editingInstrumentId)],
            'inst.protocol' => ['required', Rule::in(array_keys(LabInstrument::PROTOCOLS))],
            'inst.lab_gateway_id' => ['nullable', Rule::exists('lab_gateways', 'id')->where('tenant_id', $tenantId)],
            'inst.bidirectional' => ['boolean'],
        ], ['inst.code.regex' => __('Use letters, numbers, dashes or underscores (e.g. BC30-LAB1).')])['inst'];

        $instrument = $this->editingInstrumentId
            ? tap(LabInstrument::findOrFail($this->editingInstrumentId))->update($data)
            : LabInstrument::create($data + ['active' => true]);
        $audit->log($this->editingInstrumentId ? 'lab.instrument_updated' : 'lab.instrument_created', $instrument);

        $this->instrumentId = $instrument->id;
        $this->showInstrumentForm = false;
        $this->reset('editingInstrumentId');
        unset($this->instruments, $this->instrument, $this->mappings);
        Flux::toast(variant: 'success', text: __('Instrument saved. Now map its codes to your lab tests.'));
    }

    public function toggleInstrument(int $id): void
    {
        $i = LabInstrument::findOrFail($id);
        $i->update(['active' => ! $i->active]);
        unset($this->instruments);
    }

    public function selectInstrument(int $id): void
    {
        $this->instrumentId = $id;
        $this->reset('map', 'preset');
        unset($this->instrument, $this->mappings);
    }

    // Mappings

    public function addMapping(): void
    {
        abort_unless($this->instrument, 404);
        $data = $this->validate([
            'map.instrument_code' => ['required', 'string', 'max:40'],
            'map.lab_test_id' => ['required', Rule::exists('lab_tests', 'id')->where('tenant_id', $this->instrument->tenant_id)],
            'map.component' => ['nullable', 'string', 'max:60'],
        ])['map'];

        $code = strtoupper(trim($data['instrument_code']));
        if (LabInstrumentMapping::where('lab_instrument_id', $this->instrumentId)->where('instrument_code', $code)->where('lab_test_id', $data['lab_test_id'])->exists()) {
            $this->addError('map.instrument_code', __('This code is already mapped to that test.'));

            return;
        }

        LabInstrumentMapping::create(['lab_instrument_id' => $this->instrumentId, 'instrument_code' => $code, 'lab_test_id' => $data['lab_test_id'], 'component' => trim((string) $data['component']) ?: null, 'sort' => $this->mappings->count()]);
        $this->reset('map');
        unset($this->mappings, $this->instruments);
    }

    public function removeMapping(int $id): void
    {
        LabInstrumentMapping::where('lab_instrument_id', $this->instrumentId)->whereKey($id)->delete();
        unset($this->mappings, $this->instruments);
    }

    public function applyPreset(): void
    {
        abort_unless($this->instrument, 404);
        $preset = AnalyserPresets::all()[$this->preset] ?? null;
        if (! $preset) {
            $this->addError('preset', __('Choose a preset.'));

            return;
        }

        $tests = LabTest::pluck('id', 'code');
        $added = 0;
        $missing = [];
        $sort = $this->mappings->count();

        foreach ($preset['rows'] as [$code, $testCode, $component]) {
            if (! isset($tests[$testCode])) {
                $missing[$testCode] = true;

                continue;
            }
            $mapping = LabInstrumentMapping::firstOrCreate(
                ['lab_instrument_id' => $this->instrumentId, 'instrument_code' => $code, 'lab_test_id' => $tests[$testCode]],
                ['component' => $component, 'sort' => $sort++],
            );
            $added += $mapping->wasRecentlyCreated ? 1 : 0;
        }

        unset($this->mappings, $this->instruments);
        Flux::toast(variant: 'success', text: __(':n mappings added.', ['n' => $added]).($missing ? ' '.__('Not in your catalog: :codes', ['codes' => implode(', ', array_keys($missing))]) : ''));
    }

    public function toggleMessage(int $id): void
    {
        $this->openMessageId = $this->openMessageId === $id ? null : $id;
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex flex-wrap items-end justify-between gap-3">
        <div>
            <flux:heading level="1">{{ __('Lab analysers') }}</flux:heading>
            <flux:text>{{ __('Connect laboratory analysers through a device gateway on your network. Results arrive in the worklist as "resulted" and must still be verified by staff.') }}</flux:text>
        </div>
        @if ($this->attention)
            <flux:badge color="red" icon="exclamation-triangle">{{ trans_choice(':n message needs attention (24h)|:n messages need attention (24h)', $this->attention, ['n' => $this->attention]) }}</flux:badge>
        @endif
    </div>

    @if ($newToken)
        <div class="rounded-xl border border-amber-300 bg-amber-50 p-4 dark:border-amber-700 dark:bg-amber-950/30">
            <div class="font-semibold">{{ __('Gateway token for :name — copy it now', ['name' => $newTokenFor]) }}</div>
            <p class="mt-1 text-sm text-neutral-600 dark:text-neutral-300">{{ __('Put this in the gateway\'s config file as "token". It is shown only once; if lost, rotate it.') }}</p>
            <div class="mt-3 flex flex-wrap items-center gap-2">
                <code class="rounded-lg bg-white px-3 py-2 font-mono text-sm break-all dark:bg-neutral-900" id="gw-token">{{ $newToken }}</code>
                <flux:button size="sm" icon="clipboard" x-on:click="navigator.clipboard.writeText(document.getElementById('gw-token').innerText); $el.innerText='{{ __('Copied') }}'">{{ __('Copy') }}</flux:button>
                <flux:button size="sm" variant="ghost" wire:click="dismissToken">{{ __('Done') }}</flux:button>
            </div>
            <p class="mt-3 text-xs text-neutral-500">{{ __('Server address for the gateway:') }} <code>{{ url('/api/lis/v1') }}</code></p>
        </div>
    @endif

    {{-- Gateways --}}
    <section class="grid gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <flux:heading level="2">{{ __('1. Device gateways') }}</flux:heading>
                <flux:text class="text-sm">{{ __('The HMIS gateway program runs on a computer in the lab, connected to the analysers by cable or network.') }}</flux:text>
            </div>
            <form wire:submit="addGateway" class="flex flex-wrap items-end gap-2">
                <flux:input wire:model="gw.name" :label="__('Name')" placeholder="{{ __('Main lab PC') }}" class="w-48" />
                <flux:select wire:model="gw.facility_id" :label="__('Facility')" class="w-48">
                    <flux:select.option value="">{{ __('Any') }}</flux:select.option>
                    @foreach (\App\Models\Facility::orderBy('name')->get() as $f)
                        <flux:select.option value="{{ $f->id }}">{{ $f->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button type="submit" variant="primary" icon="plus">{{ __('Add gateway') }}</flux:button>
            </form>
        </div>
        <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-3 py-2">{{ __('Gateway') }}</th><th class="px-3 py-2">{{ __('Status') }}</th><th class="px-3 py-2">{{ __('Instruments') }}</th><th class="px-3 py-2">{{ __('Token') }}</th><th class="px-3 py-2"></th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->gateways as $g)
                        <tr wire:key="gw-{{ $g->id }}">
                            <td class="px-3 py-2"><div class="font-medium">{{ $g->name }}</div><div class="text-xs text-neutral-500">{{ $g->facility?->name ?? __('All facilities') }}{{ $g->version ? ' · v'.$g->version : '' }}</div></td>
                            <td class="px-3 py-2">
                                @if (! $g->active)
                                    <flux:badge color="zinc">{{ __('Disabled') }}</flux:badge>
                                @elseif ($g->isOnline())
                                    <flux:badge color="green">{{ __('Online') }}</flux:badge>
                                @else
                                    <flux:badge color="amber">{{ $g->last_seen_at ? __('Last seen :t', ['t' => $g->last_seen_at->diffForHumans()]) : __('Never connected') }}</flux:badge>
                                @endif
                                @if ($g->last_ip) <div class="mt-0.5 text-xs text-neutral-500">{{ $g->last_ip }}</div> @endif
                            </td>
                            <td class="px-3 py-2">{{ $g->instruments_count }}</td>
                            <td class="px-3 py-2 font-mono text-xs">hgw_…{{ $g->token_hint }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <flux:button size="sm" variant="ghost" icon="arrow-path" wire:click="rotateToken({{ $g->id }})" wire:confirm="{{ __('Issue a new token? The old one stops working immediately.') }}">{{ __('Rotate token') }}</flux:button>
                                <flux:button size="sm" variant="ghost" wire:click="toggleGateway({{ $g->id }})">{{ $g->active ? __('Disable') : __('Enable') }}</flux:button>
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-neutral-500">{{ __('No gateway yet. Add one, then install the gateway program in the lab.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>

    {{-- Instruments --}}
    <section class="grid gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
        <div class="flex flex-wrap items-end justify-between gap-3">
            <div>
                <flux:heading level="2">{{ __('2. Analysers') }}</flux:heading>
                <flux:text class="text-sm">{{ __('Each analyser has a code that must match the "code" in the gateway config.') }}</flux:text>
            </div>
            <flux:button icon="plus" wire:click="newInstrument">{{ __('Add analyser') }}</flux:button>
        </div>

        @if ($showInstrumentForm)
            <form wire:submit="saveInstrument" class="grid gap-3 rounded-lg bg-neutral-50 p-4 dark:bg-neutral-800">
                <div class="grid gap-3 sm:grid-cols-4">
                    <flux:input wire:model="inst.name" :label="__('Name')" placeholder="Mindray BC-30s" />
                    <flux:input wire:model="inst.manufacturer" :label="__('Manufacturer')" placeholder="Mindray" />
                    <flux:input wire:model="inst.model" :label="__('Model')" placeholder="BC-30s" />
                    <flux:input wire:model="inst.code" :label="__('Code')" placeholder="BC30-LAB1" />
                </div>
                <div class="grid items-end gap-3 sm:grid-cols-4">
                    <flux:select wire:model="inst.protocol" :label="__('Protocol')">
                        @foreach (\App\Models\LabInstrument::PROTOCOLS as $k => $label)
                            <flux:select.option value="{{ $k }}">{{ $label }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:select wire:model="inst.lab_gateway_id" :label="__('Gateway')">
                        <flux:select.option value="">{{ __('None yet') }}</flux:select.option>
                        @foreach ($this->gateways as $g)
                            <flux:select.option value="{{ $g->id }}">{{ $g->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <flux:checkbox wire:model="inst.bidirectional" :label="__('Bidirectional (send orders to the analyser)')" />
                    <div class="flex gap-2">
                        <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                        <flux:button variant="ghost" wire:click="$set('showInstrumentForm', false)">{{ __('Cancel') }}</flux:button>
                    </div>
                </div>
            </form>
        @endif

        <div class="grid gap-2 md:grid-cols-2 xl:grid-cols-3">
            @forelse ($this->instruments as $i)
                <button type="button" wire:key="ins-{{ $i->id }}" wire:click="selectInstrument({{ $i->id }})"
                        class="rounded-lg border p-3 text-left text-sm transition {{ $instrumentId === $i->id ? 'border-[#4E7CFF] bg-[#4E7CFF]/5' : 'border-neutral-200 hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800' }}">
                    <div class="flex items-start justify-between gap-2">
                        <div>
                            <div class="font-semibold">{{ $i->name }}</div>
                            <div class="text-xs text-neutral-500">{{ $i->code }} · {{ strtoupper($i->protocol) }}{{ $i->bidirectional ? ' · '.__('bidirectional') : '' }} · {{ $i->gateway?->name ?? __('no gateway') }}</div>
                        </div>
                        <flux:badge size="sm" :color="$i->active ? 'green' : 'zinc'">{{ $i->active ? __('Active') : __('Off') }}</flux:badge>
                    </div>
                    <div class="mt-2 text-xs text-neutral-500">{{ trans_choice(':n code mapped|:n codes mapped', $i->mappings_count, ['n' => $i->mappings_count]) }} · {{ $i->last_message_at ? __('last result :t', ['t' => $i->last_message_at->diffForHumans()]) : __('no results yet') }}</div>
                </button>
            @empty
                <flux:text class="text-sm">{{ __('No analysers yet.') }}</flux:text>
            @endforelse
        </div>
    </section>

    {{-- Mappings --}}
    @if ($this->instrument)
        <section class="grid gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="flex flex-wrap items-end justify-between gap-3">
                <div>
                    <flux:heading level="2">{{ __('3. Code mapping — :name', ['name' => $this->instrument->name]) }}</flux:heading>
                    <flux:text class="text-sm">{{ __('Link each analyser code to an HMIS test. For panels such as FBC, give the component name (e.g. HGB → Full blood count / Haemoglobin).') }}</flux:text>
                </div>
                <div class="flex gap-2">
                    <flux:button size="sm" variant="ghost" icon="pencil-square" wire:click="editInstrument({{ $this->instrument->id }})">{{ __('Edit analyser') }}</flux:button>
                    <flux:button size="sm" variant="ghost" wire:click="toggleInstrument({{ $this->instrument->id }})">{{ $this->instrument->active ? __('Switch off') : __('Switch on') }}</flux:button>
                </div>
            </div>

            <div class="flex flex-wrap items-end gap-2 rounded-lg bg-neutral-50 p-3 dark:bg-neutral-800">
                <flux:select wire:model="preset" :label="__('Start from a preset')" class="w-80">
                    <flux:select.option value="">{{ __('Choose…') }}</flux:select.option>
                    @foreach (\App\Support\AnalyserPresets::all() as $k => $p)
                        <flux:select.option value="{{ $k }}">{{ $p['label'] }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:button wire:click="applyPreset" icon="sparkles">{{ __('Add preset codes') }}</flux:button>
                <flux:text class="text-xs">{{ __('Presets use common vendor codes; check them against your analyser\'s LIS manual.') }}</flux:text>
            </div>
            <flux:error name="preset" />

            <form wire:submit="addMapping" class="flex flex-wrap items-end gap-2">
                <flux:input wire:model="map.instrument_code" :label="__('Analyser code')" placeholder="HGB" class="w-36" />
                <flux:select wire:model="map.lab_test_id" :label="__('HMIS test')" class="w-72">
                    <flux:select.option value="">{{ __('Choose test') }}</flux:select.option>
                    @foreach ($this->tests as $t)
                        <flux:select.option value="{{ $t->id }}">{{ $t->name }} ({{ $t->code }})</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="map.component" :label="__('Component (panels only)')" placeholder="Haemoglobin" class="w-56" />
                <flux:button type="submit" icon="plus">{{ __('Add') }}</flux:button>
            </form>

            <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
                <table class="w-full text-left text-sm">
                    <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                        <tr><th class="px-3 py-2">{{ __('Analyser code') }}</th><th class="px-3 py-2">{{ __('HMIS test') }}</th><th class="px-3 py-2">{{ __('Component') }}</th><th class="px-3 py-2"></th></tr>
                    </thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @forelse ($this->mappings as $m)
                            <tr wire:key="map-{{ $m->id }}">
                                <td class="px-3 py-1.5 font-mono text-xs font-semibold">{{ $m->instrument_code }}</td>
                                <td class="px-3 py-1.5">{{ $m->test?->name }} <span class="text-xs text-neutral-500">{{ $m->test?->code }}</span></td>
                                <td class="px-3 py-1.5">{{ $m->component ?? '—' }}</td>
                                <td class="px-3 py-1.5 text-right"><flux:button size="xs" variant="ghost" icon="trash" wire:click="removeMapping({{ $m->id }})" /></td>
                            </tr>
                        @empty
                            <tr><td colspan="4" class="px-3 py-6 text-center text-neutral-500">{{ __('No codes mapped yet. Results from this analyser will be logged as unmatched until you map them.') }}</td></tr>
                        @endforelse
                    </tbody>
                </table>
            </div>
        </section>
    @endif

    {{-- Messages --}}
    <section class="grid gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700" data-live="lab">
        <flux:heading level="2">{{ __('Recent messages') }}</flux:heading>
        <div class="overflow-hidden rounded-lg border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-3 py-2">{{ __('When') }}</th><th class="px-3 py-2">{{ __('Analyser') }}</th><th class="px-3 py-2">{{ __('Type') }}</th><th class="px-3 py-2">{{ __('Sample') }}</th><th class="px-3 py-2">{{ __('Outcome') }}</th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->messages as $msg)
                        <tr wire:key="msg-{{ $msg->id }}" class="cursor-pointer hover:bg-neutral-50 dark:hover:bg-neutral-800" wire:click="toggleMessage({{ $msg->id }})">
                            <td class="px-3 py-2 whitespace-nowrap text-xs">{{ $msg->created_at->format('d M H:i:s') }}</td>
                            <td class="px-3 py-2">{{ $msg->instrument?->name ?? '—' }}</td>
                            <td class="px-3 py-2 text-xs">{{ $msg->direction === 'in' ? '←' : '→' }} {{ $msg->type }}</td>
                            <td class="px-3 py-2 font-mono text-xs">{{ $msg->sample_id ?? '—' }}</td>
                            <td class="px-3 py-2">
                                <flux:badge size="sm" :color="match ($msg->status) { 'accepted', 'sent' => 'green', 'partial' => 'amber', default => 'red' }">{{ $msg->status }}</flux:badge>
                                <span class="ml-1 text-xs text-neutral-500">{{ $msg->detail }}</span>
                            </td>
                        </tr>
                        @if ($openMessageId === $msg->id && $msg->payload)
                            <tr wire:key="msgp-{{ $msg->id }}"><td colspan="5" class="bg-neutral-50 px-3 py-2 dark:bg-neutral-900"><pre class="max-h-64 overflow-auto font-mono text-xs whitespace-pre-wrap">{{ $msg->payload }}</pre></td></tr>
                        @endif
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-neutral-500">{{ __('No messages yet.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
    </section>
</div>
