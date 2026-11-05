<?php

use App\Models\Encounter;
use App\Models\Invoice;
use App\Models\Payment;
use App\Support\EncounterStage;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Cashier')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = 'outstanding';

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['billing.invoices.view', 'billing.payments.receive']), 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function waitingAtBilling()
    {
        return Encounter::with(['patient', 'invoices'])->where('status', 'open')->where('stage', EncounterStage::WAITING_BILLING)->orderBy('updated_at')->get();
    }

    #[Computed]
    public function invoices()
    {
        return Invoice::with('patient')
            ->when($this->status === 'outstanding', fn ($q) => $q->whereIn('status', ['open', 'partially_paid'])->where('balance', '>', 0))
            ->when(in_array($this->status, ['paid', 'void']), fn ($q) => $q->where('status', $this->status))
            ->when($this->search, fn ($q) => $q->where(fn ($w) => $w->where('invoice_number', 'like', "%{$this->search}%")
                ->orWhereHas('patient', fn ($p) => $p->where('mrn', 'like', "%{$this->search}%")->orWhere('last_name', 'like', "%{$this->search}%")->orWhere('first_name', 'like', "%{$this->search}%")->orWhere('phone', 'like', "%{$this->search}%"))))
            ->latest()
            ->paginate(20);
    }

    #[Computed]
    public function todayTotals()
    {
        $q = Payment::whereDate('received_at', today());

        return [
            'count' => (clone $q)->count(),
            'total' => (float) (clone $q)->sum('amount'),
            'mine' => (float) (clone $q)->where('received_by', Auth::id())->sum('amount'),
            'by_method' => (clone $q)->selectRaw('method, sum(amount) as total')->groupBy('method')->pluck('total', 'method'),
        ];
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6" data-live="billing,queue">
    <flux:heading level="1">{{ __('Cashier') }}</flux:heading>

    <div class="grid gap-3 sm:grid-cols-4">
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700"><div class="text-xs text-neutral-500">{{ __("Today's collections") }}</div><div class="text-xl font-semibold"><x-money :amount="$this->todayTotals['total']" /></div><div class="text-xs text-neutral-500">{{ $this->todayTotals['count'] }} {{ __('receipts') }}</div></div>
        <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700"><div class="text-xs text-neutral-500">{{ __('Collected by me') }}</div><div class="text-xl font-semibold"><x-money :amount="$this->todayTotals['mine']" /></div></div>
        <div class="rounded-xl border border-neutral-200 p-4 sm:col-span-2 dark:border-neutral-700">
            <div class="text-xs text-neutral-500">{{ __('By method') }}</div>
            <div class="mt-1 flex flex-wrap gap-3 text-sm">
                @forelse ($this->todayTotals['by_method'] as $m => $t)
                    <span>{{ config('hms.payment_methods.'.$m, $m) }}: <x-money :amount="$t" /></span>
                @empty
                    <span class="text-neutral-500">—</span>
                @endforelse
            </div>
        </div>
    </div>

    @if ($this->waitingAtBilling->isNotEmpty())
        <div>
            <flux:heading level="3" class="mb-2">{{ __('Patients waiting at billing') }}</flux:heading>
            <div class="grid gap-2 md:grid-cols-3">
                @foreach ($this->waitingAtBilling as $e)
                    @php $inv = $e->invoices->whereIn('status', ['open', 'partially_paid'])->first() ?? $e->invoices->first(); @endphp
                    <div wire:key="wb-{{ $e->id }}" class="flex items-center justify-between rounded-lg border border-orange-300 p-3 text-sm dark:border-orange-800">
                        <div>{{ $e->patient->full_name }}<div class="text-xs text-neutral-500">{{ $e->encounter_number }} · {{ ucfirst($e->payer_type) }}</div></div>
                        @if ($inv)
                            <flux:button size="xs" variant="primary" :href="route('billing.invoice', $inv)" wire:navigate><x-money :amount="$inv->balance" /></flux:button>
                        @endif
                    </div>
                @endforeach
            </div>
        </div>
    @endif

    <div class="flex flex-wrap items-center gap-3">
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Invoice, MRN, name or phone')" class="max-w-sm" />
        @foreach (['outstanding' => __('Outstanding'), 'paid' => __('Paid'), 'void' => __('Void'), 'all' => __('All')] as $k => $l)
            <flux:button size="sm" :variant="$status === $k ? 'primary' : 'ghost'" wire:click="$set('status', '{{ $k }}')">{{ $l }}</flux:button>
        @endforeach
    </div>

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                <tr><th class="px-3 py-2">{{ __('Invoice') }}</th><th class="px-3 py-2">{{ __('Patient') }}</th><th class="px-3 py-2">{{ __('Payer') }}</th><th class="px-3 py-2 text-right">{{ __('Total') }}</th><th class="px-3 py-2 text-right">{{ __('Paid') }}</th><th class="px-3 py-2 text-right">{{ __('Balance') }}</th><th class="px-3 py-2">{{ __('Status') }}</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse ($this->invoices as $inv)
                    <tr wire:key="inv-{{ $inv->id }}">
                        <td class="px-3 py-2 font-mono text-xs">{{ $inv->invoice_number }}<div class="font-sans text-neutral-500">{{ $inv->created_at->format('d M H:i') }}</div></td>
                        <td class="px-3 py-2">{{ $inv->patient->full_name }}<div class="text-xs text-neutral-500">{{ $inv->patient->mrn }}</div></td>
                        <td class="px-3 py-2">{{ ucfirst($inv->payer_type) }}</td>
                        <td class="px-3 py-2 text-right"><x-money :amount="$inv->total" /></td>
                        <td class="px-3 py-2 text-right"><x-money :amount="$inv->amount_paid" /></td>
                        <td class="px-3 py-2 text-right font-medium"><x-money :amount="$inv->balance" /></td>
                        <td class="px-3 py-2"><flux:badge size="sm" :color="['paid' => 'lime', 'partially_paid' => 'amber', 'open' => 'sky', 'void' => 'zinc'][$inv->status]">{{ str_replace('_', ' ', $inv->status) }}</flux:badge></td>
                        <td class="px-3 py-2 text-right"><flux:button size="xs" :href="route('billing.invoice', $inv)" wire:navigate>{{ __('Open') }}</flux:button></td>
                    </tr>
                @empty
                    <tr><td colspan="8" class="px-3 py-8 text-center text-neutral-500">{{ __('No invoices.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>
    {{ $this->invoices->links() }}
</div>
