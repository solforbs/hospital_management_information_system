<?php

use App\Services\Patients\PatientRegistrationService;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Register patient')] class extends Component {
    public string $first_name = '';

    public string $middle_name = '';

    public string $last_name = '';

    public ?string $date_of_birth = null;

    public bool $date_of_birth_estimated = false;

    public string $sex = '';

    public string $national_id = '';

    public string $phone = '';

    public string $email = '';

    public string $address = '';

    public string $county = '';

    public string $next_of_kin_name = '';

    public string $next_of_kin_phone = '';

    public string $next_of_kin_relationship = '';

    public string $category = 'general';

    public array $possibleDuplicates = [];

    public bool $confirmNotDuplicate = false;

    public function registerAnyway(PatientRegistrationService $service): void
    {
        $this->confirmNotDuplicate = true;
        $this->register($service);
    }

    public function mount(): void
    {
        abort_unless(Auth::user()->can('patients.create'), 403);
    }

    protected function rules(): array
    {
        return [
            'first_name' => ['required', 'string', 'max:100'],
            'middle_name' => ['nullable', 'string', 'max:100'],
            'last_name' => ['required', 'string', 'max:100'],
            'date_of_birth' => ['nullable', 'date', 'before_or_equal:today'],
            'date_of_birth_estimated' => ['boolean'],
            'sex' => ['required', 'in:male,female,unknown'],
            'national_id' => ['nullable', 'string', 'max:30'],
            'phone' => ['nullable', 'string', 'max:30'],
            'email' => ['nullable', 'email', 'max:255'],
            'address' => ['nullable', 'string', 'max:255'],
            'county' => ['nullable', 'string', 'max:100'],
            'next_of_kin_name' => ['nullable', 'string', 'max:150'],
            'next_of_kin_phone' => ['nullable', 'string', 'max:30'],
            'next_of_kin_relationship' => ['nullable', 'string', 'max:100'],
            'category' => ['required', 'in:general,minor,newborn,unidentified'],
        ];
    }

    public function register(PatientRegistrationService $service): void
    {
        abort_unless(Auth::user()->can('patients.create'), 403);

        $validated = $this->validate();

        if (! $this->confirmNotDuplicate) {
            $matches = $service->findPossibleDuplicates($validated);

            if ($matches->isNotEmpty()) {
                $this->possibleDuplicates = $matches->map(fn ($p) => [
                    'id' => $p->id, 'mrn' => $p->mrn, 'name' => $p->full_name,
                    'dob' => $p->date_of_birth?->format('d M Y'), 'phone' => $p->phone, 'national_id' => $p->national_id,
                ])->all();

                return;
            }
        }

        $context = app(TenantContext::class);
        abort_unless($context->hasTenant(), 403, 'No active hospital selected.');

        $validated['registered_by'] = Auth::id();

        $patient = $service->register($context->tenant(), $validated, $context->facility());

        $this->redirect(route('patients.show', $patient), navigate: true);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <flux:heading level="1">{{ __('Register patient') }}</flux:heading>

    @if ($possibleDuplicates)
        <flux:callout variant="warning" icon="exclamation-triangle" class="max-w-3xl">
            <flux:callout.heading>{{ __('Possible existing patient records') }}</flux:callout.heading>
            <flux:callout.text>
                {{ __('These records match on national ID, name and date of birth, or phone. Open the existing record instead of creating a duplicate.') }}
                <ul class="mt-2 space-y-1">
                    @foreach ($possibleDuplicates as $dup)
                        <li>
                            <a class="font-medium underline" href="{{ route('patients.show', $dup['id']) }}" wire:navigate>{{ $dup['mrn'] }} — {{ $dup['name'] }}</a>
                            <span class="text-xs">{{ $dup['dob'] }} {{ $dup['phone'] }} {{ $dup['national_id'] }}</span>
                        </li>
                    @endforeach
                </ul>
            </flux:callout.text>
            <x-slot name="actions">
                <flux:button size="sm" wire:click="registerAnyway">{{ __('Not a duplicate — register anyway') }}</flux:button>
            </x-slot>
        </flux:callout>
    @endif

    <form wire:submit="register" class="grid max-w-3xl gap-6">
        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="first_name" :label="__('First name')" required autofocus />
            <flux:input wire:model="middle_name" :label="__('Middle name')" />
            <flux:input wire:model="last_name" :label="__('Last name')" required />
        </div>

        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="date_of_birth" type="date" :label="__('Date of birth')" />
            <flux:checkbox wire:model="date_of_birth_estimated" :label="__('Estimated')" />
            <flux:select wire:model="sex" :label="__('Sex')" required>
                <flux:select.option value="">{{ __('Select') }}</flux:select.option>
                <flux:select.option value="male">{{ __('Male') }}</flux:select.option>
                <flux:select.option value="female">{{ __('Female') }}</flux:select.option>
                <flux:select.option value="unknown">{{ __('Unknown') }}</flux:select.option>
            </flux:select>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="national_id" :label="__('National ID / Passport')" />
            <flux:select wire:model="category" :label="__('Category')" required>
                <flux:select.option value="general">{{ __('General') }}</flux:select.option>
                <flux:select.option value="minor">{{ __('Minor') }}</flux:select.option>
                <flux:select.option value="newborn">{{ __('Newborn') }}</flux:select.option>
                <flux:select.option value="unidentified">{{ __('Unidentified / Emergency') }}</flux:select.option>
            </flux:select>
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="phone" :label="__('Phone')" />
            <flux:input wire:model="email" type="email" :label="__('Email')" />
        </div>

        <div class="grid gap-4 sm:grid-cols-2">
            <flux:input wire:model="address" :label="__('Address')" />
            <flux:input wire:model="county" :label="__('County')" />
        </div>

        <flux:separator />

        <flux:heading level="3">{{ __('Next of kin') }}</flux:heading>

        <div class="grid gap-4 sm:grid-cols-3">
            <flux:input wire:model="next_of_kin_name" :label="__('Name')" />
            <flux:input wire:model="next_of_kin_phone" :label="__('Phone')" />
            <flux:input wire:model="next_of_kin_relationship" :label="__('Relationship')" />
        </div>

        <div class="flex items-center gap-3">
            <flux:button variant="primary" type="submit">{{ __('Register patient') }}</flux:button>
            <flux:button variant="ghost" :href="route('patients.index')" wire:navigate>{{ __('Cancel') }}</flux:button>
        </div>
    </form>
</div>
