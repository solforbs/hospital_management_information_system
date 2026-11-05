<?php

use App\Models\RadiologyOrder;
use App\Services\Diagnostics\RadiologyService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Radiology')] class extends Component {
    #[Url]
    public string $status = 'ordered';

    public ?int $reportingId = null;

    public string $findings = '';

    public string $impression = '';

    public array $scheduleAt = [];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('radiology.orders.view'), 403);
    }

    #[Computed]
    public function counts()
    {
        return RadiologyOrder::selectRaw('status, count(*) as c')->groupBy('status')->pluck('c', 'status');
    }

    #[Computed]
    public function orders()
    {
        return RadiologyOrder::with(['exam', 'patient', 'orderedBy', 'reportedBy'])
            ->where('status', $this->status)
            ->orderByRaw("case priority when 'stat' then 0 when 'urgent' then 1 else 2 end")
            ->orderBy('created_at')
            ->limit(100)
            ->get();
    }

    public function schedule(int $id, RadiologyService $radiology): void
    {
        abort_unless(Auth::user()->can('radiology.orders.process'), 403);
        $this->validate(["scheduleAt.$id" => ['required', 'date', 'after_or_equal:today']]);
        $radiology->schedule(RadiologyOrder::findOrFail($id), $this->scheduleAt[$id]);
        unset($this->orders, $this->counts);
    }

    public function perform(int $id, RadiologyService $radiology): void
    {
        abort_unless(Auth::user()->can('radiology.orders.process'), 403);
        $radiology->perform(RadiologyOrder::findOrFail($id));
        Flux::toast(text: __('Examination marked as performed.'));
        unset($this->orders, $this->counts);
    }

    public function startReport(int $id): void
    {
        $order = RadiologyOrder::findOrFail($id);
        $this->reportingId = $order->id;
        $this->findings = (string) $order->findings;
        $this->impression = (string) $order->impression;
    }

    public function saveReport(RadiologyService $radiology): void
    {
        abort_unless(Auth::user()->can('radiology.orders.process'), 403);
        $this->validate(['findings' => ['required', 'string', 'max:10000'], 'impression' => ['required', 'string', 'max:3000']]);
        $radiology->report(RadiologyOrder::findOrFail($this->reportingId), $this->findings, $this->impression);
        $this->reset(['reportingId', 'findings', 'impression']);
        Flux::toast(variant: 'success', text: __('Report saved — awaiting verification.'));
        unset($this->orders, $this->counts);
    }

    public function verify(int $id, RadiologyService $radiology): void
    {
        abort_unless(Auth::user()->can('radiology.reports.verify'), 403);
        $radiology->verify(RadiologyOrder::findOrFail($id));
        Flux::toast(variant: 'success', text: __('Report verified and released.'));
        unset($this->orders, $this->counts);
    }

    public function cancel(int $id, RadiologyService $radiology): void
    {
        abort_unless(Auth::user()->can('radiology.orders.process'), 403);
        $radiology->cancel(RadiologyOrder::findOrFail($id), __('Cancelled by radiology'));
        unset($this->orders, $this->counts);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6" data-live="radiology">
    <flux:heading level="1">{{ __('Radiology worklist') }}</flux:heading>

    <div class="flex flex-wrap gap-2">
        @foreach (['ordered' => __('New requests'), 'scheduled' => __('Scheduled'), 'performed' => __('To report'), 'reported' => __('To verify'), 'verified' => __('Completed'), 'cancelled' => __('Cancelled')] as $key => $label)
            <flux:button size="sm" :variant="$status === $key ? 'primary' : 'ghost'" wire:click="$set('status', '{{ $key }}')">
                {{ $label }} @if (isset($this->counts[$key])) <flux:badge size="sm" class="ms-1">{{ $this->counts[$key] }}</flux:badge> @endif
            </flux:button>
        @endforeach
    </div>

    @if ($reportingId)
        <form wire:submit="saveReport" class="grid max-w-3xl gap-3 rounded-xl border border-indigo-300 p-5 dark:border-indigo-800">
            <flux:heading level="3">{{ __('Report') }}</flux:heading>
            <flux:textarea wire:model="findings" rows="6" :label="__('Findings')" />
            <flux:textarea wire:model="impression" rows="3" :label="__('Impression')" />
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Save report') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('reportingId', null)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    <div class="space-y-3">
        @forelse ($this->orders as $o)
            <div wire:key="ro-{{ $o->id }}" class="rounded-xl border p-4 text-sm {{ $o->priority !== 'routine' ? 'border-amber-400' : 'border-neutral-200 dark:border-neutral-700' }}">
                <div class="flex flex-wrap items-start justify-between gap-3">
                    <div>
                        <div class="font-medium">{{ strtoupper($o->exam->modality) }} — {{ $o->exam->name }} <span class="text-xs uppercase text-amber-600">{{ $o->priority !== 'routine' ? $o->priority : '' }}</span></div>
                        <div class="text-neutral-500">{{ $o->patient->full_name }} · {{ $o->patient->mrn }} · {{ ucfirst($o->patient->sex) }} {{ $o->patient->age }}</div>
                        <div class="text-xs text-neutral-500"><span class="font-mono">{{ $o->order_number }}</span> · {{ __('by') }} {{ $o->orderedBy?->name }} · {{ $o->created_at->format('d M H:i') }} @if ($o->scheduled_at) · {{ __('scheduled') }} {{ $o->scheduled_at->format('d M H:i') }} @endif</div>
                        <div class="mt-1"><span class="text-neutral-500">{{ __('Indication') }}:</span> {{ $o->clinical_indication }}</div>
                        @if ($o->exam->requires_preparation) <div class="text-xs text-amber-600">{{ __('Preparation') }}: {{ $o->exam->preparation_instructions }}</div> @endif
                        @if ($o->impression)
                            <div class="mt-2"><span class="font-medium">{{ __('Impression') }}:</span> {{ $o->impression }} <span class="text-xs text-neutral-500">— {{ $o->reportedBy?->name }}</span></div>
                        @endif
                    </div>
                    <div class="flex flex-wrap items-center gap-2">
                        @can('radiology.orders.process')
                            @if (in_array($o->status, ['ordered', 'scheduled']))
                                <flux:input size="sm" type="datetime-local" wire:model="scheduleAt.{{ $o->id }}" class="w-52" />
                                <flux:button size="xs" wire:click="schedule({{ $o->id }})">{{ __('Schedule') }}</flux:button>
                                <flux:button size="xs" variant="primary" wire:click="perform({{ $o->id }})">{{ __('Mark performed') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="cancel({{ $o->id }})" wire:confirm="{{ __('Cancel this request?') }}">{{ __('Cancel') }}</flux:button>
                            @elseif (in_array($o->status, ['performed', 'reported']))
                                <flux:button size="xs" variant="primary" wire:click="startReport({{ $o->id }})">{{ $o->status === 'performed' ? __('Write report') : __('Edit report') }}</flux:button>
                            @endif
                        @endcan
                        @if ($o->status === 'reported')
                            @can('radiology.reports.verify')
                                <flux:button size="xs" variant="primary" wire:click="verify({{ $o->id }})">{{ __('Verify & release') }}</flux:button>
                            @endcan
                        @endif
                    </div>
                </div>
            </div>
        @empty
            <flux:text>{{ __('Nothing in this list.') }}</flux:text>
        @endforelse
    </div>
</div>
