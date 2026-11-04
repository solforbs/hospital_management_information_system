<?php

use App\Models\ServiceItem;
use App\Services\Tenancy\TenantContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Service tariffs')] class extends Component {
    public ?int $editingId = null;

    public bool $showForm = false;

    public array $form = ['code' => '', 'name' => '', 'category' => 'consultation', 'price' => '0'];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('billing.catalog.manage'), 403);
    }

    #[Computed]
    public function services()
    {
        return ServiceItem::orderBy('category')->orderBy('name')->get();
    }

    public function edit(int $id): void
    {
        $s = ServiceItem::findOrFail($id);
        $this->editingId = $s->id;
        $this->form = ['code' => $s->code, 'name' => $s->name, 'category' => $s->category, 'price' => (string) $s->price];
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->can('billing.catalog.manage'), 403);

        $data = $this->validate([
            'form.code' => ['required', 'string', 'max:30', Rule::unique('service_items', 'code')->where('tenant_id', app(TenantContext::class)->id())->ignore($this->editingId)],
            'form.name' => ['required', 'string', 'max:150'],
            'form.category' => ['required', 'in:consultation,registration,procedure,bed,nursing,emergency,other'],
            'form.price' => ['required', 'numeric', 'min:0'],
        ])['form'];
        $data['code'] = strtoupper($data['code']);

        if ($this->editingId) {
            $service = ServiceItem::findOrFail($this->editingId);
            app(\App\Services\Audit\AuditLogger::class)->log('billing.tariff_changed', $service, ['from' => $service->price, 'to' => $data['price']]);
            $service->update($data);
        } else {
            ServiceItem::create($data);
        }

        Flux::toast(variant: 'success', text: __('Tariff saved.'));
        $this->reset(['editingId', 'showForm', 'form']);
        unset($this->services);
    }

    public function toggle(int $id): void
    {
        abort_unless(Auth::user()->can('billing.catalog.manage'), 403);
        $s = ServiceItem::findOrFail($id);
        $s->update(['active' => ! $s->active]);
        unset($this->services);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Service tariffs') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="$set('showForm', true)">{{ __('Add service') }}</flux:button>
    </div>
    <flux:text>{{ __('Codes REG-FEE, ER-FEE, CONSULT and BED-DAY are charged automatically at check-in, emergency registration, consultation start and discharge (when the ward has no daily rate).') }}</flux:text>

    @if ($showForm)
        <form wire:submit="save" class="grid max-w-3xl gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="grid gap-3 sm:grid-cols-4">
                <flux:input wire:model="form.code" :label="__('Code')" />
                <div class="sm:col-span-2"><flux:input wire:model="form.name" :label="__('Name')" /></div>
                <flux:input wire:model="form.price" type="number" step="0.01" :label="__('Price')" />
            </div>
            <flux:select wire:model="form.category" :label="__('Category')" class="max-w-xs">
                @foreach (['consultation', 'registration', 'procedure', 'bed', 'nursing', 'emergency', 'other'] as $c)
                    <flux:select.option value="{{ $c }}">{{ ucfirst($c) }}</flux:select.option>
                @endforeach
            </flux:select>
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                <tr><th class="px-3 py-2">{{ __('Code') }}</th><th class="px-3 py-2">{{ __('Service') }}</th><th class="px-3 py-2">{{ __('Category') }}</th><th class="px-3 py-2 text-right">{{ __('Price') }}</th><th class="px-3 py-2">{{ __('Status') }}</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @foreach ($this->services as $s)
                    <tr wire:key="s-{{ $s->id }}">
                        <td class="px-3 py-2 font-mono text-xs">{{ $s->code }}</td>
                        <td class="px-3 py-2">{{ $s->name }}</td>
                        <td class="px-3 py-2">{{ ucfirst($s->category) }}</td>
                        <td class="px-3 py-2 text-right"><x-money :amount="$s->price" /></td>
                        <td class="px-3 py-2"><flux:badge size="sm" :color="$s->active ? 'lime' : 'zinc'">{{ $s->active ? __('Active') : __('Inactive') }}</flux:badge></td>
                        <td class="px-3 py-2 text-right whitespace-nowrap">
                            <flux:button size="xs" variant="ghost" wire:click="edit({{ $s->id }})">{{ __('Edit') }}</flux:button>
                            <flux:button size="xs" variant="ghost" wire:click="toggle({{ $s->id }})">{{ $s->active ? __('Deactivate') : __('Activate') }}</flux:button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
