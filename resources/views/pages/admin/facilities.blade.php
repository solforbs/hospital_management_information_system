<?php

use App\Models\Facility;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\TenantContext;
use App\Support\FacilityLevel;
use App\Support\ModuleRegistry;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Facilities')] class extends Component {
    public bool $showForm = false;

    public string $name = '';

    public string $code = '';

    public string $type = 'branch';

    public string $phone = '';

    public string $county = '';

    public string $level = '';

    public ?int $configuringId = null;

    public string $configLevel = '';

    public string $configKhis = '';

    /** @var array<string, bool> */
    public array $modules = [];

    public function configure(int $id): void
    {
        abort_unless(Auth::user()->can('facilities.manage'), 403);
        $facility = Facility::findOrFail($id);
        $this->configuringId = $facility->id;
        $this->configLevel = (string) ($facility->level ?? '');
        $this->configKhis = (string) data_get($facility->settings, 'khis_org_unit', '');
        $this->loadModules($facility);
    }

    public function updatedConfigLevel(): void
    {
        // Changing the level resets toggles to that level's defaults.
        $level = $this->configLevel !== '' ? (int) $this->configLevel : null;
        foreach (ModuleRegistry::toggleable() as $m) {
            $this->modules[$m['key']] = ModuleRegistry::defaultAtLevel($m, $level);
        }
    }

    protected function loadModules(Facility $facility): void
    {
        $this->modules = [];
        foreach (ModuleRegistry::toggleable() as $m) {
            $this->modules[$m['key']] = ModuleRegistry::enabledAt($facility, $m['key']);
        }
    }

    public function saveConfiguration(AuditLogger $audit): void
    {
        abort_unless(Auth::user()->can('facilities.manage'), 403);
        $this->validate(['configLevel' => ['nullable', 'integer', 'between:1,6'], 'configKhis' => ['nullable', 'string', 'max:20', 'regex:/^[A-Za-z0-9]+$/']]);

        $facility = Facility::findOrFail($this->configuringId);
        $level = $this->configLevel !== '' ? (int) $this->configLevel : null;

        // Store only deviations from the level defaults, so a later level
        // change still brings sensible defaults for untouched modules.
        $overrides = [];
        foreach (ModuleRegistry::toggleable() as $m) {
            $wanted = (bool) ($this->modules[$m['key']] ?? false);
            if ($wanted !== ModuleRegistry::defaultAtLevel($m, $level)) {
                $overrides[$m['key']] = $wanted;
            }
        }

        $before = ['level' => $facility->level, 'modules' => $facility->enabled_modules];
        $settings = $facility->settings ?? [];
        $settings['khis_org_unit'] = $this->configKhis ?: null;
        $facility->update(['level' => $level, 'enabled_modules' => $overrides ?: null, 'settings' => $settings]);
        $audit->log('facility.services_configured', $facility, ['before' => $before, 'after' => ['level' => $level, 'modules' => $overrides]]);

        $this->configuringId = null;
        Flux::toast(variant: 'success', text: __('Facility level and services saved.'));
    }

    public function mount(): void
    {
        abort_unless(Auth::user()->can('facilities.manage'), 403);
    }

    public function getFacilitiesProperty()
    {
        return Facility::orderBy('name')->get();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:20', 'alpha_dash'],
            'type' => ['required', 'in:main,branch,clinic,satellite'],
            'phone' => ['nullable', 'string', 'max:30'],
            'county' => ['nullable', 'string', 'max:100'],
            'level' => ['nullable', 'integer', 'between:1,6'],
        ];
    }

    public function create(): void
    {
        abort_unless(Auth::user()->can('facilities.manage'), 403);

        $validated = $this->validate();

        $tenant = app(TenantContext::class)->tenant();
        abort_unless($tenant, 403);

        if (Facility::where('tenant_id', $tenant->id)->where('code', $validated['code'])->exists()) {
            $this->addError('code', __('This facility code is already in use.'));

            return;
        }

        $validated['level'] = $validated['level'] !== '' && $validated['level'] !== null ? (int) $validated['level'] : null;
        $tenant->facilities()->create($validated);

        $this->reset(['name', 'code', 'type', 'phone', 'county', 'level', 'showForm']);
    }

    public function toggleActive(Facility $facility): void
    {
        abort_unless(Auth::user()->can('facilities.manage'), 403);

        $facility->update(['active' => ! $facility->active]);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Facilities') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="$set('showForm', true)">
            {{ __('Add facility') }}
        </flux:button>
    </div>

    @if ($showForm)
        <form wire:submit="create" class="grid max-w-2xl gap-4 rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" required autofocus />
                <flux:input wire:model="code" :label="__('Code')" required />
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:select wire:model="type" :label="__('Type')">
                    <flux:select.option value="main">{{ __('Main') }}</flux:select.option>
                    <flux:select.option value="branch">{{ __('Branch') }}</flux:select.option>
                    <flux:select.option value="clinic">{{ __('Clinic') }}</flux:select.option>
                    <flux:select.option value="satellite">{{ __('Satellite') }}</flux:select.option>
                </flux:select>
                <flux:input wire:model="phone" :label="__('Phone')" />
                <flux:input wire:model="county" :label="__('County')" />
            </div>
            <flux:select wire:model="level" :label="__('KEPH level')">
                <flux:select.option value="">{{ __('Not set (all services)') }}</flux:select.option>
                @foreach (FacilityLevel::options() as $k => $l)
                    <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save facility') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    @if ($configuringId)
        <form wire:submit="saveConfiguration" class="grid gap-4 rounded-xl border border-indigo-300 p-5 dark:border-indigo-800">
            <div class="flex items-center justify-between">
                <flux:heading level="3">{{ __('Level & services') }} — {{ $this->facilities->firstWhere('id', $configuringId)?->name }}</flux:heading>
                <flux:button size="sm" variant="ghost" wire:click="$set('configuringId', null)">{{ __('Close') }}</flux:button>
            </div>
            <flux:select wire:model.live="configLevel" :label="__('KEPH level')" class="max-w-md" :description="__('Changing the level resets the services below to that level\'s defaults.')">
                <flux:select.option value="">{{ __('Not set (all services)') }}</flux:select.option>
                @foreach (FacilityLevel::options() as $k => $l)
                    <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                @endforeach
            </flux:select>
            <flux:input wire:model="configKhis" :label="__('KHIS (DHIS2) org unit UID')" class="max-w-md" :description="__('Used when exporting MOH reports for KHIS.')" />
            <div class="grid gap-2 sm:grid-cols-2 lg:grid-cols-3">
                @foreach (ModuleRegistry::toggleable() as $m)
                    <label wire:key="mod-{{ $m['key'] }}" class="flex items-center justify-between gap-2 rounded-lg border border-neutral-200 px-3 py-2 text-sm dark:border-neutral-700">
                        <span>{{ __($m['label']) }} <span class="text-xs text-neutral-500">{{ __($m['group']) }} · L{{ $m['min_level'] }}+</span></span>
                        <input type="checkbox" wire:model="modules.{{ $m['key'] }}" class="rounded" />
                    </label>
                @endforeach
            </div>
            <div><flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button></div>
        </form>
    @endif

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Code') }}</th>
                    <th class="px-4 py-3">{{ __('Type') }}</th>
                    <th class="px-4 py-3">{{ __('Level') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @foreach ($this->facilities as $facility)
                    <tr wire:key="facility-{{ $facility->id }}">
                        <td class="px-4 py-3">
                            {{ $facility->name }}
                            @if ($facility->is_main)
                                <flux:badge size="sm">{{ __('Main') }}</flux:badge>
                            @endif
                        </td>
                        <td class="px-4 py-3 font-mono text-xs">{{ $facility->code }}</td>
                        <td class="px-4 py-3">{{ ucfirst($facility->type) }}</td>
                        <td class="px-4 py-3 text-xs">{{ FacilityLevel::short($facility->level) }}@if ($facility->enabled_modules)<div class="text-neutral-500">{{ trans_choice(':count custom service setting|:count custom service settings', count($facility->enabled_modules)) }}</div>@endif</td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$facility->active ? 'lime' : 'zinc'">
                                {{ $facility->active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3 text-right whitespace-nowrap">
                            <flux:button size="sm" variant="ghost" wire:click="configure({{ $facility->id }})">{{ __('Level & services') }}</flux:button>
                            <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $facility->id }})">
                                {{ $facility->active ? __('Deactivate') : __('Activate') }}
                            </flux:button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
