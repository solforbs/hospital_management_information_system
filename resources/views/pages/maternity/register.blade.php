<?php

use App\Models\Patient;
use App\Services\Maternity\MaternityService;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;

new #[Title('Register pregnancy')] class extends Component {
    #[Url]
    public ?int $patient = null;

    public string $patientSearch = '';

    public array $form = [
        'lmp' => '', 'edd' => '', 'gravida' => '1', 'parity' => '0', 'abortions' => '0', 'living_children' => '0',
        'previous_caesareans' => '0', 'blood_group' => '', 'rhesus' => '', 'hiv_status' => '', 'syphilis_status' => '',
        'hepatitis_b_status' => '', 'height_cm' => '', 'birth_plan_facility' => '',
    ];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('maternity.manage'), 403);
    }

    #[Computed]
    public function patientModel(): ?Patient
    {
        return $this->patient ? Patient::find($this->patient) : null;
    }

    #[Computed]
    public function matches()
    {
        if (mb_strlen($this->patientSearch) < 2) {
            return collect();
        }
        $term = '%'.$this->patientSearch.'%';

        return Patient::where('sex', 'female')
            ->where(fn ($q) => $q->where('first_name', 'like', $term)->orWhere('last_name', 'like', $term)->orWhere('mrn', 'like', $term)->orWhere('national_id', 'like', $term)->orWhere('phone', 'like', $term))
            ->limit(8)->get();
    }

    public function selectPatient(int $id): void
    {
        $this->patient = Patient::where('sex', 'female')->findOrFail($id)->id;
        $this->patientSearch = '';
    }

    public function previewEdd(): ?string
    {
        if ($this->form['edd']) {
            return $this->form['edd'];
        }

        try {
            return $this->form['lmp'] ? \Illuminate\Support\Carbon::parse($this->form['lmp'])->addDays(280)->format('d M Y') : null;
        } catch (\Throwable) {
            return null;
        }
    }

    public function save(MaternityService $maternity): void
    {
        abort_unless(Auth::user()->can('maternity.manage'), 403);

        $data = $this->validate([
            'form.lmp' => ['nullable', 'date', 'before_or_equal:today', 'required_without:form.edd'],
            'form.edd' => ['nullable', 'date', 'after:today'],
            'form.gravida' => ['required', 'integer', 'between:1,20'],
            'form.parity' => ['required', 'integer', 'between:0,19'],
            'form.abortions' => ['required', 'integer', 'between:0,19'],
            'form.living_children' => ['required', 'integer', 'between:0,20'],
            'form.previous_caesareans' => ['required', 'integer', 'between:0,10'],
            'form.blood_group' => ['nullable', 'in:A,B,AB,O'],
            'form.rhesus' => ['nullable', 'in:positive,negative'],
            'form.hiv_status' => ['nullable', 'in:negative,positive,unknown'],
            'form.syphilis_status' => ['nullable', 'in:negative,positive,unknown'],
            'form.hepatitis_b_status' => ['nullable', 'in:negative,positive,unknown'],
            'form.height_cm' => ['nullable', 'numeric', 'between:100,220'],
            'form.birth_plan_facility' => ['nullable', 'string', 'max:150'],
        ], [], ['form.lmp' => 'LMP', 'form.edd' => 'EDD'])['form'];

        if (! $this->patientModel) {
            $this->addError('patient', __('Select the mother.'));

            return;
        }

        $data = array_map(fn ($v) => $v === '' ? null : $v, $data);
        $pregnancy = $maternity->registerPregnancy($this->patientModel, $data);

        $this->redirect(route('maternity.pregnancy', $pregnancy), navigate: true);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Register pregnancy (ANC booking)') }}</flux:heading>
        <flux:button size="sm" variant="ghost" icon="arrow-left" :href="route('maternity.index')" wire:navigate>{{ __('ANC / PNC clinic') }}</flux:button>
    </div>

    <div class="max-w-3xl space-y-2 rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
        @if ($this->patientModel)
            <div class="flex items-center justify-between text-sm">
                <div><span class="font-medium">{{ $this->patientModel->full_name }}</span> <span class="font-mono text-xs text-neutral-500">{{ $this->patientModel->mrn }}</span> · {{ $this->patientModel->date_of_birth?->age ?? '—' }} {{ __('yrs') }}</div>
                <flux:button size="xs" variant="ghost" wire:click="$set('patient', null)">{{ __('Change') }}</flux:button>
            </div>
        @else
            <flux:input wire:model.live.debounce.300ms="patientSearch" :label="__('Find the mother')" :placeholder="__('Name, MRN, ID or phone')" />
            @foreach ($this->matches as $p)
                <button type="button" wire:key="m-{{ $p->id }}" wire:click="selectPatient({{ $p->id }})" class="block w-full rounded px-2 py-1 text-left text-sm hover:bg-neutral-50 dark:hover:bg-neutral-800">{{ $p->full_name }} <span class="font-mono text-xs text-neutral-500">{{ $p->mrn }}</span></button>
            @endforeach
            <flux:text class="text-xs">{{ __('New client?') }} <a class="underline" href="{{ route('patients.create') }}" wire:navigate>{{ __('Register her first') }}</a>.</flux:text>
        @endif
        @error('patient')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
    </div>

    <form wire:submit="save" class="grid max-w-3xl gap-5">
        <div class="grid gap-3 sm:grid-cols-3">
            <flux:input type="date" wire:model.live="form.lmp" :label="__('LMP (first day of last period)')" />
            <flux:input type="date" wire:model.live="form.edd" :label="__('EDD by ultrasound (optional)')" />
            <div class="pt-6 text-sm">{{ __('EDD') }}: <strong>{{ $this->previewEdd() ?? '—' }}</strong></div>
        </div>
        <div class="grid gap-3 sm:grid-cols-5">
            <flux:input type="number" wire:model="form.gravida" :label="__('Gravida')" />
            <flux:input type="number" wire:model="form.parity" :label="__('Parity')" />
            <flux:input type="number" wire:model="form.abortions" :label="__('Abortions')" />
            <flux:input type="number" wire:model="form.living_children" :label="__('Living children')" />
            <flux:input type="number" wire:model="form.previous_caesareans" :label="__('Previous C/S')" />
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            <flux:select wire:model="form.blood_group" :label="__('Blood group')">
                <flux:select.option value="">—</flux:select.option>
                @foreach (['A', 'B', 'AB', 'O'] as $g)<flux:select.option value="{{ $g }}">{{ $g }}</flux:select.option>@endforeach
            </flux:select>
            <flux:select wire:model="form.rhesus" :label="__('Rhesus')">
                <flux:select.option value="">—</flux:select.option>
                <flux:select.option value="positive">{{ __('Positive') }}</flux:select.option>
                <flux:select.option value="negative">{{ __('Negative') }}</flux:select.option>
            </flux:select>
            <flux:input type="number" step="0.1" wire:model="form.height_cm" :label="__('Height (cm)')" />
        </div>
        <div class="grid gap-3 sm:grid-cols-3">
            @foreach (['hiv_status' => __('HIV'), 'syphilis_status' => __('Syphilis (VDRL)'), 'hepatitis_b_status' => __('Hepatitis B')] as $field => $label)
                <flux:select wire:model="form.{{ $field }}" :label="$label">
                    <flux:select.option value="">{{ __('Not tested') }}</flux:select.option>
                    <flux:select.option value="negative">{{ __('Negative') }}</flux:select.option>
                    <flux:select.option value="positive">{{ __('Positive') }}</flux:select.option>
                    <flux:select.option value="unknown">{{ __('Declined / unknown') }}</flux:select.option>
                </flux:select>
            @endforeach
        </div>
        <flux:input wire:model="form.birth_plan_facility" :label="__('Birth plan — planned place of delivery')" />
        @error('gravida')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
        @error('lmp')<flux:text class="text-red-600">{{ $message }}</flux:text>@enderror
        <div><flux:button type="submit" variant="primary">{{ __('Register pregnancy') }}</flux:button></div>
    </form>
</div>
