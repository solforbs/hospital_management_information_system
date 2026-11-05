<?php

use App\Models\Department;
use App\Models\InventoryItem;
use App\Models\InventoryMovement;
use App\Models\PurchaseOrder;
use App\Models\Supplier;
use App\Services\Stores\StoresService;
use App\Services\Tenancy\TenantContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Stores & purchasing')] class extends Component {
    #[Url]
    public string $tab = 'stock';

    public string $filter = 'all';

    public array $item = ['code' => '', 'name' => '', 'category' => 'consumables', 'unit' => 'pcs', 'reorder_level' => '0'];

    public array $supplier = ['name' => '', 'kra_pin' => '', 'contact_person' => '', 'phone' => '', 'email' => ''];

    public string $po_supplier = '';

    public array $po_lines = [];

    public ?int $receivingId = null;

    public array $received = [];

    public string $delivery_note = '';

    public ?int $issuingId = null;

    public string $issue_qty = '';

    public string $issue_department = '';

    public string $issue_reason = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['inventory.manage', 'procurement.manage']), 403);
        $this->po_lines = [['inventory_item_id' => '', 'quantity' => '', 'unit_cost' => '']];
    }

    #[Computed]
    public function items()
    {
        return InventoryItem::orderBy('category')->orderBy('name')->get()
            ->when($this->filter === 'low', fn ($c) => $c->filter->isLow());
    }

    #[Computed]
    public function suppliers()
    {
        return Supplier::where('active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function orders()
    {
        return PurchaseOrder::with(['supplier', 'lines.item'])->latest('id')->limit(50)->get();
    }

    #[Computed]
    public function movements()
    {
        return InventoryMovement::with(['item', 'department', 'user'])->latest('id')->limit(50)->get();
    }

    #[Computed]
    public function departments()
    {
        return Department::orderBy('name')->get();
    }

    public function saveItem(): void
    {
        abort_unless(Auth::user()->can('inventory.manage'), 403);
        $data = $this->validate([
            'item.code' => ['required', 'string', 'max:30', Rule::unique('inventory_items', 'code')->where('tenant_id', app(TenantContext::class)->id())],
            'item.name' => ['required', 'string', 'max:150'],
            'item.category' => ['required', 'in:'.implode(',', array_keys(InventoryItem::CATEGORIES))],
            'item.unit' => ['required', 'string', 'max:20'],
            'item.reorder_level' => ['required', 'numeric', 'min:0'],
        ], [], ['item.code' => 'code'])['item'];
        $data['code'] = strtoupper($data['code']);
        InventoryItem::create($data);
        $this->item = ['code' => '', 'name' => '', 'category' => 'consumables', 'unit' => 'pcs', 'reorder_level' => '0'];
        unset($this->items);
    }

    public function saveSupplier(): void
    {
        abort_unless(Auth::user()->can('procurement.manage'), 403);
        $data = $this->validate([
            'supplier.name' => ['required', 'string', 'max:150'],
            'supplier.kra_pin' => ['nullable', 'regex:/^[AP][0-9]{9}[A-Z]$/i'],
            'supplier.contact_person' => ['nullable', 'string', 'max:100'],
            'supplier.phone' => ['nullable', 'string', 'max:30'],
            'supplier.email' => ['nullable', 'email'],
        ])['supplier'];
        Supplier::create(array_map(fn ($v) => $v === '' ? null : $v, $data));
        $this->supplier = ['name' => '', 'kra_pin' => '', 'contact_person' => '', 'phone' => '', 'email' => ''];
        unset($this->suppliers);
    }

    public function addLine(): void
    {
        $this->po_lines[] = ['inventory_item_id' => '', 'quantity' => '', 'unit_cost' => ''];
    }

    public function createPo(StoresService $stores): void
    {
        abort_unless(Auth::user()->can('procurement.manage'), 403);
        $this->validate([
            'po_supplier' => ['required', 'integer'],
            'po_lines.*.inventory_item_id' => ['nullable', 'integer'],
            'po_lines.*.quantity' => ['nullable', 'numeric', 'min:0'],
            'po_lines.*.unit_cost' => ['nullable', 'numeric', 'min:0'],
        ]);
        $supplier = Supplier::findOrFail((int) $this->po_supplier);
        $lines = collect($this->po_lines)->filter(fn ($l) => $l['inventory_item_id'] !== '' && $l['quantity'] !== '')->map(fn ($l) => ['inventory_item_id' => (int) $l['inventory_item_id'], 'quantity' => (float) $l['quantity'], 'unit_cost' => (float) ($l['unit_cost'] ?: 0)])->all();
        $po = $stores->createPurchaseOrder($supplier->id, $lines);
        $this->po_lines = [['inventory_item_id' => '', 'quantity' => '', 'unit_cost' => '']];
        $this->po_supplier = '';
        unset($this->orders);
        Flux::toast(variant: 'success', text: __('Purchase order :n created — awaiting approval.', ['n' => $po->po_number]));
    }

    public function approve(int $id, StoresService $stores): void
    {
        abort_unless(Auth::user()->can('procurement.manage'), 403);
        $stores->approve(PurchaseOrder::findOrFail($id));
        unset($this->orders);
    }

    public function receive(StoresService $stores): void
    {
        abort_unless(Auth::user()->can('inventory.manage'), 403);
        $this->validate(['received.*' => ['nullable', 'numeric', 'min:0'], 'delivery_note' => ['required', 'string', 'max:60']]);
        $stores->receive(PurchaseOrder::with('lines.item')->findOrFail($this->receivingId), $this->received, $this->delivery_note);
        $this->reset(['receivingId', 'received', 'delivery_note']);
        unset($this->orders, $this->items, $this->movements);
        Flux::toast(variant: 'success', text: __('Goods received into stock.'));
    }

    public function issue(StoresService $stores): void
    {
        abort_unless(Auth::user()->can('inventory.manage'), 403);
        $this->validate(['issue_qty' => ['required', 'numeric', 'min:0.01'], 'issue_department' => ['required', 'integer'], 'issue_reason' => ['nullable', 'string', 'max:150']]);
        $stores->issue(InventoryItem::findOrFail($this->issuingId), (float) $this->issue_qty, Department::findOrFail((int) $this->issue_department)->id, $this->issue_reason ?: null);
        $this->reset(['issuingId', 'issue_qty', 'issue_reason']);
        unset($this->items, $this->movements);
        Flux::toast(variant: 'success', text: __('Issued.'));
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:heading level="1">{{ __('Stores & purchasing') }}</flux:heading>

    <div class="flex gap-1 border-b border-neutral-200 dark:border-neutral-700">
        @foreach (['stock' => __('Stock'), 'orders' => __('Purchase orders'), 'suppliers' => __('Suppliers'), 'ledger' => __('Stock ledger')] as $k => $l)
            <button type="button" wire:click="$set('tab', '{{ $k }}')" class="-mb-px border-b-2 px-3 py-2 text-sm {{ $tab === $k ? 'border-indigo-500 font-medium' : 'border-transparent text-neutral-500' }}">{{ $l }}</button>
        @endforeach
    </div>

    @if ($tab === 'stock')
        <div class="flex gap-2">
            <flux:button size="sm" :variant="$filter === 'all' ? 'primary' : 'ghost'" wire:click="$set('filter', 'all')">{{ __('All') }}</flux:button>
            <flux:button size="sm" :variant="$filter === 'low' ? 'primary' : 'ghost'" wire:click="$set('filter', 'low')">{{ __('At / below reorder level') }}</flux:button>
        </div>
        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead><tr><th class="px-3 py-2">{{ __('Item') }}</th><th class="px-3 py-2">{{ __('Category') }}</th><th class="px-3 py-2 text-right">{{ __('In stock') }}</th><th class="px-3 py-2 text-right">{{ __('Reorder at') }}</th><th></th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->items as $i)
                        <tr wire:key="i-{{ $i->id }}">
                            <td class="px-3 py-2">{{ $i->name }} <span class="font-mono text-xs text-zinc-500">{{ $i->code }}</span></td>
                            <td class="px-3 py-2 text-xs">{{ \App\Models\InventoryItem::CATEGORIES[$i->category] ?? $i->category }}</td>
                            <td class="px-3 py-2 text-right {{ $i->isLow() ? 'font-semibold text-red-600' : '' }}">{{ (float) $i->stock_on_hand }} {{ $i->unit }}</td>
                            <td class="px-3 py-2 text-right text-xs">{{ (float) $i->reorder_level }}</td>
                            <td class="px-3 py-2 text-right">@can('inventory.manage')<flux:button size="xs" wire:click="$set('issuingId', {{ $i->id }})">{{ __('Issue') }}</flux:button>@endcan</td>
                        </tr>
                    @empty
                        <tr><td colspan="5" class="px-3 py-6 text-center text-zinc-500">{{ __('No items.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>
        @if ($issuingId)
            <form wire:submit="issue" class="grid max-w-2xl gap-3 rounded-xl border border-indigo-300 p-4 dark:border-indigo-800">
                <flux:heading level="3">{{ __('Issue') }}: {{ $this->items->firstWhere('id', $issuingId)?->name }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-3">
                    <flux:input type="number" step="0.01" wire:model="issue_qty" :label="__('Quantity')" />
                    <flux:select wire:model="issue_department" :label="__('To department')" class="sm:col-span-2">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach ($this->departments as $d)<flux:select.option value="{{ $d->id }}">{{ $d->name }}</flux:select.option>@endforeach
                    </flux:select>
                </div>
                <flux:input wire:model="issue_reason" :label="__('Requisition / note')" />
                @error('quantity')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                <div class="flex gap-2"><flux:button type="submit" size="sm" variant="primary">{{ __('Issue') }}</flux:button><flux:button size="sm" variant="ghost" wire:click="$set('issuingId', null)">{{ __('Cancel') }}</flux:button></div>
            </form>
        @endif
        @can('inventory.manage')
            <form wire:submit="saveItem" class="grid max-w-3xl gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:heading level="3">{{ __('New item') }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-5">
                    <flux:input wire:model="item.code" :label="__('Code')" />
                    <div class="sm:col-span-2"><flux:input wire:model="item.name" :label="__('Name')" /></div>
                    <flux:input wire:model="item.unit" :label="__('Unit')" />
                    <flux:input type="number" wire:model="item.reorder_level" :label="__('Reorder level')" />
                </div>
                <flux:select wire:model="item.category" :label="__('Category')" class="max-w-xs">@foreach (\App\Models\InventoryItem::CATEGORIES as $k => $l)<flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>@endforeach</flux:select>
                <div><flux:button type="submit" size="sm" variant="primary">{{ __('Add item') }}</flux:button></div>
            </form>
        @endcan
    @elseif ($tab === 'orders')
        @can('procurement.manage')
            <form wire:submit="createPo" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:heading level="3">{{ __('New purchase order') }}</flux:heading>
                <flux:select wire:model="po_supplier" :label="__('Supplier')" class="max-w-md">
                    <flux:select.option value="">—</flux:select.option>
                    @foreach ($this->suppliers as $s)<flux:select.option value="{{ $s->id }}">{{ $s->name }}</flux:select.option>@endforeach
                </flux:select>
                @foreach ($po_lines as $idx => $line)
                    <div wire:key="pl-{{ $idx }}" class="grid gap-3 sm:grid-cols-4">
                        <flux:select wire:model="po_lines.{{ $idx }}.inventory_item_id" class="sm:col-span-2">
                            <flux:select.option value="">{{ __('Item') }}</flux:select.option>
                            @foreach ($this->items as $i)<flux:select.option value="{{ $i->id }}">{{ $i->name }} ({{ $i->unit }})</flux:select.option>@endforeach
                        </flux:select>
                        <flux:input type="number" step="0.01" wire:model="po_lines.{{ $idx }}.quantity" :placeholder="__('Qty')" />
                        <flux:input type="number" step="0.01" wire:model="po_lines.{{ $idx }}.unit_cost" :placeholder="__('Unit cost')" />
                    </div>
                @endforeach
                @error('lines')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                <div class="flex gap-2"><flux:button size="sm" icon="plus" wire:click="addLine">{{ __('Line') }}</flux:button><flux:button type="submit" size="sm" variant="primary">{{ __('Create order') }}</flux:button></div>
            </form>
        @endcan
        @error('po')<flux:callout variant="danger" icon="x-circle"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>@enderror
        <div class="space-y-3">
            @foreach ($this->orders as $po)
                <div wire:key="po-{{ $po->id }}" class="rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-700">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <div><span class="font-mono">{{ $po->po_number }}</span> · {{ $po->supplier->name }} · <x-money :amount="$po->total" /></div>
                        <div class="flex items-center gap-2">
                            <flux:badge size="sm" :color="match($po->status) { 'received' => 'lime', 'approved', 'partially_received' => 'indigo', 'cancelled' => 'red', default => 'zinc' }">{{ str_replace('_', ' ', $po->status) }}</flux:badge>
                            @if ($po->status === 'draft')@can('procurement.manage')<flux:button size="xs" variant="primary" wire:click="approve({{ $po->id }})">{{ __('Approve') }}</flux:button>@endcan @endif
                            @if (in_array($po->status, ['approved', 'partially_received']))@can('inventory.manage')<flux:button size="xs" wire:click="$set('receivingId', {{ $po->id }})">{{ __('Receive goods') }}</flux:button>@endcan @endif
                        </div>
                    </div>
                    <div class="mt-2 space-y-0.5 text-xs text-zinc-500">
                        @foreach ($po->lines as $l)<div>{{ $l->item->name }} — {{ (float) $l->quantity_received }}/{{ (float) $l->quantity }} {{ $l->item->unit }} @ {{ number_format((float) $l->unit_cost, 2) }}</div>@endforeach
                    </div>
                    @if ($receivingId === $po->id)
                        <form wire:submit="receive" class="mt-3 grid gap-2 rounded-lg bg-zinc-50 p-3 dark:bg-zinc-800">
                            @foreach ($po->lines as $l)
                                <div class="flex items-center gap-2"><span class="flex-1">{{ $l->item->name }} ({{ __('outstanding') }} {{ (float) $l->quantity - (float) $l->quantity_received }})</span><flux:input type="number" step="0.01" size="sm" wire:model="received.{{ $l->id }}" class="w-28" /></div>
                                @error('received.'.$l->id)<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                            @endforeach
                            <flux:input wire:model="delivery_note" size="sm" :label="__('Delivery note / invoice no.')" />
                            <div><flux:button type="submit" size="sm" variant="primary">{{ __('Receive') }}</flux:button></div>
                        </form>
                    @endif
                </div>
            @endforeach
        </div>
    @elseif ($tab === 'suppliers')
        <div class="grid gap-6 lg:grid-cols-2">
            <div class="space-y-2">
                @forelse ($this->suppliers as $s)
                    <div wire:key="s-{{ $s->id }}" class="rounded-lg border border-neutral-200 p-3 text-sm dark:border-neutral-700">{{ $s->name }} <div class="text-xs text-zinc-500">{{ $s->kra_pin }} · {{ $s->contact_person }} · {{ $s->phone }}</div></div>
                @empty
                    <flux:text>{{ __('No suppliers yet.') }}</flux:text>
                @endforelse
            </div>
            @can('procurement.manage')
                <form wire:submit="saveSupplier" class="grid content-start gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('Add supplier') }}</flux:heading>
                    <flux:input wire:model="supplier.name" :label="__('Name')" />
                    <div class="grid gap-3 sm:grid-cols-2">
                        <flux:input wire:model="supplier.kra_pin" :label="__('KRA PIN')" />
                        <flux:input wire:model="supplier.contact_person" :label="__('Contact person')" />
                        <flux:input wire:model="supplier.phone" :label="__('Phone')" />
                        <flux:input wire:model="supplier.email" :label="__('Email')" />
                    </div>
                    <div><flux:button type="submit" size="sm" variant="primary">{{ __('Add') }}</flux:button></div>
                </form>
            @endcan
        </div>
    @else
        <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-sm">
                <thead><tr><th class="px-3 py-2">{{ __('When') }}</th><th class="px-3 py-2">{{ __('Item') }}</th><th class="px-3 py-2">{{ __('Type') }}</th><th class="px-3 py-2 text-right">{{ __('Qty') }}</th><th class="px-3 py-2">{{ __('Detail') }}</th><th class="px-3 py-2">{{ __('By') }}</th></tr></thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @foreach ($this->movements as $m)
                        <tr wire:key="m-{{ $m->id }}">
                            <td class="px-3 py-2 text-xs">{{ $m->created_at->format('d M H:i') }}</td>
                            <td class="px-3 py-2">{{ $m->item->name }}</td>
                            <td class="px-3 py-2 text-xs">{{ $m->type }}</td>
                            <td class="px-3 py-2 text-right {{ $m->quantity < 0 ? 'text-red-600' : 'text-lime-600' }}">{{ (float) $m->quantity > 0 ? '+' : '' }}{{ (float) $m->quantity }}</td>
                            <td class="px-3 py-2 text-xs">{{ $m->department?->name ?? $m->reference ?? $m->reason }}</td>
                            <td class="px-3 py-2 text-xs">{{ $m->user?->name }}</td>
                        </tr>
                    @endforeach
                </tbody>
            </table>
        </div>
    @endif
</div>
