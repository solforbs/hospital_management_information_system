<?php

use App\Models\LabTest;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Lab test catalog')] class extends Component {
    public ?int $editingId = null;

    public bool $showForm = false;

    public array $form = [];

    public string $search = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('lab.catalog.manage'), 403);
        $this->blank();
    }

    protected function blank(): void
    {
        $this->form = ['code' => '', 'name' => '', 'category' => '', 'specimen_type' => 'blood', 'unit' => '', 'reference_low' => '', 'reference_high' => '', 'reference_text' => '', 'result_type' => 'numeric', 'price' => '0', 'turnaround_minutes' => '60'];
    }

    #[Computed]
    public function tests()
    {
        return LabTest::when($this->search, fn ($q) => $q->where('name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%"))
            ->orderBy('category')->orderBy('name')->get();
    }

    public function edit(int $id): void
    {
        $test = LabTest::findOrFail($id);
        $this->editingId = $test->id;
        $this->form = array_map(fn ($v) => (string) $v, $test->only(array_keys($this->form)));
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->can('lab.catalog.manage'), 403);

        $tenantId = app(\App\Services\Tenancy\TenantContext::class)->id();
        $data = $this->validate([
            'form.code' => ['required', 'string', 'max:30', Rule::unique('lab_tests', 'code')->where('tenant_id', $tenantId)->ignore($this->editingId)],
            'form.name' => ['required', 'string', 'max:150'],
            'form.category' => ['nullable', 'string', 'max:60'],
            'form.specimen_type' => ['required', 'string', 'max:40'],
            'form.unit' => ['nullable', 'string', 'max:30'],
            'form.reference_low' => ['nullable', 'numeric'],
            'form.reference_high' => ['nullable', 'numeric', 'gte:form.reference_low'],
            'form.reference_text' => ['nullable', 'string', 'max:100'],
            'form.result_type' => ['required', 'in:numeric,text'],
            'form.price' => ['required', 'numeric', 'min:0'],
            'form.turnaround_minutes' => ['required', 'integer', 'min:1'],
        ])['form'];

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $data['code'] = strtoupper($data['code']);

        $this->editingId ? LabTest::findOrFail($this->editingId)->update($data) : LabTest::create($data);

        Flux::toast(variant: 'success', text: __('Test saved.'));
        $this->reset(['editingId', 'showForm']);
        $this->blank();
        unset($this->tests);
    }

    public function toggle(int $id): void
    {
        abort_unless(Auth::user()->can('lab.catalog.manage'), 403);
        $t = LabTest::findOrFail($id);
        $t->update(['active' => ! $t->active]);
        unset($this->tests);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Lab test catalog') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="$set('showForm', true)">{{ __('Add test') }}</flux:button>
    </div>

    @if ($showForm)
        <form wire:submit="save" class="grid max-w-4xl gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="grid gap-3 sm:grid-cols-4">
                <flux:input wire:model="form.code" :label="__('Code')" />
                <div class="sm:col-span-2"><flux:input wire:model="form.name" :label="__('Name')" /></div>
                <flux:input wire:model="form.category" :label="__('Category')" />
            </div>
            <div class="grid gap-3 sm:grid-cols-4">
                <flux:input wire:model="form.specimen_type" :label="__('Specimen')" />
                <flux:select wire:model="form.result_type" :label="__('Result type')">
                    <flux:select.option value="numeric">{{ __('Numeric') }}</flux:select.option>
                    <flux:select.option value="text">{{ __('Text') }}</flux:select.option>
                </flux:select>
                <flux:input wire:model="form.unit" :label="__('Unit')" />
                <flux:input wire:model="form.turnaround_minutes" type="number" :label="__('TAT (min)')" />
            </div>
            <div class="grid gap-3 sm:grid-cols-4">
                <flux:input wire:model="form.reference_low" type="number" step="any" :label="__('Ref. low')" />
                <flux:input wire:model="form.reference_high" type="number" step="any" :label="__('Ref. high')" />
                <flux:input wire:model="form.reference_text" :label="__('Ref. text')" />
                <flux:input wire:model="form.price" type="number" step="0.01" :label="__('Price')" />
            </div>
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search')" class="max-w-sm" />

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                <tr><th class="px-3 py-2">{{ __('Code') }}</th><th class="px-3 py-2">{{ __('Name') }}</th><th class="px-3 py-2">{{ __('Category') }}</th><th class="px-3 py-2">{{ __('Reference') }}</th><th class="px-3 py-2 text-right">{{ __('Price') }}</th><th class="px-3 py-2">{{ __('Status') }}</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @foreach ($this->tests as $t)
                    <tr wire:key="t-{{ $t->id }}">
                        <td class="px-3 py-2 font-mono text-xs">{{ $t->code }}</td>
                        <td class="px-3 py-2">{{ $t->name }}</td>
                        <td class="px-3 py-2">{{ $t->category }}</td>
                        <td class="px-3 py-2">{{ $t->referenceRange() ?? '—' }}</td>
                        <td class="px-3 py-2 text-right"><x-money :amount="$t->price" /></td>
                        <td class="px-3 py-2"><flux:badge size="sm" :color="$t->active ? 'lime' : 'zinc'">{{ $t->active ? __('Active') : __('Inactive') }}</flux:badge></td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <flux:button size="xs" variant="ghost" wire:click="edit({{ $t->id }})">{{ __('Edit') }}</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="toggle({{ $t->id }})">{{ $t->active ? __('Deactivate') : __('Activate') }}</flux:button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
