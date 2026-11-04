<?php

use App\Models\Labour;
use App\Services\Maternity\MaternityService;
use App\Support\ModuleRegistry;
use App\Services\Tenancy\TenantContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Labour & partograph')] class extends Component {
    public Labour $labour;

    public array $entry = [];

    public array $delivery = [];

    public array $babies = [];

    public string $refer_reason = '';

    public function mount(Labour $labour): void
    {
        abort_unless(Auth::user()->canAny(['maternity.deliver', 'maternity.view']), 403);
        $this->labour = $labour->load(['pregnancy.patient', 'encounter']);
        $this->resetEntry();
        $this->delivery = ['delivered_at' => now()->format('Y-m-d\TH:i'), 'delivery_mode' => 'svd', 'amtsl' => true, 'uterotonic_given' => true, 'placenta_complete' => true, 'blood_loss_ml' => '', 'perineum' => 'intact', 'mother_condition' => 'stable', 'complications' => ''];
        $this->babies = [$this->blankBaby()];
    }

    protected function resetEntry(): void
    {
        $this->entry = array_fill_keys(['fetal_heart_rate', 'liquor', 'moulding', 'cervix_cm', 'descent_fifths', 'contractions_per_10', 'contraction_strength', 'oxytocin_units', 'drugs', 'pulse', 'systolic', 'diastolic', 'temperature', 'urine_protein', 'urine_volume_ml'], '');
        $this->entry['recorded_at'] = now()->format('Y-m-d\TH:i');
    }

    protected function blankBaby(): array
    {
        return ['sex' => 'female', 'outcome' => 'live', 'birth_weight_g' => '', 'apgar_1' => '', 'apgar_5' => '', 'apgar_10' => '', 'resuscitated' => false, 'vitamin_k' => true, 'teo_given' => true, 'bcg_given' => false, 'opv0_given' => false, 'breastfed_within_1h' => true, 'chlorhexidine_cord' => true, 'notes' => ''];
    }

    public function addBaby(): void
    {
        if (count($this->babies) < 6) {
            $this->babies[] = $this->blankBaby();
        }
    }

    public function removeBaby(int $i): void
    {
        if (count($this->babies) > 1) {
            unset($this->babies[$i]);
            $this->babies = array_values($this->babies);
        }
    }

    #[Computed]
    public function entries()
    {
        return $this->labour->entries()->with('recorder')->get();
    }

    #[Computed]
    public function alerts(): array
    {
        return app(MaternityService::class)->partographAlerts($this->labour);
    }

    /** Points for the cervicograph SVG: hours since first entry (x) vs cm (y), plus alert/action lines. */
    #[Computed]
    public function chart(): array
    {
        $exams = $this->entries->whereNotNull('cervix_cm')->values();
        if ($exams->isEmpty()) {
            return ['points' => [], 'alert' => null, 'action' => null];
        }

        $t0 = $exams->first()->recorded_at;
        $points = $exams->map(fn ($e) => ['x' => round($t0->diffInMinutes($e->recorded_at) / 60, 2), 'y' => (float) $e->cervix_cm])->all();
        $start = $exams->first(fn ($e) => (float) $e->cervix_cm >= 4);
        $alert = $action = null;

        if ($start) {
            $x0 = round($t0->diffInMinutes($start->recorded_at) / 60, 2);
            $c0 = (float) $start->cervix_cm;
            $alert = [[$x0, $c0], [$x0 + (10 - $c0), 10]];
            $action = [[$x0 + 4, $c0], [$x0 + 4 + (10 - $c0), 10]];
        }

        return ['points' => $points, 'alert' => $alert, 'action' => $action];
    }

    public function isOpen(): bool
    {
        return $this->labour->status === 'in_labour';
    }

    public function record(MaternityService $maternity): void
    {
        abort_unless(Auth::user()->can('maternity.deliver'), 403);

        $data = $this->validate([
            'entry.recorded_at' => ['required', 'date'],
            'entry.fetal_heart_rate' => ['nullable', 'integer', 'between:40,240'],
            'entry.liquor' => ['nullable', 'in:I,C,M,B,A'],
            'entry.moulding' => ['nullable', 'in:0,+,++,+++'],
            'entry.cervix_cm' => ['nullable', 'numeric', 'between:0,10'],
            'entry.descent_fifths' => ['nullable', 'integer', 'between:0,5'],
            'entry.contractions_per_10' => ['nullable', 'integer', 'between:0,10'],
            'entry.contraction_strength' => ['nullable', 'in:<20,20-40,>40'],
            'entry.oxytocin_units' => ['nullable', 'numeric', 'between:0,60'],
            'entry.drugs' => ['nullable', 'string', 'max:255'],
            'entry.pulse' => ['nullable', 'integer', 'between:30,220'],
            'entry.systolic' => ['nullable', 'integer', 'between:50,260'],
            'entry.diastolic' => ['nullable', 'integer', 'between:30,180'],
            'entry.temperature' => ['nullable', 'numeric', 'between:30,43'],
            'entry.urine_protein' => ['nullable', 'in:nil,trace,+,++,+++'],
            'entry.urine_volume_ml' => ['nullable', 'integer', 'between:0,3000'],
        ])['entry'];

        if (collect($data)->except('recorded_at')->filter(fn ($v) => $v !== '' && $v !== null)->isEmpty()) {
            $this->addError('entry', __('Enter at least one observation.'));

            return;
        }

        $maternity->recordPartograph($this->labour, $data);
        $this->labour->refresh();
        $this->resetEntry();
        unset($this->entries, $this->alerts, $this->chart);

        $this->alerts['action'] || $this->alerts['critical']
            ? Flux::toast(variant: 'danger', text: __('Warning signs on the partograph — the clinician on call has been alerted.'))
            : Flux::toast(variant: 'success', text: __('Partograph updated.'));
    }

    public function deliver(MaternityService $maternity): void
    {
        abort_unless(Auth::user()->can('maternity.deliver'), 403);

        $this->validate([
            'delivery.delivered_at' => ['required', 'date'],
            'delivery.delivery_mode' => ['required', 'in:'.implode(',', array_keys(Labour::MODES))],
            'delivery.amtsl' => ['boolean'],
            'delivery.uterotonic_given' => ['boolean'],
            'delivery.placenta_complete' => ['boolean'],
            'delivery.blood_loss_ml' => ['nullable', 'integer', 'between:0,6000'],
            'delivery.perineum' => ['nullable', 'in:intact,first,second,third,fourth,episiotomy,na'],
            'delivery.mother_condition' => ['required', 'in:stable,pph,eclampsia,sepsis,ruptured_uterus,deceased,other'],
            'delivery.complications' => ['nullable', 'string', 'max:2000'],
            'babies' => ['required', 'array', 'min:1', 'max:6'],
            'babies.*.sex' => ['required', 'in:male,female,unknown'],
            'babies.*.outcome' => ['required', 'in:live,fresh_stillbirth,macerated_stillbirth'],
            'babies.*.birth_weight_g' => ['nullable', 'integer', 'between:300,6500'],
            'babies.*.apgar_1' => ['nullable', 'integer', 'between:0,10'],
            'babies.*.apgar_5' => ['nullable', 'integer', 'between:0,10'],
            'babies.*.apgar_10' => ['nullable', 'integer', 'between:0,10'],
            'babies.*.resuscitated' => ['boolean'],
            'babies.*.vitamin_k' => ['boolean'],
            'babies.*.teo_given' => ['boolean'],
            'babies.*.bcg_given' => ['boolean'],
            'babies.*.opv0_given' => ['boolean'],
            'babies.*.breastfed_within_1h' => ['boolean'],
            'babies.*.chlorhexidine_cord' => ['boolean'],
            'babies.*.notes' => ['nullable', 'string', 'max:500'],
        ]);

        if ($this->delivery['delivery_mode'] === 'caesarean' && ! ModuleRegistry::enabledAt(app(TenantContext::class)->facility(), 'theatre')) {
            $this->addError('delivery.delivery_mode', __('Caesarean sections need a theatre (Level 4+). Refer the mother instead.'));

            return;
        }

        $maternity->recordDelivery($this->labour, $this->delivery, $this->babies);
        $this->labour->refresh();
        Flux::toast(variant: 'success', text: __('Delivery recorded. Live-born babies have been registered as patients.'));
    }

    public function dischargeMother(MaternityService $maternity): void
    {
        abort_unless(Auth::user()->can('maternity.deliver'), 403);
        $maternity->dischargeMother($this->labour);
        Flux::toast(variant: 'success', text: __('Mother discharged — visit sent to billing.'));
    }

    public function refer(MaternityService $maternity): void
    {
        abort_unless(Auth::user()->can('maternity.deliver'), 403);
        $this->validate(['refer_reason' => ['required', 'string', 'max:500']]);
        $maternity->referLabour($this->labour, $this->refer_reason);
        $this->labour->refresh();

        if ($this->labour->encounter_id && Auth::user()->can('referrals.manage')) {
            $this->redirect(route('referrals.create', ['direction' => 'outgoing', 'encounter' => $this->labour->encounter_id]), navigate: true);
        }
    }
};
?>

