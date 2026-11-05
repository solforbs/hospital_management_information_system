<?php

use App\Models\GrowthMeasurement;
use App\Models\HeiEnrolment;
use App\Models\HeiTest;
use App\Models\Immunization;
use App\Models\Patient;
use App\Services\Maternity\ChildWelfareService;
use App\Support\KepiSchedule;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Child health card')] class extends Component {
    public Patient $patient;

    public ?string $vaccine = null;

    public array $dose = ['given_on' => '', 'batch_number' => '', 'site' => '', 'given_elsewhere' => false];

    public array $growth = ['weight_kg' => '', 'height_cm' => '', 'muac_cm' => '', 'head_circumference_cm' => '', 'vitamin_a' => false, 'deworming' => false, 'notes' => ''];

    public string $hei_test = 'pcr_6wk';

    public string $hei_outcome = 'negative_discharged';

    public function mount(Patient $patient): void
    {
        abort_unless(Auth::user()->can('cwc.manage'), 403);
        abort_unless($patient->date_of_birth && $patient->date_of_birth->gt(now()->subYears(6)), 404, __('The child welfare card is for children under 5.'));
        $this->patient = $patient;
        $this->dose['given_on'] = now()->toDateString();
    }

    #[Computed]
    public function schedule(): array
    {
        $given = Immunization::where('patient_id', $this->patient->id)->get()->mapWithKeys(fn ($i) => [$i->vaccine => $i->given_on])->all();
        $regional = (bool) data_get(app(\App\Services\Tenancy\TenantContext::class)->tenant()?->settings, 'cwc.regional_vaccines', false);

        return KepiSchedule::forChild($this->patient->date_of_birth, $given, $regional || collect($given)->keys()->contains(fn ($k) => str_starts_with($k, 'malaria') || $k === 'yellow_fever'));
    }

    #[Computed]
    public function measurements()
    {
        return GrowthMeasurement::where('patient_id', $this->patient->id)->orderByDesc('measured_on')->get();
    }

    #[Computed]
    public function hei(): ?HeiEnrolment
    {
        return HeiEnrolment::with('tests')->where('patient_id', $this->patient->id)->first();
    }

    public function giveVaccine(ChildWelfareService $cwc): void
    {
        abort_unless(Auth::user()->can('cwc.manage'), 403);
        $this->validate([
            'vaccine' => ['required', 'string'],
            'dose.given_on' => ['required', 'date', 'before_or_equal:today'],
            'dose.batch_number' => ['nullable', 'string', 'max:40'],
            'dose.site' => ['nullable', 'string', 'max:40'],
            'dose.given_elsewhere' => ['boolean'],
        ]);
        $cwc->immunize($this->patient, $this->vaccine, $this->dose);
        $this->reset('vaccine');
        $this->dose = ['given_on' => now()->toDateString(), 'batch_number' => '', 'site' => '', 'given_elsewhere' => false];
        unset($this->schedule);
        Flux::toast(variant: 'success', text: __('Vaccine recorded.'));
    }

    public function saveGrowth(ChildWelfareService $cwc): void
    {
        abort_unless(Auth::user()->can('cwc.manage'), 403);
        $this->validate([
            'growth.weight_kg' => ['nullable', 'numeric', 'between:0.4,40'],
            'growth.height_cm' => ['nullable', 'numeric', 'between:25,130'],
            'growth.muac_cm' => ['nullable', 'numeric', 'between:6,25'],
            'growth.head_circumference_cm' => ['nullable', 'numeric', 'between:20,60'],
            'growth.vitamin_a' => ['boolean'],
            'growth.deworming' => ['boolean'],
            'growth.notes' => ['nullable', 'string', 'max:255'],
        ]);
        $m = $cwc->recordGrowth($this->patient, $this->growth);
        $this->growth = ['weight_kg' => '', 'height_cm' => '', 'muac_cm' => '', 'head_circumference_cm' => '', 'vitamin_a' => false, 'deworming' => false, 'notes' => ''];
        unset($this->measurements);

        in_array($m->nutrition_status, ['sam', 'mam'], true)
            ? Flux::toast(variant: 'danger', text: $m->nutrition_status === 'sam' ? __('Severe acute malnutrition (MUAC < 11.5 cm) — refer for OTP/stabilisation.') : __('Moderate acute malnutrition — enrol in supplementary feeding.'))
            : Flux::toast(variant: 'success', text: __('Growth recorded.'));
    }

    public function enrolHei(ChildWelfareService $cwc): void
    {
        abort_unless(Auth::user()->can('cwc.manage'), 403);
        $cwc->enrolHei($this->patient, null);
        unset($this->hei);
    }

    public function addHeiTest(ChildWelfareService $cwc): void
    {
        $cwc->addHeiTest($this->hei, $this->hei_test);
        unset($this->hei);
    }

    public function heiResult(int $testId, string $result, ChildWelfareService $cwc): void
    {
        $cwc->recordHeiResult(HeiTest::where('hei_enrolment_id', $this->hei->id)->findOrFail($testId), $result);
        unset($this->hei);
    }

    public function closeHei(ChildWelfareService $cwc): void
    {
        $cwc->closeHei($this->hei, $this->hei_outcome);
        unset($this->hei);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Child health card') }}</flux:heading>
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('cwc.index')" wire:navigate>{{ __('Child welfare clinic') }}</flux:button>
    </div>

    <x-patient-banner :patient="$patient" />

    <div class="grid gap-6 xl:grid-cols-5">
        <div class="space-y-3 xl:col-span-3">
            <flux:heading level="3">{{ __('Immunisation (KEPI)') }}</flux:heading>
            <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
                <table class="w-full text-left text-sm">
                    <thead><tr><th class="px-3 py-2">{{ __('Vaccine') }}</th><th class="px-3 py-2">{{ __('Age') }}</th><th class="px-3 py-2">{{ __('Due') }}</th><th class="px-3 py-2">{{ __('Status') }}</th><th></th></tr></thead>
                    <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                        @foreach ($this->schedule as $key => $v)
                            <tr wire:key="v-{{ $key }}">
                                <td class="px-3 py-1.5">{{ $v['label'] }}</td>
                                <td class="px-3 py-1.5 text-xs text-zinc-500">{{ $v['age'] }}</td>
                                <td class="px-3 py-1.5 text-xs">{{ $v['due']->format('d M Y') }}</td>
                                <td class="px-3 py-1.5">
                                    @if ($v['status'] === 'given')
                                        <flux:badge size="sm" color="lime">{{ __('Given') }} {{ $v['given_on']->format('d M Y') }}</flux:badge>
                                    @else
                                        <flux:badge size="sm" :color="match($v['status']) { 'overdue' => 'red', 'due' => 'amber', default => 'zinc' }">{{ __(ucfirst($v['status'])) }}</flux:badge>
                                    @endif
                                </td>
                                <td class="px-3 py-1.5 text-right">
                                    @if ($v['status'] !== 'given')<flux:button size="xs" wire:click="$set('vaccine', '{{ $key }}')">{{ __('Record') }}</flux:button>@endif
                                </td>
                            </tr>
                        @endforeach
                    </tbody>
                </table>
            </div>

            @if ($vaccine)
                <form wire:submit="giveVaccine" class="grid gap-3 rounded-xl border border-indigo-300 p-4 dark:border-indigo-800">
                    <flux:heading level="3">{{ \App\Support\KepiSchedule::label($vaccine) }}</flux:heading>
                    <div class="grid gap-3 sm:grid-cols-3">
                        <flux:input type="date" wire:model="dose.given_on" :label="__('Date given')" />
                        <flux:input wire:model="dose.batch_number" :label="__('Batch / lot no.')" />
                        <flux:input wire:model="dose.site" :label="__('Site')" :placeholder="__('e.g. left thigh')" />
                    </div>
                    <flux:checkbox wire:model="dose.given_elsewhere" :label="__('Given at another facility (transcribed from the child\'s booklet)')" />
                    @error('vaccine')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                    @error('given_on')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
                    <div class="flex gap-2"><flux:button type="submit" variant="primary" size="sm">{{ __('Save') }}</flux:button><flux:button size="sm" variant="ghost" wire:click="$set('vaccine', null)">{{ __('Cancel') }}</flux:button></div>
                </form>
            @endif
        </div>

        <div class="space-y-6 xl:col-span-2">
            <form wire:submit="saveGrowth" class="grid gap-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:heading level="3">{{ __('Growth monitoring') }}</flux:heading>
                <div class="grid gap-3 sm:grid-cols-2">
                    <flux:input type="number" step="0.01" wire:model="growth.weight_kg" :label="__('Weight (kg)')" />
                    <flux:input type="number" step="0.1" wire:model="growth.height_cm" :label="__('Length/height (cm)')" />
                    <flux:input type="number" step="0.1" wire:model="growth.muac_cm" :label="__('MUAC (cm)')" />
                    <flux:input type="number" step="0.1" wire:model="growth.head_circumference_cm" :label="__('Head circ. (cm)')" />
                </div>
                <div class="flex gap-4"><flux:checkbox wire:model="growth.vitamin_a" :label="__('Vitamin A')" /><flux:checkbox wire:model="growth.deworming" :label="__('Dewormed')" /></div>
                <div><flux:button type="submit" size="sm" variant="primary">{{ __('Record') }}</flux:button></div>
            </form>
            <div class="space-y-1">
                @foreach ($this->measurements as $m)
                    <div wire:key="g-{{ $m->id }}" class="flex justify-between rounded-lg bg-zinc-50 px-3 py-1.5 text-xs dark:bg-zinc-800">
                        <span>{{ $m->measured_on->format('d M Y') }}</span>
                        <span>{{ $m->weight_kg ? $m->weight_kg.' kg' : '' }} {{ $m->height_cm ? '· '.$m->height_cm.' cm' : '' }} {{ $m->muac_cm ? '· MUAC '.$m->muac_cm : '' }}
                            @if ($m->nutrition_status && $m->nutrition_status !== 'normal')<flux:badge size="sm" color="red">{{ strtoupper($m->nutrition_status) }}</flux:badge>@endif</span>
                    </div>
                @endforeach
            </div>

            <div class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                <flux:heading level="3">{{ __('HIV-exposed infant (HEI)') }}</flux:heading>
                @if ($h = $this->hei)
                    <div class="text-sm"><span class="font-mono">{{ $h->hei_number }}</span> · {{ __('enrolled') }} {{ $h->enrolled_on->format('d M Y') }} · <flux:badge size="sm" :color="$h->status === 'active' ? 'amber' : ($h->status === 'negative_discharged' ? 'lime' : 'red')">{{ str_replace('_', ' ', $h->status) }}</flux:badge></div>
                    <div class="text-xs text-zinc-500">{{ __('Prophylaxis') }}: {{ $h->infant_prophylaxis ?? '—' }}</div>
                    @foreach ($h->tests as $t)
                        <div wire:key="ht-{{ $t->id }}" class="flex items-center justify-between rounded-lg bg-zinc-50 px-3 py-1.5 text-xs dark:bg-zinc-800">
                            <span>{{ \App\Models\HeiEnrolment::TESTS[$t->test][0] }} · {{ $t->sample_date->format('d M Y') }}</span>
                            @if ($t->result === 'pending' && $h->status === 'active')
                                <span class="flex gap-1"><flux:button size="xs" wire:click="heiResult({{ $t->id }}, 'negative')">{{ __('Negative') }}</flux:button><flux:button size="xs" variant="danger" wire:click="heiResult({{ $t->id }}, 'positive')" wire:confirm="{{ __('Record a POSITIVE result? The infant will be linked to HIV care.') }}">{{ __('Positive') }}</flux:button></span>
                            @else
                                <flux:badge size="sm" :color="$t->result === 'positive' ? 'red' : 'lime'">{{ $t->result }}</flux:badge>
                            @endif
                        </div>
                    @endforeach
                    @if ($h->status === 'active')
                        <div class="flex flex-wrap items-end gap-2">
                            <flux:select wire:model="hei_test" size="sm" class="max-w-56">
                                @foreach (\App\Models\HeiEnrolment::TESTS as $k => [$l])<flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>@endforeach
                            </flux:select>
                            <flux:button size="sm" wire:click="addHeiTest">{{ __('Sample taken') }}</flux:button>
                        </div>
                        <div class="flex flex-wrap items-end gap-2">
                            <flux:select wire:model="hei_outcome" size="sm" class="max-w-64">
                                @foreach (\App\Models\HeiEnrolment::OUTCOMES as $k => $l)<flux:select.option value="{{ $k }}">{{ __($l) }}</flux:select.option>@endforeach
                            </flux:select>
                            <flux:button size="sm" variant="ghost" wire:click="closeHei">{{ __('Close follow-up') }}</flux:button>
                        </div>
                    @endif
                @else
                    <flux:text class="text-sm">{{ __('Not enrolled. Babies of HIV-positive mothers are enrolled automatically at delivery.') }}</flux:text>
                    <flux:button size="sm" wire:click="enrolHei">{{ __('Enrol in HEI follow-up') }}</flux:button>
                @endif
            </div>
        </div>
    </div>
</div>
