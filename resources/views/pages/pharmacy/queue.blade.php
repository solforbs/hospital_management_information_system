<?php

use App\Models\Prescription;
use App\Services\Pharmacy\PharmacyService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Pharmacy')] class extends Component {
    #[Url]
    public string $status = 'pending';

    public ?int $selectedId = null;

    public array $quantities = [];

    public string $cancelReason = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('pharmacy.prescriptions.view'), 403);
    }

    #[Computed]
    public function prescriptions()
    {
        return Prescription::with(['patient', 'prescriber', 'encounter'])
            ->withCount('items')
            ->when($this->status === 'pending', fn ($q) => $q->whereIn('status', ['pending', 'partially_dispensed']))
            ->when($this->status !== 'pending', fn ($q) => $q->where('status', $this->status)->whereDate('updated_at', '>=', now()->subDays(7)))
            ->orderBy('created_at')
            ->limit(100)
            ->get();
    }

    #[Computed]
    public function selected()
    {
        return $this->selectedId
            ? Prescription::with(['items.medicine', 'patient.allergies', 'encounter', 'prescriber'])->find($this->selectedId)
            : null;
    }

    public function select(int $id): void
    {
        $rx = Prescription::with('items.medicine')->findOrFail($id);
        $this->selectedId = $rx->id;
        $this->quantities = $rx->items->mapWithKeys(fn ($i) => [$i->id => max(0, min($i->quantity - $i->quantity_dispensed, $i->medicine->stockOnHand()))])->all();
        $this->cancelReason = '';
    }

    public function dispense(PharmacyService $pharmacy): void
    {
        abort_unless(Auth::user()->can('pharmacy.dispense'), 403);

        $this->validate(['quantities' => ['array'], 'quantities.*' => ['nullable', 'integer', 'min:0']]);

        if (! array_filter($this->quantities)) {
            $this->addError('quantities', __('Enter a quantity to dispense.'));

            return;
        }

        $rx = $pharmacy->dispense(Prescription::findOrFail($this->selectedId), array_map('intval', $this->quantities));

        Flux::toast(variant: 'success', text: __(':n :status and charged to the patient bill.', ['n' => $rx->prescription_number, 'status' => str_replace('_', ' ', $rx->status)]));
        $this->reset(['selectedId', 'quantities']);
        unset($this->prescriptions);
    }

    public function cancel(PharmacyService $pharmacy): void
    {
        abort_unless(Auth::user()->can('pharmacy.dispense'), 403);
        $this->validate(['cancelReason' => ['required', 'string', 'max:255']]);
        $pharmacy->cancel(Prescription::findOrFail($this->selectedId), $this->cancelReason);
        $this->reset(['selectedId', 'quantities', 'cancelReason']);
        unset($this->prescriptions);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6" wire:poll.30s>
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Pharmacy dispensing') }}</flux:heading>
        <div class="flex gap-2">
            @foreach (['pending' => __('To dispense'), 'dispensed' => __('Dispensed (7d)'), 'cancelled' => __('Cancelled (7d)')] as $k => $l)
                <flux:button size="sm" :variant="$status === $k ? 'primary' : 'ghost'" wire:click="$set('status', '{{ $k }}')">{{ $l }}</flux:button>
            @endforeach
        </div>
    </div>

    <div class="grid gap-6 lg:grid-cols-3">
        <div class="space-y-2">
            @forelse ($this->prescriptions as $rx)
                <button type="button" wire:key="rx-{{ $rx->id }}" wire:click="select({{ $rx->id }})" class="block w-full rounded-lg border p-3 text-left text-sm {{ $selectedId === $rx->id ? 'border-indigo-500' : 'border-neutral-200 dark:border-neutral-700' }}">
                    <div class="flex justify-between"><span class="font-medium">{{ $rx->patient->full_name }}</span><span class="text-xs text-neutral-500">{{ $rx->created_at->format('H:i') }}</span></div>
                    <div class="text-xs text-neutral-500"><span class="font-mono">{{ $rx->prescription_number }}</span> · {{ $rx->items_count }} {{ __('item(s)') }} · {{ ucfirst($rx->encounter->payer_type) }} · {{ $rx->prescriber?->name }}</div>
                    @if ($rx->status === 'partially_dispensed') <flux:badge size="sm" color="amber">{{ __('partial') }}</flux:badge> @endif
                </button>
            @empty
                <flux:text>{{ __('No prescriptions.') }}</flux:text>
            @endforelse
        </div>

        <div class="lg:col-span-2">
            @if ($rx = $this->selected)
                <x-patient-banner :patient="$rx->patient" :encounter="$rx->encounter" class="mb-4" />
                <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <div class="mb-3 flex justify-between text-sm">
                        <span class="font-mono">{{ $rx->prescription_number }}</span>
                        <span class="text-neutral-500">{{ $rx->prescriber?->name }} · {{ $rx->created_at->format('d M Y H:i') }}</span>
                    </div>
                    @if ($rx->notes) <flux:text class="mb-3 italic">{{ $rx->notes }}</flux:text> @endif
                    <table class="w-full text-left text-sm">
                        <thead class="text-xs uppercase text-neutral-500"><tr><th class="py-1">{{ __('Medicine') }}</th><th class="py-1">{{ __('Regimen') }}</th><th class="py-1">{{ __('Prescribed') }}</th><th class="py-1">{{ __('Stock') }}</th><th class="py-1">{{ __('Dispense now') }}</th></tr></thead>
                        <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                            @foreach ($rx->items as $item)
                                @php $stock = $item->medicine->stockOnHand(); $remaining = $item->quantity - $item->quantity_dispensed; @endphp
                                <tr wire:key="it-{{ $item->id }}">
                                    <td class="py-2">
                                        {{ $item->medicine->displayName() }}
                                        @if ($item->medicine->is_controlled) <flux:badge size="sm" color="red">{{ __('Controlled') }}</flux:badge> @endif
                                        @if (app(\App\Services\Pharmacy\PharmacyService::class)->allergyConflicts($rx->patient_id, $item->medicine)->isNotEmpty())
                                            <flux:badge size="sm" color="red" icon="exclamation-triangle">{{ __('Allergy') }}</flux:badge>
                                        @endif
                                    </td>
                                    <td class="py-2">{{ $item->dose }} {{ $item->frequency }} {{ $item->route }} × {{ $item->duration_days }}d<div class="text-xs text-neutral-500">{{ $item->instructions }}</div></td>
                                    <td class="py-2">{{ $item->quantity_dispensed }}/{{ $item->quantity }}</td>
                                    <td class="py-2 {{ $stock < $remaining ? 'text-red-600' : '' }}">{{ $stock }}</td>
                                    <td class="py-2">
                                        @if ($remaining > 0 && $item->status !== 'cancelled' && in_array($rx->status, ['pending', 'partially_dispensed']))
                                            <flux:input size="sm" type="number" min="0" :max="$remaining" wire:model="quantities.{{ $item->id }}" class="w-24" />
                                            @error("quantities.{$item->id}") <flux:text class="text-xs text-red-500">{{ $message }}</flux:text> @enderror
                                        @else
                                            <flux:badge size="sm" color="lime">{{ $item->status }}</flux:badge>
                                        @endif
                                    </td>
                                </tr>
                            @endforeach
                        </tbody>
                    </table>
                    @error('quantities') <flux:text class="text-sm text-red-500">{{ $message }}</flux:text> @enderror

                    @if (in_array($rx->status, ['pending', 'partially_dispensed']))
                        @can('pharmacy.dispense')
                            <div class="mt-4 flex flex-wrap items-end gap-3">
                                <flux:button variant="primary" wire:click="dispense" wire:loading.attr="disabled">{{ __('Dispense & bill') }}</flux:button>
                                @if ($rx->status === 'pending')
                                    <flux:input size="sm" wire:model="cancelReason" :placeholder="__('Reason to cancel')" class="max-w-xs" />
                                    <flux:button size="sm" variant="ghost" wire:click="cancel">{{ __('Cancel prescription') }}</flux:button>
                                @endif
                            </div>
                        @endcan
                    @endif
                </div>
            @else
                <div class="rounded-xl border border-dashed border-neutral-300 p-10 text-center text-neutral-500 dark:border-neutral-700">{{ __('Select a prescription.') }}</div>
            @endif
        </div>
    </div>
</div>
