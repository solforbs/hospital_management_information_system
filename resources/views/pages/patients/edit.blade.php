<?php

use App\Models\Patient;
use App\Services\Audit\AuditLogger;
use App\Services\Patients\PatientRegistrationService;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Edit patient')] class extends Component {
    public Patient $patient;

    public array $form = [];

    public array $possibleDuplicates = [];

    protected array $fields = [
        'first_name', 'middle_name', 'last_name', 'date_of_birth', 'date_of_birth_estimated', 'sex',
        'national_id', 'phone', 'alternative_phone', 'email', 'address', 'county',
        'next_of_kin_name', 'next_of_kin_phone', 'next_of_kin_relationship', 'category', 'preferred_language',
    ];

    public function mount(Patient $patient): void
    {
        abort_unless(Auth::user()->can('patients.update'), 403);

        $this->patient = $patient;
        foreach ($this->fields as $field) {
            $value = $patient->{$field};
            $this->form[$field] = $value instanceof \DateTimeInterface ? $value->format('Y-m-d') : $value;
        }
        $this->form['date_of_birth_estimated'] = (bool) $patient->date_of_birth_estimated;
    }

    protected function rules(): array
    {
        return [
            'form.first_name' => ['required', 'string', 'max:100'],
            'form.middle_name' => ['nullable', 'string', 'max:100'],
            'form.last_name' => ['required', 'string', 'max:100'],
            'form.date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'form.date_of_birth_estimated' => ['boolean'],
            'form.sex' => ['required', 'in:male,female,unknown'],
            'form.national_id' => ['nullable', 'string', 'max:30'],
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.alternative_phone' => ['nullable', 'string', 'max:30'],
            'form.email' => ['nullable', 'email', 'max:255'],
            'form.address' => ['nullable', 'string', 'max:255'],
            'form.county' => ['nullable', 'string', 'max:100'],
            'form.next_of_kin_name' => ['nullable', 'string', 'max:150'],
            'form.next_of_kin_phone' => ['nullable', 'string', 'max:30'],
            'form.next_of_kin_relationship' => ['nullable', 'string', 'max:100'],
            'form.category' => ['required', 'in:general,minor,newborn,unidentified'],
            'form.preferred_language' => ['nullable', 'string', 'max:50'],
        ];
    }

    public function save(PatientRegistrationService $registration, AuditLogger $audit): void
    {
        abort_unless(Auth::user()->can('patients.update'), 403);

        $data = $this->validate()['form'];
        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);

        $this->possibleDuplicates = $registration->findPossibleDuplicates($data, $this->patient->id)
            ->map(fn ($p) => ['id' => $p->id, 'mrn' => $p->mrn, 'name' => $p->full_name])->all();

        $this->patient->update($data);

        $audit->log('patient.updated', $this->patient, ['fields' => array_keys($this->patient->getChanges())]);

        Flux::toast(variant: 'success', text: __('Patient details updated.'));

        if (! $this->possibleDuplicates) {
            $this->redirect(route('patients.show', $this->patient), navigate: true);
        }
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading level="1">{{ __('Edit patient') }}</flux:heading>
            <flux:text class="font-mono">{{ $patient->mrn }}</flux:text>
        </div>
        <flux:button variant="ghost" :href="route('patients.show', $patient)" wire:navigate>{{ __('Back') }}</flux:button>
    </div>

    @if ($possibleDuplicates)
        <flux:callout variant="warning" icon="exclamation-triangle" class="max-w-3xl">
            <flux:callout.heading>{{ __('Saved — but these records look like the same person') }}</flux:callout.heading>
            <flux:callout.text>
                @foreach ($possibleDuplicates as $dup)
                    <a class="underline" href="{{ route('patients.show', $dup['id']) }}" wire:navigate>{{ $dup['mrn'] }} — {{ $dup['name'] }}</a>@if (! $loop->last), @endif
                @endforeach
                <div class="mt-1">{{ __('Ask a records officer to review and merge if appropriate.') }}</div>
            </flux:callout.text>
        </flux:callout>
    @endif

    <form wire:submit="save" class="grid max-w-3xl gap-6">
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="form.first_name" :label="__('First name')" required />
            <flux:input wire:model="form.middle_name" :label="__('Middle name')" />
            <flux:input wire:model="form.last_name" :label="__('Last name')" required />
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="form.date_of_birth" type="date" :label="__('Date of birth')" />
            <flux:checkbox wire:model="form.date_of_birth_estimated" :label="__('Estimated')" />
            <flux:select wire:model="form.sex" :label="__('Sex')" required>
                <flux:select.option value="male">{{ __('Male') }}</flux:select.option>
                <flux:select.option value="female">{{ __('Female') }}</flux:select.option>
                <flux:select.option value="unknown">{{ __('Unknown') }}</flux:select.option>
            </flux:select>
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="form.national_id" :label="__('National ID / Passport')" />
            <flux:select wire:model="form.category" :label="__('Category')">
                <flux:select.option value="general">{{ __('General') }}</flux:select.option>
                <flux:select.option value="minor">{{ __('Minor') }}</flux:select.option>
                <flux:select.option value="newborn">{{ __('Newborn') }}</flux:select.option>
                <flux:select.option value="unidentified">{{ __('Unidentified / Emergency') }}</flux:select.option>
            </flux:select>
            <flux:input wire:model="form.preferred_language" :label="__('Preferred language')" />
        </div>
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="form.phone" :label="__('Phone')" />
            <flux:input wire:model="form.alternative_phone" :label="__('Alternative phone')" />
            <flux:input wire:model="form.email" type="email" :label="__('Email')" />
        </div>
        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="form.address" :label="__('Address')" />
            <flux:input wire:model="form.county" :label="__('County')" />
        </div>
        <flux:heading level="3">{{ __('Next of kin') }}</flux:heading>
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="form.next_of_kin_name" :label="__('Name')" />
            <flux:input wire:model="form.next_of_kin_phone" :label="__('Phone')" />
            <flux:input wire:model="form.next_of_kin_relationship" :label="__('Relationship')" />
        </div>
        <div>
            <flux:button variant="primary" type="submit">{{ __('Save changes') }}</flux:button>
        </div>
    </form>
</div>