@php($p = $labour->pregnancy)
<div class="flex h-full w-full flex-1 flex-col gap-4" @if ($this->isOpen()) wire:poll.60s @endif>
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading level="1">{{ __('Labour & partograph') }} <span class="font-mono text-base">{{ $labour->labour_number }}</span></flux:heading>
            <flux:text>{{ $p->obstetricFormula() }} · {{ $labour->gestation_weeks ?? '—' }} {{ __('weeks') }} · {{ __('admitted') }} {{ $labour->admitted_at->format('d M H:i') }} · {{ __('membranes') }} {{ $labour->membranes }}@if ($labour->membranes_ruptured_at) ({{ $labour->membranes_ruptured_at->format('d M H:i') }})@endif · <flux:badge size="sm">{{ str_replace('_', ' ', $labour->status) }}</flux:badge></flux:text>
        </div>
        <div class="flex gap-2">
            <flux:button size="sm" :href="route('maternity.pregnancy', $p)" wire:navigate>{{ __('Pregnancy record') }}</flux:button>
            <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('labour.index')" wire:navigate>{{ __('Labour ward') }}</flux:button>
        </div>
    </div>

    <x-patient-banner :patient="$p->patient" />

    @if ($p->risk_factors)
        <flux:callout variant="danger" icon="exclamation-triangle"><flux:callout.text>{{ __('High-risk') }}: {{ implode('; ', $p->risk_factors) }}</flux:callout.text></flux:callout>
    @endif

    @php($a = $this->alerts)
    @if ($a['action'] || $a['alert'] || $a['critical'])
        <div class="rounded-xl border border-red-300 bg-red-50 p-3 text-sm dark:border-red-900 dark:bg-red-950/40">
            @if ($a['action'])<div class="font-semibold text-red-700">{{ __('ACTION LINE crossed — decide on intervention (augmentation, assisted delivery, C/S or referral).') }}</div>
            @elseif ($a['alert'])<div class="font-semibold text-amber-700">{{ __('Alert line crossed — progress slower than 1 cm/hour. Reassess; consider referral if no theatre.') }}</div>@endif
            @foreach ($a['critical'] as $c)<div class="text-red-700">{{ $c }}</div>@endforeach
        </div>
    @endif

    {{-- Cervicograph --}}
    @php($c = $this->chart)
    <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        <div class="mb-2 text-sm font-medium">{{ __('Cervicograph') }} <span class="text-xs font-normal text-neutral-500">{{ __('cervical dilatation (cm) by hours; amber = alert line, red = action line') }}</span></div>
        <svg viewBox="0 0 520 230" class="w-full max-w-3xl" role="img" aria-label="{{ __('Cervicograph') }}">
            @for ($cm = 0; $cm <= 10; $cm++)
                <line x1="40" x2="510" y1="{{ 210 - $cm * 20 }}" y2="{{ 210 - $cm * 20 }}" stroke="currentColor" stroke-opacity="{{ $cm === 4 ? 0.35 : 0.1 }}" />
                <text x="30" y="{{ 214 - $cm * 20 }}" font-size="9" text-anchor="end" fill="currentColor" opacity="0.6">{{ $cm }}</text>
            @endfor
            @for ($h = 0; $h <= 12; $h++)
                <line y1="10" y2="210" x1="{{ 40 + $h * 39 }}" x2="{{ 40 + $h * 39 }}" stroke="currentColor" stroke-opacity="0.08" />
                <text y="224" x="{{ 40 + $h * 39 }}" font-size="9" text-anchor="middle" fill="currentColor" opacity="0.6">{{ $h }}h</text>
            @endfor
            @foreach (['alert' => '#d97706', 'action' => '#dc2626'] as $key => $color)
                @if ($c[$key])
                    <line x1="{{ 40 + $c[$key][0][0] * 39 }}" y1="{{ 210 - $c[$key][0][1] * 20 }}" x2="{{ 40 + $c[$key][1][0] * 39 }}" y2="{{ 210 - $c[$key][1][1] * 20 }}" stroke="{{ $color }}" stroke-width="2" stroke-dasharray="{{ $key === 'action' ? '6 3' : '0' }}" />
                @endif
            @endforeach
            @if (count($c['points']) > 1)
                <polyline fill="none" stroke="#4f46e5" stroke-width="2" points="{{ collect($c['points'])->map(fn ($pt) => (40 + $pt['x'] * 39).','.(210 - $pt['y'] * 20))->implode(' ') }}" />
            @endif
            @foreach ($c['points'] as $pt)
                <text x="{{ 40 + $pt['x'] * 39 }}" y="{{ 214 - $pt['y'] * 20 }}" font-size="12" text-anchor="middle" fill="#4f46e5">×</text>
            @endforeach
        </svg>
    </div>

    @if ($this->isOpen())
        @can('maternity.deliver')
            <form wire:submit="record" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:heading level="3">{{ __('Partograph entry') }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-6">
                    <flux:input type="datetime-local" wire:model="entry.recorded_at" :label="__('Time')" class="sm:col-span-2" />
                    <flux:input type="number" wire:model="entry.fetal_heart_rate" :label="__('FHR')" />
                    <flux:select wire:model="entry.liquor" :label="__('Liquor')">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach (['I' => 'I — intact', 'C' => 'C — clear', 'M' => 'M — meconium', 'B' => 'B — blood', 'A' => 'A — absent'] as $k => $l)<flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>@endforeach
                    </flux:select>
                    <flux:select wire:model="entry.moulding" :label="__('Moulding')">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach (['0', '+', '++', '+++'] as $o)<flux:select.option value="{{ $o }}">{{ $o }}</flux:select.option>@endforeach
                    </flux:select>
                    <flux:input type="number" step="0.5" wire:model="entry.cervix_cm" :label="__('Cervix cm')" />
                </div>
                <div class="grid gap-3 sm:grid-cols-6">
                    <flux:input type="number" wire:model="entry.descent_fifths" :label="__('Descent /5')" />
                    <flux:input type="number" wire:model="entry.contractions_per_10" :label="__('Contr. /10 min')" />
                    <flux:select wire:model="entry.contraction_strength" :label="__('Duration')">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach (['<20', '20-40', '>40'] as $o)<flux:select.option value="{{ $o }}">{{ $o }} s</flux:select.option>@endforeach
                    </flux:select>
                    <flux:input type="number" step="0.5" wire:model="entry.oxytocin_units" :label="__('Oxytocin U/L')" />
                    <div class="sm:col-span-2"><flux:input wire:model="entry.drugs" :label="__('Drugs / IV fluids')" /></div>
                </div>
                <div class="grid gap-3 sm:grid-cols-6">
                    <flux:input type="number" wire:model="entry.pulse" :label="__('Pulse')" />
                    <flux:input type="number" wire:model="entry.systolic" :label="__('Systolic')" />
                    <flux:input type="number" wire:model="entry.diastolic" :label="__('Diastolic')" />
                    <flux:input type="number" step="0.1" wire:model="entry.temperature" :label="__('Temp')" />
                    <flux:select wire:model="entry.urine_protein" :label="__('Urine protein')">
                        <flux:select.option value="">—</flux:select.option>
                        @foreach (['nil', 'trace', '+', '++', '+++'] as $o)<flux:select.option value="{{ $o }}">{{ $o }}</flux:select.option>@endforeach
                    </flux:select>
                    <flux:input type="number" wire:model="entry.urine_volume_ml" :label="__('Urine ml')" />
                </div>
                @error('entry')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                <div><flux:button type="submit" variant="primary">{{ __('Record') }}</flux:button></div>
            </form>
        @endcan
    @endif

    <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-xs">
            <thead class="bg-neutral-50 uppercase text-neutral-500 dark:bg-neutral-900">
                <tr>@foreach (['Time', 'FHR', 'Liq', 'Mould', 'Cx', 'Desc', 'Contr', 'Oxy', 'Pulse', 'BP', 'Temp', 'Urine', 'By'] as $h)<th class="px-2 py-2">{{ __($h) }}</th>@endforeach</tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse ($this->entries->reverse() as $e)
                    <tr wire:key="e-{{ $e->id }}">
                        <td class="px-2 py-1 whitespace-nowrap">{{ $e->recorded_at->format('d M H:i') }}</td>
                        <td class="px-2 py-1 {{ $e->fetal_heart_rate && ($e->fetal_heart_rate < 110 || $e->fetal_heart_rate > 160) ? 'font-bold text-red-600' : '' }}">{{ $e->fetal_heart_rate }}</td>
                        <td class="px-2 py-1 {{ $e->liquor === 'M' ? 'font-bold text-red-600' : '' }}">{{ $e->liquor }}</td>
                        <td class="px-2 py-1">{{ $e->moulding }}</td>
                        <td class="px-2 py-1">{{ $e->cervix_cm }}</td>
                        <td class="px-2 py-1">{{ $e->descent_fifths }}</td>
                        <td class="px-2 py-1">{{ $e->contractions_per_10 }}@if ($e->contraction_strength) ({{ $e->contraction_strength }}s)@endif</td>
                        <td class="px-2 py-1">{{ $e->oxytocin_units }}</td>
                        <td class="px-2 py-1">{{ $e->pulse }}</td>
                        <td class="px-2 py-1">{{ $e->systolic }}@if ($e->diastolic)/{{ $e->diastolic }}@endif</td>
                        <td class="px-2 py-1">{{ $e->temperature }}</td>
                        <td class="px-2 py-1">{{ $e->urine_protein }}@if ($e->urine_volume_ml) {{ $e->urine_volume_ml }}ml @endif</td>
                        <td class="px-2 py-1">{{ $e->recorder?->name }}</td>
                    </tr>
                @empty
                    <tr><td colspan="13" class="px-3 py-6 text-center text-neutral-500">{{ __('No entries yet.') }}</td></tr>
                @endforelse
            </tbody>
        </table>
    </div>

    @if ($this->isOpen())
        @can('maternity.deliver')
            <form wire:submit="deliver" class="grid gap-3 rounded-xl border border-indigo-300 p-4 dark:border-indigo-800">
                <flux:heading level="3">{{ __('Record delivery') }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-4">
                    <flux:input type="datetime-local" wire:model="delivery.delivered_at" :label="__('Time of birth')" />
                    <flux:select wire:model="delivery.delivery_mode" :label="__('Mode')" class="sm:col-span-2">
                        @foreach (\App\Models\Labour::MODES as $k => $l)<flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>@endforeach
                    </flux:select>
                    <flux:input type="number" wire:model="delivery.blood_loss_ml" :label="__('Blood loss ml')" />
                </div>
                <div class="grid gap-3 sm:grid-cols-4">
                    <flux:select wire:model="delivery.perineum" :label="__('Perineum')">
                        @foreach (['intact' => 'Intact', 'first' => '1st degree tear', 'second' => '2nd degree tear', 'third' => '3rd degree tear', 'fourth' => '4th degree tear', 'episiotomy' => 'Episiotomy', 'na' => 'N/A (C/S)'] as $k => $l)<flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>@endforeach
                    </flux:select>
                    <flux:select wire:model="delivery.mother_condition" :label="__('Mother\'s condition')">
                        @foreach (['stable' => 'Stable', 'pph' => 'Postpartum haemorrhage', 'eclampsia' => 'Eclampsia', 'sepsis' => 'Sepsis', 'ruptured_uterus' => 'Ruptured uterus', 'deceased' => 'Maternal death', 'other' => 'Other'] as $k => $l)<flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>@endforeach
                    </flux:select>
                    <div class="flex flex-col gap-1 pt-5 text-sm sm:col-span-2">
                        <flux:checkbox wire:model="delivery.amtsl" :label="__('AMTSL done')" />
                        <flux:checkbox wire:model="delivery.uterotonic_given" :label="__('Uterotonic (oxytocin) given')" />
                        <flux:checkbox wire:model="delivery.placenta_complete" :label="__('Placenta complete')" />
                    </div>
                </div>
                <flux:input wire:model="delivery.complications" :label="__('Complications / notes')" />

                @foreach ($babies as $i => $b)
                    <div wire:key="baby-{{ $i }}" class="space-y-2 rounded-lg border border-neutral-200 p-3 dark:border-neutral-700">
                        <div class="flex items-center justify-between text-sm font-medium">{{ __('Baby') }} {{ $i + 1 }}
                            @if (count($babies) > 1)<flux:button size="xs" variant="ghost" wire:click="removeBaby({{ $i }})">{{ __('Remove') }}</flux:button>@endif
                        </div>
                        <div class="grid gap-3 sm:grid-cols-6">
                            <flux:select wire:model="babies.{{ $i }}.sex" :label="__('Sex')">
                                <flux:select.option value="female">{{ __('Female') }}</flux:select.option>
                                <flux:select.option value="male">{{ __('Male') }}</flux:select.option>
                                <flux:select.option value="unknown">{{ __('Indeterminate') }}</flux:select.option>
                            </flux:select>
                            <flux:select wire:model="babies.{{ $i }}.outcome" :label="__('Outcome')">
                                <flux:select.option value="live">{{ __('Live birth') }}</flux:select.option>
                                <flux:select.option value="fresh_stillbirth">{{ __('Fresh stillbirth') }}</flux:select.option>
                                <flux:select.option value="macerated_stillbirth">{{ __('Macerated stillbirth') }}</flux:select.option>
                            </flux:select>
                            <flux:input type="number" wire:model="babies.{{ $i }}.birth_weight_g" :label="__('Weight g')" />
                            <flux:input type="number" wire:model="babies.{{ $i }}.apgar_1" :label="__('Apgar 1′')" />
                            <flux:input type="number" wire:model="babies.{{ $i }}.apgar_5" :label="__('Apgar 5′')" />
                            <flux:input type="number" wire:model="babies.{{ $i }}.apgar_10" :label="__('Apgar 10′')" />
                        </div>
                        <div class="flex flex-wrap gap-4 text-sm">
                            @foreach (['resuscitated' => 'Resuscitated', 'vitamin_k' => 'Vitamin K', 'teo_given' => 'TEO (eye)', 'chlorhexidine_cord' => 'Chlorhexidine cord care', 'breastfed_within_1h' => 'Breastfed within 1 h', 'bcg_given' => 'BCG', 'opv0_given' => 'OPV 0'] as $k => $l)
                                <flux:checkbox wire:model="babies.{{ $i }}.{{ $k }}" :label="__($l)" />
                            @endforeach
                        </div>
                    </div>
                @endforeach
                <div><flux:button size="sm" icon="plus" wire:click="addBaby">{{ __('Add another baby (twins/multiples)') }}</flux:button></div>
                @error('delivery.delivery_mode')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                @error('babies')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                <div><flux:button type="submit" variant="primary">{{ __('Record delivery') }}</flux:button></div>
            </form>

            <form wire:submit="refer" class="flex flex-wrap items-end gap-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <div class="flex-1"><flux:input wire:model="refer_reason" :label="__('Refer mother (e.g. prolonged labour, needs C/S)')" /></div>
                <flux:button type="submit" variant="danger" size="sm">{{ __('Refer out') }}</flux:button>
            </form>
        @endcan
    @elseif ($labour->status === 'delivered')
        <div class="rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-700">
            <div class="font-medium">{{ \App\Models\Labour::MODES[$labour->delivery_mode] ?? $labour->delivery_mode }} · {{ $labour->delivered_at->format('d M Y H:i') }} · {{ __('by') }} {{ $labour->deliveredBy?->name }}</div>
            <div class="text-xs">{{ __('Blood loss') }} {{ $labour->blood_loss_ml ?? '—' }} ml · {{ __('perineum') }} {{ $labour->perineum ?? '—' }} · {{ __('mother') }} <strong>{{ $labour->mother_condition }}</strong> · AMTSL {{ $labour->amtsl ? __('yes') : __('no') }}</div>
            @foreach ($labour->newborns()->with('baby')->get() as $b)
                <div class="mt-2 rounded bg-neutral-50 px-3 py-2 text-xs dark:bg-neutral-900">{{ __('Baby') }} {{ $b->birth_order }}: {{ ucfirst($b->sex) }} · {{ $b->birth_weight_g ?? '—' }} g @if ($b->isLowBirthWeight())<flux:badge size="sm" color="amber">LBW</flux:badge>@endif · Apgar {{ $b->apgar_1 ?? '—' }}/{{ $b->apgar_5 ?? '—' }} · {{ str_replace('_', ' ', $b->outcome) }} @if ($b->baby) · <a class="underline" href="{{ route('patients.show', $b->baby) }}" wire:navigate>{{ $b->baby->mrn }}</a>@endif</div>
            @endforeach
            @can('maternity.deliver')
                @if ($labour->encounter && $labour->encounter->stage === \App\Support\EncounterStage::ADMITTED)
                    <div class="mt-3"><flux:button size="sm" wire:click="dischargeMother">{{ __('Discharge mother (send to billing)') }}</flux:button></div>
                @endif
            @endcan
        </div>
    @endif
</div>
