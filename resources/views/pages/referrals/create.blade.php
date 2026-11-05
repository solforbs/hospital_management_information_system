<?php

use App\Models\Encounter;
use App\Models\Patient;
use App\Services\Clinical\ReferralService;
use App\Services\Hie\HieClient;
use App\Services\Hie\HieException;
use App\Services\Hie\RegistryService;
use App\Services\Tenancy\TenantContext;
use App\Support\FacilityLevel;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('New referral')] class extends Component {
    #[Url]
    public string $direction = 'outgoing';

    #[Url]
    public ?int $encounter = null;

    #[Url]
    public ?int $patient = null;

    public string $patientSearch = '';

    public string $other_facility_name = '';

    public string $other_facility_code = '';

    public string $other_facility_level = '';

    public string $department = '';

    public string $urgency = 'routine';

    public string $reason = '';

    public string $clinical_summary = '';

    public string $investigations = '';

    public string $treatment_given = '';

    public string $transport = '';

    public string $referring_clinician = '';

    public string $facilityQuery = '';

    public array $facilityResults = [];

    public ?string $facilityMessage = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('referrals.manage'), 403);

        if ($this->direction === 'outgoing' && $this->encounter) {
            $enc = $this->encounterModel;
            abort_unless($enc, 404);

            // Pre-fill the clinical summary from the visit.
            $dx = $enc->diagnoses()->get()->map(fn ($d) => trim(($d->code ? $d->code.' ' : '').$d->description))->implode('; ');
            $this->clinical_summary = trim(($enc->chief_complaint ? __('Presenting complaint: :c', ['c' => $enc->chief_complaint])."\n" : '').($dx ? __('Diagnoses: :d', ['d' => $dx]) : ''));
            $this->urgency = $enc->priority === 'emergency' ? 'emergency' : ($enc->priority === 'urgent' ? 'urgent' : 'routine');
            $this->other_facility_level = (string) min(6, (int) (app(TenantContext::class)->facility()?->level ?? 4) + 1);
        }
    }

    #[Computed]
    public function encounterModel(): ?Encounter
    {
        return $this->encounter ? Encounter::with('patient')->find($this->encounter) : null;
    }

    #[Computed]
    public function patientModel(): ?Patient
    {
        return $this->patient ? Patient::find($this->patient) : null;
    }

    #[Computed]
    public function patientMatches()
    {
        if (mb_strlen($this->patientSearch) < 2) {
            return collect();
        }

        $term = '%'.$this->patientSearch.'%';

        return Patient::where(fn ($q) => $q->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('mrn', 'like', $term)->orWhere('national_id_hash', \App\Models\Patient::identifierHash(trim(str_replace('%', '', $term))))->orWhere('phone', 'like', $term))
            ->limit(8)->get();
    }

    public function selectPatient(int $id): void
    {
        $this->patient = Patient::findOrFail($id)->id;
        $this->patientSearch = '';
    }

    public function hieEnabled(): bool
    {
        return app(HieClient::class)->isEnabled();
    }

    public function searchFacilities(): void
    {
        $this->validate(['facilityQuery' => ['required', 'string', 'min:3', 'max:100']]);

        try {
            $this->facilityResults = app(RegistryService::class)->searchFacilities($this->facilityQuery);
            $this->facilityMessage = $this->facilityResults ? null : __('No facilities found in the Facility Registry.');
        } catch (HieException $e) {
            $this->facilityMessage = $e->getMessage();
        }
    }

    public function pickFacility(int $index): void
    {
        $f = $this->facilityResults[$index] ?? null;
        if ($f) {
            $this->other_facility_name = $f['name'];
            $this->other_facility_code = (string) $f['code'];
            $this->other_facility_level = (string) ($f['level'] ?? '');
            $this->facilityResults = [];
        }
    }

    public function levelWarning(): ?string
    {
        $own = app(TenantContext::class)->facility()?->level;

        if ($this->direction === 'outgoing' && $own && $this->other_facility_level !== '' && (int) $this->other_facility_level <= $own) {
            return __('You are referring to a facility at the same or a lower level (:level). That is normally a back-referral — check the destination.', ['level' => 'L'.$this->other_facility_level]);
        }

        return null;
    }

    public function save(bool $send = false): void
    {
        abort_unless(Auth::user()->can('referrals.manage'), 403);

        $data = $this->validate([
            'direction' => ['required', 'in:outgoing,incoming'],
            'other_facility_name' => ['required', 'string', 'max:200'],
            'other_facility_code' => ['nullable', 'string', 'max:40'],
            'other_facility_level' => ['nullable', 'integer', 'between:1,6'],
            'department' => ['nullable', 'string', 'max:120'],
            'urgency' => ['required', 'in:routine,urgent,emergency'],
            'reason' => ['required', 'string', 'max:2000'],
            'clinical_summary' => ['nullable', 'string', 'max:5000'],
            'investigations' => ['nullable', 'string', 'max:5000'],
            'treatment_given' => ['nullable', 'string', 'max:5000'],
            'transport' => ['nullable', 'in:ambulance,private,public,self'],
            'referring_clinician' => ['nullable', 'string', 'max:150'],
        ]);
        $data['other_facility_level'] = $data['other_facility_level'] !== '' && $data['other_facility_level'] !== null ? (int) $data['other_facility_level'] : null;

        $service = app(ReferralService::class);

        if ($this->direction === 'outgoing') {
            $encounter = $this->encounterModel;
            if (! $encounter) {
                $this->addError('encounter', __('Outgoing referrals are made from a patient visit.'));

                return;
            }
            $referral = $service->createOutgoing($encounter, $data);
            if ($send) {
                $service->send($referral);
            }
        } else {
            $patient = $this->patientModel;
            if (! $patient) {
                $this->addError('patient', __('Select the referred patient (register them first if new).'));

                return;
            }
            $referral = $service->createIncoming($patient, $data);
        }

        $this->redirect(route('referrals.show', $referral), navigate: true);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ $direction === 'outgoing' ? __('Refer patient out') : __('Register incoming referral') }}</flux:heading>
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('referrals.index')" wire:navigate>{{ __('Referrals') }}</flux:button>
    </div>

    @if ($direction === 'outgoing')
        @if ($this->encounterModel)
            <x-patient-banner :patient="$this->encounterModel->patient" :encounter="$this->encounterModel" />
        @else
            <flux:callout variant="warning" icon="exclamation-triangle"><flux:callout.text>{{ __('Open the patient\'s visit (doctor worklist or ward) and choose "Refer" to start an outgoing referral.') }}</flux:callout.text></flux:callout>
        @endif
    @else
        <div class="max-w-3xl space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            @if ($this->patientModel)
                <div class="flex items-center justify-between text-sm">
                    <div><span class="font-medium">{{ $this->patientModel->full_name }}</span> <span class="font-mono text-xs text-neutral-500">{{ $this->patientModel->mrn }}</span></div>
                    <flux:button size="xs" variant="ghost" wire:click="$set('patient', null)">{{ __('Change') }}</flux:button>
                </div>
            @else
                <flux:input wire:model.live.debounce.300ms="patientSearch" :label="__('Find the referred patient')" :placeholder="__('Name, MRN, ID or phone')" />
                @foreach ($this->patientMatches as $p)
                    <button type="button" wire:key="pm-{{ $p->id }}" wire:click="selectPatient({{ $p->id }})" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800">{{ $p->full_name }} <span class="font-mono text-xs text-neutral-500">{{ $p->mrn }}</span></button>
                @endforeach
                <flux:text class="text-xs">{{ __('Not registered yet?') }} <a class="underline" href="{{ route('patients.create') }}" wire:navigate>{{ __('Register the patient') }}</a> {{ __('first.') }}</flux:text>
            @endif
            @error('patient')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
        </div>
    @endif

    <form wire:submit="save" class="grid max-w-3xl gap-5">
        <div class="space-y-3 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
            <flux:heading level="3">{{ $direction === 'outgoing' ? __('Referred to') : __('Referred from') }}</flux:heading>
            @if ($this->hieEnabled())
                <div class="flex items-end gap-2">
                    <flux:input wire:model="facilityQuery" :label="__('Search the national Facility Registry')" :placeholder="__('Facility name')" class="flex-1" />
                    <flux:button wire:click="searchFacilities" icon="magnifying-glass">{{ __('Search') }}</flux:button>
                </div>
                @if ($facilityMessage)<flux:text class="text-xs">{{ $facilityMessage }}</flux:text>@endif
                @foreach ($facilityResults as $i => $f)
                    <button type="button" wire:key="fr-{{ $i }}" wire:click="pickFacility({{ $i }})" class="block w-full rounded border border-neutral-200 px-3 py-2 text-left text-sm hover:bg-neutral-50 dark:border-neutral-700 dark:hover:bg-neutral-800">
                        {{ $f['name'] }} <span class="text-xs text-neutral-500">{{ $f['code'] }} · {{ \App\Support\FacilityLevel::short($f['level']) }} · SHA {{ $f['sha_contract_status'] ?? '—' }}</span>
                    </button>
                @endforeach
            @endif
            <div class="grid gap-3 sm:grid-cols-3">
                <div class="sm:col-span-2"><flux:input wire:model="other_facility_name" :label="__('Facility name')" /></div>
                <flux:input wire:model="other_facility_code" :label="__('FR / MFL code')" />
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:select wire:model.live="other_facility_level" :label="__('Facility level')">
                    <flux:select.option value="">{{ __('Unknown') }}</flux:select.option>
                    @foreach (FacilityLevel::options() as $k => $l)
                        <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:input wire:model="department" :label="__('Service / specialty')" :placeholder="__('e.g. ICU, Surgery, Oncology')" />
                <flux:select wire:model="urgency" :label="__('Urgency')">
                    <flux:select.option value="routine">{{ __('Routine') }}</flux:select.option>
                    <flux:select.option value="urgent">{{ __('Urgent') }}</flux:select.option>
                    <flux:select.option value="emergency">{{ __('Emergency') }}</flux:select.option>
                </flux:select>
            </div>
            @if ($w = $this->levelWarning())<flux:text class="text-sm text-amber-600">{{ $w }}</flux:text>@endif
        </div>

        <flux:textarea wire:model="reason" :label="__('Reason for referral')" rows="2" />
        <flux:textarea wire:model="clinical_summary" :label="__('Clinical summary (history, findings, diagnoses)')" rows="4" />
        <div class="grid gap-3 sm:grid-cols-2">
            <flux:textarea wire:model="investigations" :label="__('Investigations done / results')" rows="3" />
            <flux:textarea wire:model="treatment_given" :label="__('Treatment given')" rows="3" />
        </div>
        <div class="grid gap-3 sm:grid-cols-2">
            <flux:select wire:model="transport" :label="__('Mode of transport')">
                <flux:select.option value="">—</flux:select.option>
                <flux:select.option value="ambulance">{{ __('Ambulance') }}</flux:select.option>
                <flux:select.option value="private">{{ __('Private vehicle') }}</flux:select.option>
                <flux:select.option value="public">{{ __('Public transport') }}</flux:select.option>
                <flux:select.option value="self">{{ __('Walk-in / self') }}</flux:select.option>
            </flux:select>
            <flux:input wire:model="referring_clinician" :label="$direction === 'outgoing' ? __('Referring clinician (defaults to you)') : __('Referring clinician')" />
        </div>
        @error('encounter')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
        @error('reason')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror

        <div class="flex gap-2">
            @if ($direction === 'outgoing')
                <flux:button variant="primary" wire:click="save(true)">{{ __('Send referral') }}</flux:button>
                <flux:button type="submit">{{ __('Save draft') }}</flux:button>
            @else
                <flux:button type="submit" variant="primary">{{ __('Register referral') }}</flux:button>
            @endif
        </div>
    </form>
</div>
