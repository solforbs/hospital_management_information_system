<?php

use App\Models\LabOrderItem;
use App\Services\Diagnostics\LaboratoryService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Laboratory')] class extends Component {
    use WithPagination;

    #[Url]
    public string $status = 'ordered';

    #[Url]
    public string $search = '';

    public array $results = [];

    public array $comments = [];

    public ?int $cancellingId = null;

    public string $cancelReason = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('lab.orders.view'), 403);
    }

    public function updatingStatus(): void
    {
        $this->resetPage();
    }

    #[Computed]
    public function counts()
    {
        return LabOrderItem::selectRaw('status, count(*) as c')->whereIn('status', ['ordered', 'collected', 'resulted', 'verified'])->groupBy('status')->pluck('c', 'status');
    }

    #[Computed]
    public function items()
    {
        return LabOrderItem::with(['test', 'order.patient', 'order.orderedBy', 'resultedBy', 'instrument', 'components'])
            ->where('lab_order_items.status', $this->status)
            ->when($this->search, fn ($q) => $q->whereHas('order', fn ($o) => $o->where(fn ($w) => $w->where('order_number', 'like', "%{$this->search}%")
                ->orWhereHas('patient', fn ($p) => $p->where('mrn', 'like', "%{$this->search}%")->orWhere('last_name', 'like', "%{$this->search}%")->orWhere('first_name', 'like', "%{$this->search}%")))))
            ->join('lab_orders', 'lab_orders.id', '=', 'lab_order_items.lab_order_id')
            ->select('lab_order_items.*')
            ->orderByRaw("case lab_orders.priority when 'stat' then 0 when 'urgent' then 1 else 2 end")
            ->orderBy('lab_order_items.created_at')
            ->paginate(25);
    }

    public function collect(int $id, LaboratoryService $lab): void
    {
        abort_unless(Auth::user()->can('lab.orders.process'), 403);
        $item = $lab->collect(LabOrderItem::findOrFail($id));
        Flux::toast(text: __('Specimen :n collected.', ['n' => $item->specimen_number]));
        unset($this->items, $this->counts);
    }

    public function saveResult(int $id, LaboratoryService $lab): void
    {
        abort_unless(Auth::user()->can('lab.results.enter'), 403);

        $this->validate([
            "results.$id" => ['required', 'string', 'max:255'],
            "comments.$id" => ['nullable', 'string', 'max:1000'],
        ], [], ["results.$id" => __('result')]);

        $item = $lab->enterResult(LabOrderItem::findOrFail($id), trim($this->results[$id]), $this->comments[$id] ?? null);
        unset($this->results[$id], $this->comments[$id]);

        Flux::toast(variant: $item->flag === 'critical' ? 'danger' : 'success', text: $item->flag === 'critical' ? __('Critical value saved — verify and release urgently.') : __('Result saved.'));
        unset($this->items, $this->counts);
    }

    public function verify(int $id, LaboratoryService $lab): void
    {
        abort_unless(Auth::user()->can('lab.results.verify'), 403);
        $lab->verify(LabOrderItem::findOrFail($id));
        unset($this->items, $this->counts);
    }

    public function release(int $id, LaboratoryService $lab): void
    {
        abort_unless(Auth::user()->can('lab.results.release'), 403);
        $lab->release(LabOrderItem::findOrFail($id));
        Flux::toast(variant: 'success', text: __('Result released to the clinician.'));
        unset($this->items, $this->counts);
    }

    public function verifyAndRelease(int $id, LaboratoryService $lab): void
    {
        abort_unless(Auth::user()->can('lab.results.verify') && Auth::user()->can('lab.results.release'), 403);
        $item = $lab->verify(LabOrderItem::findOrFail($id));
        $lab->release($item);
        Flux::toast(variant: 'success', text: __('Result verified and released.'));
        unset($this->items, $this->counts);
    }

    public function cancel(LaboratoryService $lab): void
    {
        abort_unless(Auth::user()->can('lab.orders.process'), 403);
        $this->validate(['cancelReason' => ['required', 'string', 'max:255']]);
        $lab->cancel(LabOrderItem::findOrFail($this->cancellingId), $this->cancelReason);
        $this->reset(['cancellingId', 'cancelReason']);
        unset($this->items, $this->counts);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6" data-live="lab">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <flux:heading level="1">{{ __('Laboratory worklist') }}</flux:heading>
        <flux:input wire:model.live.debounce.400ms="search" icon="magnifying-glass" :placeholder="__('Order no., MRN or name')" class="max-w-xs" />
    </div>

    <div class="flex flex-wrap gap-2">
        @foreach (['ordered' => __('Awaiting collection'), 'collected' => __('Awaiting result'), 'resulted' => __('Awaiting verification'), 'verified' => __('Awaiting release'), 'released' => __('Released'), 'cancelled' => __('Cancelled')] as $key => $label)
            <flux:button size="sm" :variant="$status === $key ? 'primary' : 'ghost'" wire:click="$set('status', '{{ $key }}')">
                {{ $label }} @if (isset($this->counts[$key])) <flux:badge size="sm" class="ms-1">{{ $this->counts[$key] }}</flux:badge> @endif
            </flux:button>
        @endforeach
    </div>

    @if ($cancellingId)
        <form wire:submit="cancel" class="flex max-w-xl items-end gap-2 rounded-xl border border-red-300 p-4 dark:border-red-800">
            <flux:input wire:model="cancelReason" :label="__('Reason for cancelling this test')" class="flex-1" />
            <flux:button type="submit" variant="danger" size="sm">{{ __('Cancel test') }}</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="$set('cancellingId', null)">{{ __('Back') }}</flux:button>
        </form>
    @endif

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                <tr><th class="px-3 py-2">{{ __('Order') }}</th><th class="px-3 py-2">{{ __('Patient') }}</th><th class="px-3 py-2">{{ __('Test') }}</th><th class="px-3 py-2">{{ __('Result') }}</th><th class="px-3 py-2"></th></tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse ($this->items as $item)
                    <tr wire:key="li-{{ $item->id }}" class="{{ $item->order->priority !== 'routine' ? 'bg-amber-50 dark:bg-amber-950/30' : '' }}">
                        <td class="px-3 py-2 align-top">
                            <div class="font-mono text-xs">{{ $item->order->order_number }}</div>
                            <div class="text-xs text-neutral-500">{{ strtoupper($item->order->priority) }} · {{ $item->created_at->format('d M H:i') }}</div>
                            @if ($item->specimen_number)
                                <div class="text-xs">{{ __('Specimen') }} {{ $item->specimen_number }}
                                    @if (in_array($item->status, ['collected', 'resulted'], true))
                                        <a href="{{ route('print.specimen-label', $item) }}" target="_blank" class="ms-1 inline-flex items-center gap-0.5 text-[#4E7CFF] hover:underline" title="{{ __('Print barcode label for the tube') }}"><flux:icon name="qr-code" class="size-3.5" />{{ __('Label') }}</a>
                                    @endif
                                </div>
                            @endif
                        </td>
                        <td class="px-3 py-2 align-top">
                            {{ $item->order->patient->full_name }}
                            <div class="text-xs text-neutral-500">{{ $item->order->patient->mrn }} · {{ ucfirst($item->order->patient->sex) }} {{ $item->order->patient->age }}</div>
                            <div class="text-xs text-neutral-500">{{ __('By') }} {{ $item->order->orderedBy?->name }}</div>
                        </td>
                        <td class="px-3 py-2 align-top">
                            {{ $item->test->name }}
                            <div class="text-xs text-neutral-500">{{ $item->test->specimen_type }} · {{ __('ref') }} {{ $item->reference_range ?? '—' }}</div>
                            @if ($item->order->clinical_notes) <div class="text-xs italic text-neutral-500">{{ $item->order->clinical_notes }}</div> @endif
                        </td>
                        <td class="px-3 py-2 align-top">
                            @if ($item->status === 'collected' || ($item->status === 'resulted' && auth()->user()->can('lab.results.enter') && $status === 'collected'))
                                @can('lab.results.enter')
                                    <div class="flex items-start gap-2">
                                        <flux:input size="sm" wire:model="results.{{ $item->id }}" :placeholder="$item->test->unit ?? __('Result')" class="w-32" />
                                        <flux:input size="sm" wire:model="comments.{{ $item->id }}" :placeholder="__('Comment')" />
                                    </div>
                                    @error("results.{$item->id}") <flux:text class="text-xs text-red-500">{{ $message }}</flux:text> @enderror
                                @endcan
                            @elseif ($item->result_value !== null)
                                @if ($item->components->isNotEmpty())
                                    @include('partials.lab-components', ['components' => $item->components])
                                @else
                                    <span class="{{ $item->flag === 'critical' ? 'font-bold text-red-600' : '' }}">{{ $item->result_value }} {{ $item->result_unit }}</span>
                                @endif
                                @if ($item->flag && $item->flag !== 'normal') <flux:badge size="sm" :color="$item->flag === 'critical' ? 'red' : 'amber'">{{ $item->flag }}</flux:badge> @endif
                                <div class="text-xs text-neutral-500">
                                    @if ($item->instrument)
                                        <span class="inline-flex items-center gap-1" title="{{ __('Received from the analyser; check before verifying.') }}"><flux:icon name="cpu-chip" class="size-3.5" />{{ $item->instrument->name }}</span>
                                    @else
                                        {{ $item->resultedBy?->name }}
                                    @endif
                                    {{ $item->resulted_at?->format('H:i') }}
                                </div>
                                @if ($item->comments) <div class="text-xs">{{ $item->comments }}</div> @endif
                            @elseif ($item->status === 'cancelled')
                                <span class="text-xs text-neutral-500">{{ $item->cancellation_reason }}</span>
                            @endif
                        </td>
                        <td class="px-3 py-2 text-right align-top whitespace-nowrap">
                            @if ($item->status === 'ordered')
                                @can('lab.orders.process')
                                    <flux:button size="xs" variant="primary" wire:click="collect({{ $item->id }})">{{ __('Collect specimen') }}</flux:button>
                                    <flux:button size="xs" variant="ghost" wire:click="$set('cancellingId', {{ $item->id }})">{{ __('Cancel') }}</flux:button>
                                @endcan
                            @elseif ($item->status === 'collected')
                                @can('lab.results.enter')
                                    <flux:button size="xs" variant="primary" wire:click="saveResult({{ $item->id }})">{{ __('Save result') }}</flux:button>
                                @endcan
                            @elseif ($item->status === 'resulted')
                                @can('lab.results.verify')
                                    @if ($item->resulted_by !== auth()->id() || ! config('hms.lab.require_independent_verification'))
                                        @can('lab.results.release')
                                            <flux:button size="xs" variant="primary" wire:click="verifyAndRelease({{ $item->id }})">{{ __('Verify & release') }}</flux:button>
                                        @else
                                            <flux:button size="xs" variant="primary" wire:click="verify({{ $item->id }})">{{ __('Verify') }}</flux:button>
                                        @endcan
                                    @else
                                        <span class="text-xs text-neutral-500">{{ __('Needs a second verifier') }}</span>
                                    @endif
                                @else
                                    <span class="text-xs text-neutral-500">{{ __('Awaiting verification') }}</span>
                                @endcan
                            @elseif ($item->status === 'verified')
                                @can('lab.results.release')
                                    <flux:button size="xs" variant="primary" wire:click="release({{ $item->id }})">{{ __('Release') }}</flux:button>
                                @endcan
                            @endif
                        </td>
                    </tr>
                @empty
                    <tr><td colspan="5" class="px-3 py-8 text-center text-neutral-500">{{ __('Nothing in this list.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->items->links() }}
</div>
