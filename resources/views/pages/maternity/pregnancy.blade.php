<?php

use App\Models\Newborn;
use App\Models\PncVisit;
use App\Models\Pregnancy;
use App\Services\Maternity\MaternityService;
use App\Support\ModuleRegistry;
use App\Services\Tenancy\TenantContext;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Pregnancy record')] class extends Component {
    public Pregnancy $pregnancy;

    #[Url]
    public string $tab = 'anc';

    public array $anc = [];

    public array $pnc = [];

    public array $labourForm = ['onset' => 'spontaneous', 'membranes' => 'intact', 'payer_type' => 'cash', 'notes' => ''];

    public string $close_outcome = 'miscarriage';

    public function mount(Pregnancy $pregnancy): void
    {
        abort_unless(Auth::user()->canAny(['maternity.view', 'maternity.manage']), 403);
        $this->pregnancy = $pregnancy->load('patient');
        $this->resetAnc();
        $this->resetPnc();
        if ($pregnancy->status === 'delivered' && $this->tab === 'anc') {
            $this->tab = 'delivery';
        }
    }

    protected function resetAnc(): void
    {
        $this->anc = ['visit_date' => now()->toDateString(), 'weight_kg' => '', 'systolic' => '', 'diastolic' => '', 'fundal_height_cm' => '', 'fetal_heart_rate' => '', 'presentation' => '', 'fetal_movement' => '', 'urine_protein' => '', 'hb' => '', 'iptp_given' => false, 'td_dose' => '', 'ifas_given' => false, 'deworming' => false, 'llin_given' => false, 'danger_signs' => [], 'notes' => '', 'next_visit_date' => now()->addWeeks(4)->toDateString()];
    }

    protected function resetPnc(): void
    {
        $this->pnc = ['visit_date' => now()->toDateString(), 'timing' => '48h', 'newborn_id' => '', 'systolic' => '', 'diastolic' => '', 'temperature' => '', 'uterus' => '', 'lochia' => '', 'breasts' => '', 'perineum_wound' => '', 'baby_weight_kg' => '', 'baby_temperature' => '', 'cord' => '', 'feeding' => '', 'family_planning_counselled' => false, 'family_planning_method' => '', 'danger_signs' => [], 'notes' => ''];
    }

    #[Computed]
    public function visits()
    {
        return $this->pregnancy->ancVisits()->with('recorder')->get();
    }

    #[Computed]
    public function labours()
    {
        return $this->pregnancy->labours()->with(['newborns.baby', 'deliveredBy'])->latest('admitted_at')->get();
    }

    #[Computed]
    public function newborns()
    {
        return Newborn::with('baby')->whereHas('labour', fn ($q) => $q->where('pregnancy_id', $this->pregnancy->id))->orderBy('birth_order')->get();
    }

    #[Computed]
    public function pncVisits()
    {
        return $this->pregnancy->pncVisits()->with(['newborn', 'recorder'])->get();
    }

    public function labourWardEnabled(): bool
    {
        return ModuleRegistry::enabledAt(app(TenantContext::class)->facility(), 'labour-ward');
    }

    public function saveAnc(MaternityService $maternity): void
    {
        abort_unless(Auth::user()->can('maternity.manage'), 403);

        $data = $this->validate([
            'anc.visit_date' => ['required', 'date', 'before_or_equal:today'],
            'anc.weight_kg' => ['nullable', 'numeric', 'between:25,200'],
            'anc.systolic' => ['nullable', 'integer', 'between:50,260'],
            'anc.diastolic' => ['nullable', 'integer', 'between:30,180'],
            'anc.fundal_height_cm' => ['nullable', 'integer', 'between:5,50'],
            'anc.fetal_heart_rate' => ['nullable', 'integer', 'between:50,220'],
            'anc.presentation' => ['nullable', 'in:cephalic,breech,transverse,unknown'],
            'anc.fetal_movement' => ['nullable', 'in:0,1'],
            'anc.urine_protein' => ['nullable', 'in:nil,trace,+,++,+++'],
            'anc.hb' => ['nullable', 'numeric', 'between:2,20'],
            'anc.iptp_given' => ['boolean'],
            'anc.td_dose' => ['nullable', 'integer', 'between:1,5'],
            'anc.ifas_given' => ['boolean'],
            'anc.deworming' => ['boolean'],
            'anc.llin_given' => ['boolean'],
            'anc.danger_signs' => ['array'],
            'anc.danger_signs.*' => ['in:'.implode(',', array_keys(MaternityService::DANGER_SIGNS))],
            'anc.notes' => ['nullable', 'string', 'max:2000'],
            'anc.next_visit_date' => ['nullable', 'date', 'after:anc.visit_date'],
        ])['anc'];

        $data['danger_signs'] = $data['danger_signs'] ?: null;
        $before = $this->pregnancy->risk_level;
        $maternity->recordAncVisit($this->pregnancy, $data);
        $this->pregnancy->refresh();
        $this->resetAnc();
        unset($this->visits);

        $this->pregnancy->risk_level === 'high' && $before !== 'high'
            ? Flux::toast(variant: 'danger', text: __('Visit saved — pregnancy is now HIGH RISK. Review the risk factors.'))
            : Flux::toast(variant: 'success', text: __('ANC visit saved.'));
    }

    public function startLabour(MaternityService $maternity): void
    {
        abort_unless(Auth::user()->can('maternity.deliver'), 403);
        abort_unless($this->labourWardEnabled(), 403);
        $this->validate([
            'labourForm.onset' => ['required', 'in:spontaneous,induced,none'],
            'labourForm.membranes' => ['required', 'in:intact,ruptured'],
            'labourForm.payer_type' => ['required', 'in:cash,insurance,corporate,waiver'],
            'labourForm.notes' => ['nullable', 'string', 'max:1000'],
        ]);

        $labour = $maternity->startLabour($this->pregnancy, $this->labourForm);
        $this->redirect(route('labour.show', $labour), navigate: true);
    }

    public function savePnc(MaternityService $maternity): void
    {
        abort_unless(Auth::user()->can('maternity.manage'), 403);

        $data = $this->validate([
            'pnc.visit_date' => ['required', 'date', 'before_or_equal:today'],
            'pnc.timing' => ['required', 'in:'.implode(',', array_keys(PncVisit::TIMINGS))],
            'pnc.newborn_id' => ['nullable', 'integer'],
            'pnc.systolic' => ['nullable', 'integer', 'between:50,260'],
            'pnc.diastolic' => ['nullable', 'integer', 'between:30,180'],
            'pnc.temperature' => ['nullable', 'numeric', 'between:30,43'],
            'pnc.uterus' => ['nullable', 'in:involuted,involuting,tender,not_involuting'],
            'pnc.lochia' => ['nullable', 'in:normal,foul,excessive,none'],
            'pnc.breasts' => ['nullable', 'in:normal,engorged,cracked_nipples,mastitis'],
            'pnc.perineum_wound' => ['nullable', 'in:healed,healing,infected,breakdown,na'],
            'pnc.baby_weight_kg' => ['nullable', 'numeric', 'between:0.4,15'],
            'pnc.baby_temperature' => ['nullable', 'numeric', 'between:30,43'],
            'pnc.cord' => ['nullable', 'in:clean,infected,fallen'],
            'pnc.feeding' => ['nullable', 'in:exclusive_breastfeeding,mixed,replacement'],
            'pnc.family_planning_counselled' => ['boolean'],
            'pnc.family_planning_method' => ['nullable', 'string', 'max:60'],
            'pnc.danger_signs' => ['array'],
            'pnc.danger_signs.*' => ['in:'.implode(',', array_keys(MaternityService::DANGER_SIGNS))],
            'pnc.notes' => ['nullable', 'string', 'max:2000'],
        ])['pnc'];

        $data['danger_signs'] = $data['danger_signs'] ?: null;
        $maternity->recordPnc($this->pregnancy, $data);
        $this->resetPnc();
        unset($this->pncVisits);
        Flux::toast(variant: 'success', text: __('Postnatal visit saved.'));
    }

    public function closePregnancy(MaternityService $maternity): void
    {
        abort_unless(Auth::user()->can('maternity.manage'), 403);
        $maternity->closePregnancy($this->pregnancy, $this->close_outcome);
        $this->pregnancy->refresh();
        Flux::toast(text: __('Pregnancy record closed.'));
    }
};
?>

