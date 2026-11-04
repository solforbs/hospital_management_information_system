<?php

use App\Models\Medicine;
use App\Models\MedicineBatch;
use App\Services\Pharmacy\PharmacyService;
use App\Services\Tenancy\TenantContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Medicines & stock')] class extends Component {
    use WithPagination;

    public string $search = '';

    public string $filter = 'all';

    public bool $showForm = false;

    public ?int $editingId = null;

    public array $form = [];

    public ?int $stockMedicineId = null;

    public array $receipt = ['batch_number' => '', 'expiry_date' => '', 'quantity' => '', 'unit_cost' => '', 'supplier' => ''];

    public ?int $adjustBatchId = null;

    public int $adjust_quantity = 0;

    public string $adjust_type = 'adjustment';

    public string $adjust_reason = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['pharmacy.stock.manage', 'inventory.manage']), 403);
        $this->blank();
    }

    protected function blank(): void
    {
        $this->form = ['code' => '', 'generic_name' => '', 'brand_name' => '', 'form' => 'tablet', 'strength' => '', 'unit' => 'tablet', 'unit_price' => '0', 'reorder_level' => '0', 'is_controlled' => false];
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function medicines()
    {
        $today = now()->toDateString();

        return Medicine::query()
            ->withSum(['batches as stock' => fn ($q) => $q->whereDate('expiry_date', '>=', $today)], 'quantity_available')
            ->withMin(['batches as next_expiry' => fn ($q) => $q->where('quantity_available', '>', 0)->whereDate('expiry_date', '>=', $today)], 'expiry_date')
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w->where('generic_name', 'like', "%{$this->search}%")->orWhere('brand_name', 'like', "%{$this->search}%")->orWhere('code', 'like', "%{$this->search}%")))
            ->orderBy('generic_name')
            ->get()
            ->when($this->filter === 'low', fn ($c) => $c->filter(fn ($m) => (int) $m->stock <= $m->reorder_level))
            ->when($this->filter === 'expiring', fn ($c) => $c->filter(fn ($m) => $m->next_expiry && \Illuminate\Support\Carbon::parse($m->next_expiry)->lte(now()->addDays(90))));
    }

    #[Computed]
    public function stockMedicine()
    {
        return $this->stockMedicineId ? Medicine::with(['batches' => fn ($q) => $q->orderBy('expiry_date')])->find($this->stockMedicineId) : null;
    }

    public function edit(int $id): void
    {
        $m = Medicine::findOrFail($id);
        $this->editingId = $m->id;
        $this->form = ['code' => $m->code, 'generic_name' => $m->generic_name, 'brand_name' => (string) $m->brand_name, 'form' => $m->form, 'strength' => (string) $m->strength, 'unit' => $m->unit, 'unit_price' => (string) $m->unit_price, 'reorder_level' => (string) $m->reorder_level, 'is_controlled' => $m->is_controlled];
        $this->showForm = true;
    }

    public function save(): void
    {
        abort_unless(Auth::user()->can('pharmacy.stock.manage'), 403);

        $data = $this->validate([
            'form.code' => ['required', 'string', 'max:30', Rule::unique('medicines', 'code')->where('tenant_id', app(TenantContext::class)->id())->ignore($this->editingId)],
            'form.generic_name' => ['required', 'string', 'max:150'],
            'form.brand_name' => ['nullable', 'string', 'max:150'],
            'form.form' => ['required', 'in:tablet,capsule,syrup,suspension,injection,infusion,cream,ointment,drops,inhaler,suppository,sachet'],
            'form.strength' => ['nullable', 'string', 'max:50'],
            'form.unit' => ['required', 'string', 'max:30'],
            'form.unit_price' => ['required', 'numeric', 'min:0'],
            'form.reorder_level' => ['required', 'integer', 'min:0'],
            'form.is_controlled' => ['boolean'],
        ])['form'];
        $data['code'] = strtoupper($data['code']);

        $this->editingId ? Medicine::findOrFail($this->editingId)->update($data) : Medicine::create($data);

        Flux::toast(variant: 'success', text: __('Medicine saved.'));
        $this->reset(['showForm', 'editingId']);
        $this->blank();
        unset($this->medicines);
    }

    public function openStock(int $id): void
    {
        $this->stockMedicineId = Medicine::findOrFail($id)->id;
        $this->receipt = ['batch_number' => '', 'expiry_date' => '', 'quantity' => '', 'unit_cost' => '', 'supplier' => ''];
        $this->adjustBatchId = null;
    }

    public function receive(PharmacyService $pharmacy): void
    {
        abort_unless(Auth::user()->can('pharmacy.stock.manage'), 403);

        $data = $this->validate([
            'receipt.batch_number' => ['required', 'string', 'max:50'],
            'receipt.expiry_date' => ['required', 'date', 'after:today'],
            'receipt.quantity' => ['required', 'integer', 'min:1'],
            'receipt.unit_cost' => ['nullable', 'numeric', 'min:0'],
            'receipt.supplier' => ['nullable', 'string', 'max:150'],
        ])['receipt'];

        $pharmacy->receiveStock(Medicine::findOrFail($this->stockMedicineId), $data);

        Flux::toast(variant: 'success', text: __('Stock received.'));
        $this->receipt = ['batch_number' => '', 'expiry_date' => '', 'quantity' => '', 'unit_cost' => '', 'supplier' => ''];
        unset($this->medicines, $this->stockMedicine);
    }

    public function adjust(PharmacyService $pharmacy): void
    {
        abort_unless(Auth::user()->can('pharmacy.stock.manage'), 403);

        $this->validate([
            'adjust_quantity' => ['required', 'integer', 'not_in:0'],
            'adjust_type' => ['required', 'in:adjustment,return,expiry_writeoff'],
            'adjust_reason' => ['required', 'string', 'max:255'],
        ]);

        $batch = MedicineBatch::where('medicine_id', $this->stockMedicineId)->findOrFail($this->adjustBatchId);
        $pharmacy->adjust($batch, $this->adjust_quantity, $this->adjust_type, $this->adjust_reason);

        Flux::toast(variant: 'success', text: __('Stock adjusted.'));
        $this->reset(['adjustBatchId', 'adjust_quantity', 'adjust_reason']);
        unset($this->medicines, $this->stockMedicine);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Medicines & stock') }}</flux:heading>
        @can('pharmacy.stock.manage')
            <flux:button variant="primary" icon="plus" wire:click="$set('showForm', true)">{{ __('Add medicine') }}</flux:button>
        @endcan
    </div>

    @if ($showForm)
        <form wire:submit="save" class="grid max-w-4xl gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <div class="grid gap-3 sm:grid-cols-4">
                <flux:input wire:model="form.code" :label="__('Code')" />
                <flux:input wire:model="form.generic_name" :label="__('Generic name')" />
                <flux:input wire:model="form.brand_name" :label="__('Brand name')" />
                <flux:input wire:model="form.strength" :label="__('Strength')" placeholder="500mg" />
            </div>
            <div class="grid gap-3 sm:grid-cols-5">
                <flux:select wire:model="form.form" :label="__('Form')">
                    @foreach (['tablet', 'capsule', 'syrup', 'suspension', 'injection', 'infusion', 'cream', 'ointment', 'drops', 'inhaler', 'suppository', 'sachet'] as $f)
                        <flux:select.option value="{{ $f }}">{{ ucfirst($f) }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="form.unit" :label="__('Dispensing unit')" />
                <flux:input wire:model="form.unit_price" type="number" step="0.01" :label="__('Unit price')" />
                <flux:input wire:model="form.reorder_level" type="number" :label="__('Reorder level')" />
                <flux:checkbox wire:model="form.is_controlled" :label="__('Controlled drug')" />
            </div>
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    <div class="flex flex-wrap gap-3">
        <flux:input wire:model.live.debounce.300ms="search" icon="magnifying-glass" :placeholder="__('Search')" class="max-w-sm" />
        @foreach (['all' => __('All'), 'low' => __('Below reorder level'), 'expiring' => __('Expiring ≤ 90 days')] as $k => $l)
            <flux:button size="sm" :variant="$filter === $k ? 'primary' : 'ghost'" wire:click="$set('filter', '{{ $k }}')">{{ $l }}</flux:button>
        @endforeach
    </div>

    <div class="grid gap-6 xl:grid-cols-3">
        <div class="overflow-hidden rounded-xl border border-neutral-200 xl:col-span-2 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr><th class="px-3 py-2">{{ __('Medicine') }}</th><th class="px-3 py-2 text-right">{{ __('Price') }}</th><th class="px-3 py-2 text-right">{{ __('In stock') }}</th><th class="px-3 py-2">{{ __('Next expiry') }}</th><th></th></tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->medicines as $m)
                        <tr wire:key="m-{{ $m->id }}">
                            <td class="px-3 py-2">
                                {{ $m->displayName() }} @if ($m->brand_name) <span class="text-neutral-500">({{ $m->brand_name }})</span> @endif
                                <div class="font-mono text-xs text-neutral-500">{{ $m->code }} @if ($m->is_controlled) · <span class="text-red-600">{{ __('controlled') }}</span> @endif</div>
                            </td>
                            <td class="px-3 py-2 text-right"><x-money :amount="$m->unit_price" /></td>
                            <td class="px-3 py-2 text-right {{ (int) $m->stock <= $m->reorder_level ? 'font-semibold text-red-600' : '' }}">{{ (int) $m->stock }} {{ $m->unit }}</td>
                            <td class="px-3 py-2">{{ $m->next_expiry ? \Illuminate\Support\Carbon::parse($m->next_expiry)->format('d M Y') : '—' }}</td>
                            <td class="px-3 py-2 text-right whitespace-nowrap">
                                <flux:button size="xs" wire:click="openStock({{ $m->id }})">{{ __('Stock') }}</flux:button>
                                @can('pharmacy.stock.manage')
                                    <flux:button size="xs" variant="ghost" wire:click="edit({{ $m->id }})">{{ __('Edit') }}</flux:button>
                                @endcan
                            </td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-8 text-center text-neutral-500">{{ __('No medicines.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        <div>
            @if ($m = $this->stockMedicine)
                <div class="space-y-4 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading level="3">{{ $m->displayName() }}</flux:heading>
                    <div class="space-y-1 text-sm">
                        @forelse ($m->batches as $b)
                            <div wire:key="b-{{ $b->id }}" class="flex items-center justify-between {{ $b->expiry_date->isPast() ? 'text-red-600 line-through' : '' }}">
                                <span>{{ $b->batch_number }} · {{ __('exp') }} {{ $b->expiry_date->format('M Y') }}</span>
                                <span>{{ $b->quantity_available }}/{{ $b->quantity_received }}
                                    @can('pharmacy.stock.manage')
                                        <flux:button size="xs" variant="ghost" wire:click="$set('adjustBatchId', {{ $b->id }})">{{ __('Adjust') }}</flux:button>
                                    @endcan
                                </span>
                            </div>
                        @empty
                            <flux:text>{{ __('No batches.') }}</flux:text>
                        @endforelse
                    </div>

                    @can('pharmacy.stock.manage')
                        @if ($adjustBatchId)
                            <form wire:submit="adjust" class="grid gap-2 rounded-lg border border-amber-300 p-3 dark:border-amber-800">
                                <flux:input size="sm" type="number" wire:model="adjust_quantity" :label="__('Quantity (+/-)')" />
                                <flux:select size="sm" wire:model="adjust_type" :label="__('Type')">
                                    <flux:select.option value="adjustment">{{ __('Stock count adjustment') }}</flux:select.option>
                                    <flux:select.option value="return">{{ __('Return to supplier') }}</flux:select.option>
                                    <flux:select.option value="expiry_writeoff">{{ __('Expiry / damage write-off') }}</flux:select.option>
                                </flux:select>
                                <flux:input size="sm" wire:model="adjust_reason" :label="__('Reason')" />
                                <flux:button size="sm" type="submit">{{ __('Apply adjustment') }}</flux:button>
                            </form>
                        @endif

                        <form wire:submit="receive" class="grid gap-2">
                            <flux:heading level="4">{{ __('Receive stock') }}</flux:heading>
                            <div class="grid grid-cols-2 gap-2">
                                <flux:input size="sm" wire:model="receipt.batch_number" :label="__('Batch no.')" />
                                <flux:input size="sm" type="date" wire:model="receipt.expiry_date" :label="__('Expiry')" />
                                <flux:input size="sm" type="number" wire:model="receipt.quantity" :label="__('Quantity')" />
                                <flux:input size="sm" type="number" step="0.01" wire:model="receipt.unit_cost" :label="__('Unit cost')" />
                            </div>
                            <flux:input size="sm" wire:model="receipt.supplier" :label="__('Supplier')" />
                            <flux:button size="sm" variant="primary" type="submit">{{ __('Receive') }}</flux:button>
                        </form>
                    @endcan
                </div>
            @endif
        </div>
    </div>
</div>
