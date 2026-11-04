<?php

use App\Models\Encounter;
use App\Models\Surgery;
use App\Models\TheatreRoom;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Services\Theatre\TheatreService;
use Flux\Flux;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Theatre')] class extends Component {
    #[Url]
    public string $date = '';

    #[Url(as: 'encounter')]
    public ?int $encounter_id = null;

    public string $encounterSearch = '';

    public array $form = ['procedure_name' => '', 'urgency' => 'elective', 'anesthesia_type' => 'general', 'scheduled_at' => '', 'estimated_minutes' => 60, 'theatre_room_id' => '', 'surgeon_id' => '', 'anesthetist_id' => '', 'fee' => '0'];

    public string $newRoom = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->canAny(['theatre.schedule', 'theatre.procedures.manage']), 403);
        $this->date = $this->date ?: now()->toDateString();
        $this->form['scheduled_at'] = now()->addDay()->setTime(8, 0)->format('Y-m-d\TH:i');
    }

    #[Computed]
    public function rooms()
    {
        return TheatreRoom::where('active', true)->orderBy('name')->get();
    }

    #[Computed]
    public function staff()
    {
        return app(TenantContext::class)->tenant()->users()->wherePivot('status', 'active')->orderBy('name')->get();
    }

    #[Computed]
    public function surgeons()
    {
        return $this->staff->filter(fn (User $u) => $u->can('theatre.records.sign') || $u->can('encounters.create'))->values();
    }

    #[Computed]
    public function encounter()
    {
        return $this->encounter_id ? Encounter::with('patient')->where('status', 'open')->find($this->encounter_id) : null;
    }

    #[Computed]
    public function encounterMatches()
    {
        $t = trim($this->encounterSearch);
        if (strlen($t) < 2) {
            return collect();
        }

        return Encounter::with('patient')->where('status', 'open')
            ->where(fn ($q) => $q->where('encounter_number', 'like', "%$t%")->orWhereHas('patient', fn ($p) => $p->where('mrn', 'like', "%$t%")->orWhere('last_name', 'like', "%$t%")->orWhere('first_name', 'like', "%$t%")))
            ->limit(8)->get();
    }

    #[Computed]
    public function surgeries()
    {
        $day = Carbon::parse($this->date);

        return Surgery::with(['patient', 'room', 'surgeon', 'anesthetist'])
            ->whereBetween('scheduled_at', [$day->copy()->startOfDay(), $day->copy()->endOfDay()])
            ->orderBy('scheduled_at')->get();
    }

    public function shiftDay(int $d): void
    {
        $this->date = Carbon::parse($this->date)->addDays($d)->toDateString();
    }

    public function addRoom(): void
    {
        abort_unless(Auth::user()->can('theatre.schedule'), 403);
        $this->validate(['newRoom' => ['required', 'string', 'max:60']]);
        TheatreRoom::create(['name' => $this->newRoom, 'facility_id' => app(TenantContext::class)->facilityId(), 'active' => true]);
        $this->reset('newRoom');
        unset($this->rooms);
    }

    public function book(TheatreService $theatre): void
    {
        abort_unless(Auth::user()->can('theatre.schedule'), 403);

        $this->validate([
            'encounter_id' => ['required', 'integer'],
            'form.procedure_name' => ['required', 'string', 'max:200'],
            'form.urgency' => ['required', 'in:elective,urgent,emergency'],
            'form.anesthesia_type' => ['nullable', 'in:general,spinal,regional,local,sedation'],
            'form.scheduled_at' => ['required', 'date'],
            'form.estimated_minutes' => ['required', 'integer', 'min:10', 'max:900'],
            'form.theatre_room_id' => ['nullable', 'integer'],
            'form.surgeon_id' => ['nullable', 'integer'],
            'form.anesthetist_id' => ['nullable', 'integer'],
            'form.fee' => ['required', 'numeric', 'min:0'],
        ]);

        $encounter = Encounter::where('status', 'open')->findOrFail($this->encounter_id);
        $data = $this->form;
        $data['theatre_room_id'] = $data['theatre_room_id'] ? TheatreRoom::findOrFail($data['theatre_room_id'])->id : null;
        $data['surgeon_id'] = $data['surgeon_id'] && $this->staff->contains('id', $data['surgeon_id']) ? (int) $data['surgeon_id'] : null;
        $data['anesthetist_id'] = $data['anesthetist_id'] && $this->staff->contains('id', $data['anesthetist_id']) ? (int) $data['anesthetist_id'] : null;

        $surgery = $theatre->schedule($encounter, $data);

        Flux::toast(variant: 'success', text: __('Theatre booked: :n', ['n' => $surgery->surgery_number]));
        $this->date = $surgery->scheduled_at->toDateString();
        $this->reset('encounter_id');
        $this->form['procedure_name'] = '';
        unset($this->surgeries);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:heading level="1">{{ __('Theatre schedule') }}</flux:heading>

    <div class="grid gap-6 xl:grid-cols-3">
        @can('theatre.schedule')
            <div class="space-y-4">
                <form wire:submit="book" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('Book a case') }}</flux:heading>
                    @if ($this->encounter)
                        <div class="flex items-center justify-between rounded-lg bg-neutral-100 px-3 py-2 text-sm dark:bg-neutral-800">
                            <span>{{ $this->encounter->patient->full_name }} · <span class="font-mono text-xs">{{ $this->encounter->encounter_number }}</span></span>
                            <flux:button size="xs" variant="ghost" wire:click="$set('encounter_id', null)">{{ __('Change') }}</flux:button>
                        </div>
                    @else
                        <flux:input wire:model.live.debounce.300ms="encounterSearch" icon="magnifying-glass" :placeholder="__('Patient with an open visit')" />
                        @error('encounter_id') <flux:text class="text-sm text-red-500">{{ __('Select a patient visit.') }}</flux:text> @enderror
                        @foreach ($this->encounterMatches as $e)
                            <button type="button" wire:key="em-{{ $e->id }}" wire:click="$set('encounter_id', {{ $e->id }})" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800">{{ $e->patient->full_name }} · {{ $e->encounter_number }}</button>
                        @endforeach
                    @endif
                    <flux:input wire:model="form.procedure_name" :label="__('Procedure')" />
                    <div class="grid grid-cols-2 gap-3">
                        <flux:select wire:model="form.urgency" :label="__('Urgency')">
                            <flux:select.option value="elective">{{ __('Elective') }}</flux:select.option>
                            <flux:select.option value="urgent">{{ __('Urgent') }}</flux:select.option>
                            <flux:select.option value="emergency">{{ __('Emergency') }}</flux:select.option>
                        </flux:select>
                        <flux:select wire:model="form.anesthesia_type" :label="__('Anaesthesia')">
                            @foreach (['general', 'spinal', 'regional', 'local', 'sedation'] as $a)
                                <flux:select.option value="{{ $a }}">{{ ucfirst($a) }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:input type="datetime-local" wire:model="form.scheduled_at" :label="__('Start')" />
                        <flux:input type="number" wire:model="form.estimated_minutes" :label="__('Minutes')" />
                    </div>
                    <flux:select wire:model="form.theatre_room_id" :label="__('Theatre')">
                        <flux:select.option value="">{{ __('Unassigned') }}</flux:select.option>
                        @foreach ($this->rooms as $r)
                            <flux:select.option value="{{ $r->id }}">{{ $r->name }}</flux:select.option>
                        @endforeach
                    </flux:select>
                    <div class="grid grid-cols-2 gap-3">
                        <flux:select wire:model="form.surgeon_id" :label="__('Surgeon')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($this->surgeons as $s)
                                <flux:select.option value="{{ $s->id }}">{{ $s->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                        <flux:select wire:model="form.anesthetist_id" :label="__('Anaesthetist')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach ($this->staff as $s)
                                <flux:select.option value="{{ $s->id }}">{{ $s->name }}</flux:select.option>
                            @endforeach
                        </flux:select>
                    </div>
                    <flux:input type="number" step="0.01" wire:model="form.fee" :label="__('Procedure fee')" />
                    @error('form.scheduled_at') <flux:text class="text-sm text-red-500">{{ $message }}</flux:text> @enderror
                    <div><flux:button type="submit" variant="primary" size="sm">{{ __('Book') }}</flux:button></div>
                </form>

                <form wire:submit="addRoom" class="flex items-end gap-2">
                    <flux:input size="sm" wire:model="newRoom" :label="__('Add theatre room')" />
                    <flux:button size="sm" type="submit">{{ __('Add') }}</flux:button>
                </form>
            </div>
        @endcan

        <div class="xl:col-span-2">
            <div class="mb-3 flex items-center gap-2">
                <flux:button size="sm" icon="chevron-left" wire:click="shiftDay(-1)" />
                <flux:input type="date" wire:model.live="date" class="max-w-48" />
                <flux:button size="sm" icon="chevron-right" wire:click="shiftDay(1)" />
            </div>
            <div class="space-y-2">
                @forelse ($this->surgeries as $s)
                    <a wire:key="s-{{ $s->id }}" href="{{ route('theatre.show', $s) }}" wire:navigate class="block rounded-xl border p-4 text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800 {{ $s->urgency === 'emergency' ? 'border-red-400' : 'border-neutral-200 dark:border-neutral-700' }}">
                        <div class="flex justify-between">
                            <span class="font-medium">{{ $s->scheduled_at->format('H:i') }} — {{ $s->procedure_name }}</span>
                            <flux:badge size="sm" :color="['scheduled' => 'sky', 'in_progress' => 'amber', 'completed' => 'lime', 'cancelled' => 'zinc'][$s->status] ?? 'zinc'">{{ str_replace('_', ' ', $s->status) }}</flux:badge>
                        </div>
                        <div class="text-neutral-500">{{ $s->patient->full_name }} · {{ $s->room?->name ?? __('No room') }} · {{ $s->estimated_minutes }} {{ __('min') }} · {{ ucfirst($s->urgency) }}</div>
                        <div class="text-xs text-neutral-500">{{ __('Surgeon') }}: {{ $s->surgeon?->name ?? '—' }} · {{ __('Anaesthetist') }}: {{ $s->anesthetist?->name ?? '—' }}</div>
                    </a>
                @empty
                    <flux:text>{{ __('No cases scheduled.') }}</flux:text>
                @endforelse
            </div>
        </div>
    </div>
</div>
