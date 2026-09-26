<?php

use App\Models\Facility;
use App\Services\Tenancy\TenantContext;
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

        $tenant->facilities()->create($validated);

        $this->reset(['name', 'code', 'type', 'phone', 'county', 'showForm']);
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
            <div class="flex gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save facility') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Code') }}</th>
                    <th class="px-4 py-3">{{ __('Type') }}</th>
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
                        <td class="px-4 py-3">
                            <flux:badge :color="$facility->active ? 'lime' : 'zinc'">
                                {{ $facility->active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3 text-right">
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
