<?php

use App\Models\RadiologyExam;
use App\Services\Tenancy\TenantContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Imaging catalog')] class extends Component {
    public ?int $editingId = null;

    public bool $showForm = false;

    public array $form = ['code' => '', 'name' => '', 'modality' => 'xray', 'price' => '0', 'requires_preparation' => false, 'preparation_instructions' => '', 'sha_intervention_code' => ''];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('radiology.catalog.manage'), 403);
    }

    #[Computed]
    public function exams()
    {
        return RadiologyExam::orderBy('modality')->orderBy('name')->get();
    }

    public function edit(int $id): void
    {
        $e = RadiologyExam::findOrFail($id);
        $this->editingId = $e->id;
        $this->form = ['code' => $e->code, 'name' => $e->name, 'modality' => $e->modality, 'price' => (string) $e->price, 'requires_preparation' => $e->requires_preparation, 'preparation_instructions' => (string) $e->preparation_instructions, 'sha_intervention_code' => (string) $e->sha_intervention_code];
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->can('radiology.catalog.manage'), 403);

        $data = $this->validate([
            'form.code' => ['required', 'string', 'max:30', Rule::unique('radiology_exams', 'code')->where('tenant_id', app(TenantContext::class)->id())->ignore($this->editingId)],
            'form.name' => ['required', 'string', 'max:150'],
            'form.modality' => ['required', 'in:xray,ultrasound,ct,mri,mammography,fluoroscopy,ecg,echo'],
            'form.price' => ['required', 'numeric', 'min:0'],
            'form.requires_preparation' => ['boolean'],
            'form.preparation_instructions' => ['nullable', 'string', 'max:255'],
            'form.sha_intervention_code' => ['nullable', 'string', 'max:30', 'regex:/^SHA-[0-9A-Z\-]+$/i'],
        ], [], ['form.sha_intervention_code' => 'SHA intervention code'])['form'];
        $data['code'] = strtoupper($data['code']);
        $data['sha_intervention_code'] = $data['sha_intervention_code'] ? strtoupper($data['sha_intervention_code']) : null;

        $this->editingId ? RadiologyExam::findOrFail($this->editingId)->update($data) : RadiologyExam::create($data);

        Flux::toast(variant: 'success', text: __('Examination saved.'));
        $this->reset(['editingId', 'showForm', 'form']);
        unset($this->exams);
    }

    public function toggle(int $id): void
    {
        abort_unless(Auth::user()->can('radiology.catalog.manage'), 403);
        $e = RadiologyExam::findOrFail($id);
        $e->update(['active' => ! $e->active]);
        unset($this->exams);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Imaging catalog') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="$set('showForm', true)">{{ __('Add examination') }}</flux:button>
    </div>

    @if ($showForm)
        <form wire:submit="save" class="grid max-w-3xl gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="grid gap-3 sm:grid-cols-4">
                <flux:input wire:model="form.code" :label="__('Code')" />
                <div class="sm:col-span-2"><flux:input wire:model="form.name" :label="__('Name')" /></div>
                <flux:select wire:model="form.modality" :label="__('Modality')">
                    @foreach (['xray' => 'X-ray', 'ultrasound' => 'Ultrasound', 'ct' => 'CT', 'mri' => 'MRI', 'mammography' => 'Mammography', 'fluoroscopy' => 'Fluoroscopy', 'ecg' => 'ECG', 'echo' => 'Echo'] as $k => $l)
                        <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:input wire:model="form.price" type="number" step="0.01" :label="__('Price')" />
                <flux:checkbox wire:model="form.requires_preparation" :label="__('Requires preparation')" />
                <flux:input wire:model="form.preparation_instructions" :label="__('Preparation instructions')" />
            </div>
            <flux:input wire:model="form.sha_intervention_code" :label="__('SHA intervention code')" placeholder="SHA-12-003" class="max-w-xs" :description="__('Used when this examination is claimed from SHA.')" />
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                <tr><th class="px-3 py-2">{{ __('Code') }}</th><th class="px-3 py-2">{{ __('Name') }}</th><th class="px-3 py-2">{{ __('Modality') }}</th><th class="px-3 py-2 text-right">{{ __('Price') }}</th><th class="px-3 py-2">{{ __('Status') }}</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @foreach ($this->exams as $e)
                    <tr wire:key="x-{{ $e->id }}">
                        <td class="px-3 py-2 font-mono text-xs">{{ $e->code }}</td>
                        <td class="px-3 py-2">{{ $e->name }}</td>
                        <td class="px-3 py-2">{{ strtoupper($e->modality) }}</td>
                        <td class="px-3 py-2 text-right"><x-money :amount="$e->price" /></td>
                        <td class="px-3 py-2"><flux:badge size="sm" :color="$e->active ? 'lime' : 'zinc'">{{ $e->active ? __('Active') : __('Inactive') }}</flux:badge></td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <flux:button size="xs" variant="ghost" wire:click="edit({{ $e->id }})">{{ __('Edit') }}</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="toggle({{ $e->id }})">{{ $e->active ? __('Deactivate') : __('Activate') }}</flux:button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