@php($p = $pregnancy)
<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex flex-wrap items-center justify-between gap-3">
        <div>
            <flux:heading level="1">{{ __('Pregnancy record') }} <span class="font-mono text-base">{{ $p->anc_number }}</span></flux:heading>
            <flux:text>{{ $p->obstetricFormula() }} · LMP {{ $p->lmp?->format('d M Y') ?? '—' }} · EDD {{ $p->edd?->format('d M Y') }} @if ($p->edd_by_ultrasound) ({{ __('ultrasound') }}) @endif
                @if ($p->status === 'active') · <strong>{{ $p->gestationWeeksOn() }} {{ __('weeks') }}</strong> @endif
                · <flux:badge size="sm">{{ ucfirst($p->status) }}</flux:badge>
            </flux:text>
        </div>
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('maternity.index')" wire:navigate>{{ __('ANC / PNC clinic') }}</flux:button>
    </div>

    <x-patient-banner :patient="$p->patient" />

    <div class="grid gap-4 lg:grid-cols-3">
        <div class="rounded-xl border p-4 text-sm {{ $p->isHighRisk() ? 'border-red-300 bg-red-50 dark:border-red-900 dark:bg-red-950/40' : 'border-neutral-200 dark:border-neutral-700' }}">
            <div class="font-medium">{{ $p->isHighRisk() ? __('HIGH-RISK pregnancy') : __('Low risk') }}</div>
            @if ($p->risk_factors)
                <ul class="mt-1 list-disc pl-5 text-xs">@foreach ($p->risk_factors as $f)<li>{{ $f }}</li>@endforeach</ul>
            @else
                <div class="text-xs text-neutral-500">{{ __('No risk factors identified.') }}</div>
            @endif
        </div>
        <div class="rounded-xl border border-neutral-200 p-4 text-xs dark:border-neutral-700 lg:col-span-2">
            <div class="grid gap-2 sm:grid-cols-3">
                <div>{{ __('Blood group') }}: <strong>{{ $p->blood_group ?? '—' }} {{ $p->rhesus ? ($p->rhesus === 'positive' ? 'Rh+' : 'Rh−') : '' }}</strong></div>
                <div>{{ __('HIV') }}: <strong>{{ ucfirst($p->hiv_status ?? 'not tested') }}</strong></div>
                <div>{{ __('Syphilis') }}: <strong>{{ ucfirst($p->syphilis_status ?? 'not tested') }}</strong></div>
                <div>{{ __('Hepatitis B') }}: <strong>{{ ucfirst($p->hepatitis_b_status ?? 'not tested') }}</strong></div>
                <div>{{ __('Height') }}: <strong>{{ $p->height_cm ?? '—' }} cm</strong></div>
                <div>{{ __('Living children') }}: <strong>{{ $p->living_children }}</strong> · {{ __('Prev. C/S') }}: <strong>{{ $p->previous_caesareans }}</strong></div>
                <div class="sm:col-span-3">{{ __('Birth plan') }}: <strong>{{ $p->birth_plan_facility ?? '—' }}</strong></div>
            </div>
        </div>
    </div>

    <div class="flex flex-wrap gap-1 border-b border-neutral-200 dark:border-neutral-700">
        @foreach (['anc' => __('ANC visits').' ('.$this->visits->count().')', 'delivery' => __('Labour & delivery'), 'pnc' => __('Postnatal').' ('.$this->pncVisits->count().')'] as $k => $l)
            <button type="button" wire:click="$set('tab', '{{ $k }}')" class="-mb-px border-b-2 px-3 py-2 text-sm {{ $tab === $k ? 'border-indigo-500 font-medium' : 'border-transparent text-neutral-500' }}">{{ $l }}</button>
        @endforeach
    </div>

    @if ($tab === 'anc')
        <div class="overflow-x-auto rounded-xl border border-neutral-200 dark:border-neutral-700">
            <table class="w-full text-left text-xs">
                <thead class="bg-neutral-50 uppercase text-neutral-500 dark:bg-neutral-900">
                    <tr>@foreach (['#', 'Date', 'GA', 'Wt', 'BP', 'FH', 'FHR', 'Pres.', 'Urine', 'Hb', 'Given', 'Danger', 'Next'] as $h)<th class="px-2 py-2">{{ __($h) }}</th>@endforeach</tr>
                </thead>
                <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                    @forelse ($this->visits as $v)
                        <tr wire:key="v-{{ $v->id }}">
                            <td class="px-2 py-1">{{ $v->visit_number }}</td>
                            <td class="px-2 py-1 whitespace-nowrap">{{ $v->visit_date->format('d M Y') }}</td>
                            <td class="px-2 py-1">{{ $v->gestation_weeks }}</td>
                            <td class="px-2 py-1">{{ $v->weight_kg }}</td>
                            <td class="px-2 py-1 {{ ($v->systolic >= 140 || $v->diastolic >= 90) ? 'font-bold text-red-600' : '' }}">{{ $v->systolic }}@if ($v->diastolic)/{{ $v->diastolic }}@endif</td>
                            <td class="px-2 py-1">{{ $v->fundal_height_cm }}</td>
                            <td class="px-2 py-1">{{ $v->fetal_heart_rate }}</td>
                            <td class="px-2 py-1">{{ $v->presentation }}</td>
                            <td class="px-2 py-1">{{ $v->urine_protein }}</td>
                            <td class="px-2 py-1 {{ $v->hb !== null && $v->hb < 11 ? 'font-bold text-red-600' : '' }}">{{ $v->hb }}</td>
                            <td class="px-2 py-1">{{ collect(['IPTp' => $v->iptp_given, 'IFAS' => $v->ifas_given, 'Deworm' => $v->deworming, 'LLIN' => $v->llin_given])->filter()->keys()->implode(', ') }}@if ($v->td_dose) · Td{{ $v->td_dose }}@endif</td>
                            <td class="px-2 py-1 text-red-600">{{ collect($v->danger_signs ?? [])->map(fn ($d) => MaternityService::DANGER_SIGNS[$d] ?? $d)->implode(', ') }}</td>
                            <td class="px-2 py-1 whitespace-nowrap">{{ $v->next_visit_date?->format('d M') }}</td>
                        </tr>
                    @empty
                        <tr><td colspan="13" class="px-3 py-6 text-center text-neutral-500">{{ __('No ANC visits yet. WHO recommends at least 8 contacts.') }}</td></tr>
                    @endforelse
                </tbody>
            </table>
        </div>

        @if ($p->status === 'active')
            @can('maternity.manage')
                <form wire:submit="saveAnc" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('ANC visit') }} {{ $this->visits->count() + 1 }}</flux:heading>
                    <div class="grid gap-3 sm:grid-cols-6">
                        <flux:input type="date" wire:model="anc.visit_date" :label="__('Date')" class="sm:col-span-2" />
                        <flux:input type="number" step="0.1" wire:model="anc.weight_kg" :label="__('Weight kg')" />
                        <flux:input type="number" wire:model="anc.systolic" :label="__('Systolic')" />
                        <flux:input type="number" wire:model="anc.diastolic" :label="__('Diastolic')" />
                        <flux:input type="number" wire:model="anc.fundal_height_cm" :label="__('Fundal ht cm')" />
                    </div>
                    <div class="grid gap-3 sm:grid-cols-6">
                        <flux:input type="number" wire:model="anc.fetal_heart_rate" :label="__('FHR')" />
                        <flux:select wire:model="anc.presentation" :label="__('Presentation')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach (['cephalic', 'breech', 'transverse', 'unknown'] as $o)<flux:select.option value="{{ $o }}">{{ ucfirst($o) }}</flux:select.option>@endforeach
                        </flux:select>
                        <flux:select wire:model="anc.fetal_movement" :label="__('Fetal movement')">
                            <flux:select.option value="">—</flux:select.option>
                            <flux:select.option value="1">{{ __('Present') }}</flux:select.option>
                            <flux:select.option value="0">{{ __('Absent') }}</flux:select.option>
                        </flux:select>
                        <flux:select wire:model="anc.urine_protein" :label="__('Urine protein')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach (['nil', 'trace', '+', '++', '+++'] as $o)<flux:select.option value="{{ $o }}">{{ $o }}</flux:select.option>@endforeach
                        </flux:select>
                        <flux:input type="number" step="0.1" wire:model="anc.hb" :label="__('Hb g/dL')" />
                        <flux:input type="number" wire:model="anc.td_dose" :label="__('Td dose #')" />
                    </div>
                    <div class="flex flex-wrap gap-4 text-sm">
                        <flux:checkbox wire:model="anc.iptp_given" :label="__('IPTp-SP given')" />
                        <flux:checkbox wire:model="anc.ifas_given" :label="__('Iron + folic acid')" />
                        <flux:checkbox wire:model="anc.deworming" :label="__('Deworming')" />
                        <flux:checkbox wire:model="anc.llin_given" :label="__('LLIN (mosquito net)')" />
                    </div>
                    <div>
                        <div class="mb-1 text-sm font-medium">{{ __('Danger signs') }}</div>
                        <div class="grid gap-1 sm:grid-cols-3">
                            @foreach (MaternityService::DANGER_SIGNS as $k => $l)
                                <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="anc.danger_signs" value="{{ $k }}" class="rounded" /> {{ __($l) }}</label>
                            @endforeach
                        </div>
                    </div>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <div class="sm:col-span-2"><flux:input wire:model="anc.notes" :label="__('Notes / counselling')" /></div>
                        <flux:input type="date" wire:model="anc.next_visit_date" :label="__('Next visit')" />
                    </div>
                    @error('visit')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                    <div><flux:button type="submit" variant="primary">{{ __('Save visit') }}</flux:button></div>
                </form>

                <form wire:submit="closePregnancy" class="flex flex-wrap items-end gap-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:select wire:model="close_outcome" :label="__('Close record (no delivery here)')" class="max-w-xs">
                        <flux:select.option value="miscarriage">{{ __('Miscarriage') }}</flux:select.option>
                        <flux:select.option value="ectopic">{{ __('Ectopic pregnancy') }}</flux:select.option>
                        <flux:select.option value="transferred_out">{{ __('Transferred out') }}</flux:select.option>
                        <flux:select.option value="lost_to_follow_up">{{ __('Lost to follow-up') }}</flux:select.option>
                    </flux:select>
                    <flux:button type="submit" size="sm" variant="danger" wire:confirm="{{ __('Close this pregnancy record?') }}">{{ __('Close record') }}</flux:button>
                </form>
            @endcan
        @endif
    @elseif ($tab === 'delivery')
        @foreach ($this->labours as $l)
            <div wire:key="l-{{ $l->id }}" class="space-y-2 rounded-xl border border-neutral-200 p-4 text-sm dark:border-neutral-700">
                <div class="flex items-center justify-between">
                    <div><span class="font-mono">{{ $l->labour_number }}</span> · {{ __('admitted') }} {{ $l->admitted_at->format('d M Y H:i') }} · <flux:badge size="sm">{{ str_replace('_', ' ', $l->status) }}</flux:badge></div>
                    <flux:button size="xs" :href="route('labour.show', $l)" wire:navigate>{{ $l->status === 'in_labour' ? __('Open partograph') : __('View') }}</flux:button>
                </div>
                @if ($l->delivered_at)
                    <div>{{ \App\Models\Labour::MODES[$l->delivery_mode] ?? $l->delivery_mode }} · {{ $l->delivered_at->format('d M Y H:i') }} · {{ __('blood loss') }} {{ $l->blood_loss_ml ?? '—' }} ml · {{ __('mother') }} {{ $l->mother_condition }}</div>
                    @foreach ($l->newborns as $b)
                        <div class="rounded bg-neutral-50 px-3 py-2 text-xs dark:bg-neutral-900">
                            {{ __('Baby') }} {{ $b->birth_order }}: {{ ucfirst($b->sex) }} · {{ $b->birth_weight_g ?? '—' }} g @if ($b->isLowBirthWeight())<flux:badge size="sm" color="amber">LBW</flux:badge>@endif · Apgar {{ $b->apgar_1 ?? '—' }}/{{ $b->apgar_5 ?? '—' }} · {{ str_replace('_', ' ', $b->outcome) }}
                            @if ($b->baby)· <a class="underline" href="{{ route('patients.show', $b->baby) }}" wire:navigate>{{ $b->baby->mrn }}</a>@endif
                        </div>
                    @endforeach
                @endif
            </div>
        @endforeach

        @if ($p->status === 'active' && ! $this->labours->contains('status', 'in_labour'))
            @can('maternity.deliver')
                @if ($this->labourWardEnabled())
                    <form wire:submit="startLabour" class="grid max-w-2xl gap-3 rounded-xl border border-indigo-300 p-4 dark:border-indigo-800">
                        <flux:heading level="3">{{ __('Admit to labour ward') }}</flux:heading>
                        <div class="grid gap-3 sm:grid-cols-3">
                            <flux:select wire:model="labourForm.onset" :label="__('Onset')">
                                <flux:select.option value="spontaneous">{{ __('Spontaneous') }}</flux:select.option>
                                <flux:select.option value="induced">{{ __('Induced') }}</flux:select.option>
                                <flux:select.option value="none">{{ __('Not in labour (elective C/S)') }}</flux:select.option>
                            </flux:select>
                            <flux:select wire:model="labourForm.membranes" :label="__('Membranes')">
                                <flux:select.option value="intact">{{ __('Intact') }}</flux:select.option>
                                <flux:select.option value="ruptured">{{ __('Ruptured') }}</flux:select.option>
                            </flux:select>
                            <flux:select wire:model="labourForm.payer_type" :label="__('Payer')">
                                <flux:select.option value="cash">{{ __('Cash') }}</flux:select.option>
                                <flux:select.option value="insurance">{{ __('SHA / insurance') }}</flux:select.option>
                                <flux:select.option value="corporate">{{ __('Corporate') }}</flux:select.option>
                                <flux:select.option value="waiver">{{ __('Waiver') }}</flux:select.option>
                            </flux:select>
                        </div>
                        <flux:input wire:model="labourForm.notes" :label="__('Admission notes')" />
                        @error('labour')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                        <div><flux:button type="submit" variant="primary">{{ __('Admit to labour ward') }}</flux:button></div>
                    </form>
                @else
                    <flux:callout variant="warning" icon="information-circle"><flux:callout.text>{{ __('Deliveries are not offered at this facility level. Refer the mother to a facility with a labour ward (Level 3+), using Referrals.') }}</flux:callout.text></flux:callout>
                @endif
            @endcan
        @elseif ($this->labours->isEmpty())
            <flux:text>{{ __('No labour recorded.') }}</flux:text>
        @endif
    @else
        @if ($p->status === 'delivered')
            @can('maternity.manage')
                <form wire:submit="savePnc" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                    <flux:heading level="3">{{ __('Postnatal visit') }}</flux:heading>
                    <div class="grid gap-3 sm:grid-cols-4">
                        <flux:input type="date" wire:model="pnc.visit_date" :label="__('Date')" />
                        <flux:select wire:model="pnc.timing" :label="__('Timing')">
                            @foreach (PncVisit::TIMINGS as $k => $l)<flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>@endforeach
                        </flux:select>
                        <flux:select wire:model="pnc.newborn_id" :label="__('Baby')" class="sm:col-span-2">
                            <flux:select.option value="">{{ __('Mother only') }}</flux:select.option>
                            @foreach ($this->newborns->where('outcome', 'live') as $b)<flux:select.option value="{{ $b->id }}">{{ __('Baby') }} {{ $b->birth_order }} ({{ $b->baby?->mrn }})</flux:select.option>@endforeach
                        </flux:select>
                    </div>
                    <div class="text-xs font-medium uppercase text-neutral-500">{{ __('Mother') }}</div>
                    <div class="grid gap-3 sm:grid-cols-6">
                        <flux:input type="number" wire:model="pnc.systolic" :label="__('Systolic')" />
                        <flux:input type="number" wire:model="pnc.diastolic" :label="__('Diastolic')" />
                        <flux:input type="number" step="0.1" wire:model="pnc.temperature" :label="__('Temp')" />
                        @foreach (['uterus' => ['involuted', 'involuting', 'tender', 'not_involuting'], 'lochia' => ['normal', 'foul', 'excessive', 'none'], 'breasts' => ['normal', 'engorged', 'cracked_nipples', 'mastitis']] as $field => $opts)
                            <flux:select wire:model="pnc.{{ $field }}" :label="__(ucfirst($field))">
                                <flux:select.option value="">—</flux:select.option>
                                @foreach ($opts as $o)<flux:select.option value="{{ $o }}">{{ ucfirst(str_replace('_', ' ', $o)) }}</flux:select.option>@endforeach
                            </flux:select>
                        @endforeach
                    </div>
                    <div class="grid gap-3 sm:grid-cols-6">
                        <flux:select wire:model="pnc.perineum_wound" :label="__('Perineum / wound')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach (['healed', 'healing', 'infected', 'breakdown', 'na'] as $o)<flux:select.option value="{{ $o }}">{{ $o === 'na' ? 'N/A' : ucfirst($o) }}</flux:select.option>@endforeach
                        </flux:select>
                        <flux:checkbox wire:model="pnc.family_planning_counselled" :label="__('FP counselled')" />
                        <flux:input wire:model="pnc.family_planning_method" :label="__('FP method')" class="sm:col-span-2" />
                    </div>
                    <div class="text-xs font-medium uppercase text-neutral-500">{{ __('Baby') }}</div>
                    <div class="grid gap-3 sm:grid-cols-4">
                        <flux:input type="number" step="0.01" wire:model="pnc.baby_weight_kg" :label="__('Weight kg')" />
                        <flux:input type="number" step="0.1" wire:model="pnc.baby_temperature" :label="__('Temp')" />
                        <flux:select wire:model="pnc.cord" :label="__('Cord')">
                            <flux:select.option value="">—</flux:select.option>
                            @foreach (['clean', 'infected', 'fallen'] as $o)<flux:select.option value="{{ $o }}">{{ ucfirst($o) }}</flux:select.option>@endforeach
                        </flux:select>
                        <flux:select wire:model="pnc.feeding" :label="__('Feeding')">
                            <flux:select.option value="">—</flux:select.option>
                            <flux:select.option value="exclusive_breastfeeding">{{ __('Exclusive breastfeeding') }}</flux:select.option>
                            <flux:select.option value="mixed">{{ __('Mixed') }}</flux:select.option>
                            <flux:select.option value="replacement">{{ __('Replacement') }}</flux:select.option>
                        </flux:select>
                    </div>
                    <div class="grid gap-1 sm:grid-cols-3">
                        @foreach (MaternityService::DANGER_SIGNS as $k => $l)
                            <label class="flex items-center gap-2 text-sm"><input type="checkbox" wire:model="pnc.danger_signs" value="{{ $k }}" class="rounded" /> {{ __($l) }}</label>
                        @endforeach
                    </div>
                    <flux:input wire:model="pnc.notes" :label="__('Notes')" />
                    @error('pnc')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                    <div><flux:button type="submit" variant="primary">{{ __('Save postnatal visit') }}</flux:button></div>
                </form>
            @endcan
        @else
            <flux:text>{{ __('Postnatal visits are recorded after delivery.') }}</flux:text>
        @endif

        <div class="space-y-2">
            @foreach ($this->pncVisits as $v)
                <div wire:key="pnc-{{ $v->id }}" class="rounded-lg border border-neutral-200 p-3 text-xs dark:border-neutral-700">
                    <strong>{{ PncVisit::TIMINGS[$v->timing] ?? $v->timing }}</strong> · {{ $v->visit_date->format('d M Y') }} @if ($v->newborn) · {{ __('Baby') }} {{ $v->newborn->birth_order }} @endif
                    · BP {{ $v->systolic ?? '—' }}/{{ $v->diastolic ?? '—' }} · {{ __('uterus') }} {{ $v->uterus ?? '—' }} · {{ __('lochia') }} {{ $v->lochia ?? '—' }}
                    @if ($v->baby_weight_kg) · {{ __('baby') }} {{ $v->baby_weight_kg }} kg @endif @if ($v->feeding) · {{ str_replace('_', ' ', $v->feeding) }} @endif
                    @if ($v->family_planning_counselled) · FP {{ $v->family_planning_method ?? __('counselled') }} @endif
                    @if ($v->danger_signs)<div class="text-red-600">{{ collect($v->danger_signs)->map(fn ($d) => MaternityService::DANGER_SIGNS[$d] ?? $d)->implode(', ') }}</div>@endif
                </div>
            @endforeach
        </div>
    @endif
</div>
