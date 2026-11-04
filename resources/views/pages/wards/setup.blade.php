<?php

use App\Models\Bed;
use App\Models\Facility;
use App\Models\Ward;
use App\Services\Tenancy\TenantContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Wards & beds')] class extends Component {
    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = ['name' => '', 'code' => '', 'type' => 'general', 'gender' => 'mixed', 'daily_rate' => '0', 'facility_id' => '', 'sha_intervention_code' => ''];

    public const TYPES = ['general' => 'General', 'icu' => 'ICU', 'hdu' => 'HDU', 'nicu' => 'NICU / newborn unit', 'maternity' => 'Maternity', 'paediatric' => 'Paediatric', 'surgical' => 'Surgical', 'isolation' => 'Isolation', 'private' => 'Private'];

    public array $bedCounts = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('wards.manage'), 403);
        $this->form['facility_id'] = (string) app(TenantContext::class)->facilityId();
    }

    #[Computed]
    public function wards()
    {
        return Ward::with('facility')->withCount(['beds', 'beds as occupied_count' => fn ($q) => $q->where('status', 'occupied')])->orderBy('name')->get();
    }

    #[Computed]
    public function facilities()
    {
        return Facility::where('active', true)->orderBy('name')->get();
    }

    public function edit(int $id): void
    {
        $w = Ward::findOrFail($id);
        $this->editingId = $w->id;
        $this->form = ['name' => $w->name, 'code' => $w->code, 'type' => $w->type, 'gender' => $w->gender, 'daily_rate' => (string) $w->daily_rate, 'facility_id' => (string) $w->facility_id, 'sha_intervention_code' => (string) $w->sha_intervention_code];
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->can('wards.manage'), 403);

        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:100'],
            'form.code' => ['required', 'string', 'max:20', Rule::unique('wards', 'code')->where('tenant_id', app(TenantContext::class)->id())->ignore($this->editingId)],
            'form.type' => ['required', 'in:'.implode(',', array_keys(self::TYPES))],
            'form.gender' => ['required', 'in:male,female,mixed'],
            'form.daily_rate' => ['required', 'numeric', 'min:0'],
            'form.facility_id' => ['required', 'integer'],
            'form.sha_intervention_code' => ['nullable', 'string', 'max:30', 'regex:/^SHA-[0-9A-Z\-]+$/i'],
        ], [], ['form.sha_intervention_code' => 'SHA intervention code'])['form'];

        $facility = Facility::findOrFail($data['facility_id']);
        $data['facility_id'] = $facility->id;
        $data['code'] = strtoupper($data['code']);
        $data['sha_intervention_code'] = $data['sha_intervention_code'] ? strtoupper($data['sha_intervention_code']) : null;

        // Critical-care units are expected at higher KEPH levels.
        $minLevel = \App\Support\FacilityLevel::CRITICAL_CARE_MIN_LEVEL[$data['type']] ?? null;
        if ($minLevel && $facility->level && $facility->level < $minLevel) {
            $this->addError('form.type', __(':type units are offered from Level :min. :facility is :level — update the facility level first if this is correct.', [
                'type' => self::TYPES[$data['type']], 'min' => $minLevel, 'facility' => $facility->name, 'level' => 'Level '.$facility->level,
            ]));

            return;
        }

        $this->editingId ? Ward::findOrFail($this->editingId)->update($data) : Ward::create($data);

        Flux::toast(variant: 'success', text: __('Ward saved.'));
        $this->reset(['showForm', 'editingId']);
        unset($this->wards);
    }

    public function addBeds(int $wardId): void
    {
        abort_unless(Auth::user()->can('wards.manage'), 403);
        $this->validate(["bedCounts.$wardId" => ['required', 'integer', 'min:1', 'max:100']]);

        $ward = Ward::findOrFail($wardId);
        $existing = $ward->beds()->count();

        for ($i = 1; $i <= (int) $this->bedCounts[$wardId]; $i++) {
            $number = $ward->code.'-'.str_pad((string) ($existing + $i), 2, '0', STR_PAD_LEFT);
            Bed::firstOrCreate(['ward_id' => $ward->id, 'bed_number' => $number], ['status' => 'available']);
        }

        unset($this->bedCounts[$wardId], $this->wards);
        Flux::toast(variant: 'success', text: __('Beds added.'));
    }

    public function toggle(int $id): void
    {
        abort_unless(Auth::user()->can('wards.manage'), 403);
        $w = Ward::findOrFail($id);
        $w->update(['active' => ! $w->active]);
        unset($this->wards);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Wards & beds') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="$set('showForm', true)">{{ __('Add ward') }}</flux:button>
    </div>

    @if ($showForm)
        <form wire:submit="save" class="grid max-w-3xl gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:input wire:model="form.name" :label="__('Name')" />
                <flux:input wire:model="form.code" :label="__('Code')" />
                <flux:select wire:model="form.facility_id" :label="__('Facility')">
                    @foreach ($this->facilities as $f)
                        <flux:select.option value="{{ $f->id }}">{{ $f->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:select wire:model="form.type" :label="__('Type')">
                    @foreach ($this::TYPES as $t => $label)
                        <flux:select.option value="{{ $t }}">{{ __($label) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="form.gender" :label="__('Gender')">
                    <flux:select.option value="mixed">{{ __('Mixed') }}</flux:select.option>
                    <flux:select.option value="male">{{ __('Male') }}</flux:select.option>
                    <flux:select.option value="female">{{ __('Female') }}</flux:select.option>
                </flux:select>
                <flux:input wire:model="form.daily_rate" type="number" step="0.01" :label="__('Daily bed rate')" />
            </div>
            <flux:input wire:model="form.sha_intervention_code" :label="__('SHA intervention code (per-diem)')" placeholder="SHA-03-001" class="max-w-xs" :description="__('ICU, HDU and NICU days are claimed from SHA per day under this code.')" />
            @error('form.type')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                <tr><th class="px-3 py-2">{{ __('Ward') }}</th><th class="px-3 py-2">{{ __('Type') }}</th><th class="px-3 py-2 text-right">{{ __('Rate/day') }}</th><th class="px-3 py-2">{{ __('Beds') }}</th><th class="px-3 py-2">{{ __('Add beds') }}</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse ($this->wards as $w)
                    <tr wire:key="w-{{ $w->id }}" class="{{ $w->active ? '' : 'opacity-50' }}">
                        <td class="px-3 py-2">{{ $w->name }} <span class="font-mono text-xs text-neutral-500">{{ $w->code }}</span><div class="text-xs text-neutral-500">{{ $w->facility->name }}</div></td>
                        <td class="px-3 py-2">{{ ucfirst($w->type) }} · {{ $w->gender }}</td>
                        <td class="px-3 py-2 text-right"><x-money :amount="$w->daily_rate" /></td>
                        <td class="px-3 py-2">{{ $w->occupied_count }}/{{ $w->beds_count }} {{ __('occupied') }}</td>
                        <td class="px-3 py-2">
                            <div class="flex items-center gap-2">
                                <flux:input size="sm" type="number" min="1" wire:model="bedCounts.{{ $w->id }}" class="w-20" />
                                <flux:button size="xs" wire:click="addBeds({{ $w->id }})">{{ __('Add') }}</flux:button>
                            </div>
                        </td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <flux:button size="xs" variant="ghost" wire:click="edit({{ $w->id }})">{{ __('Edit') }}</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="toggle({{ $w->id }})">{{ $w->active ? __('Deactivate') : __('Activate') }}</flux:button>
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="6" class="px-3 py-8 text-center text-neutral-500">{{ __('No wards configured.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
</div>
