<?php

use App\Models\Department;
use App\Models\Facility;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Departments')] class extends Component {
    public bool $showForm = false;

    public string $name = '';

    public string $code = '';

    public string $type = 'clinical';

    public ?int $facility_id = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('departments.manage'), 403);
    }

    public function getFacilitiesProperty()
    {
        return Facility::orderBy('name')->get();
    }

    public function getDepartmentsProperty()
    {
        return Department::with('facility')->orderBy('name')->get();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'code' => ['required', 'string', 'max:20', 'alpha_dash'],
            'type' => ['required', 'in:clinical,ancillary,administrative'],
            'facility_id' => ['required', 'exists:facilities,id'],
        ];
    }

    public function create(): void
    {
        abort_unless(Auth::user()->can('departments.manage'), 403);

        $validated = $this->validate();

        $facility = Facility::findOrFail($validated['facility_id']);

        if (Department::where('facility_id', $facility->id)->where('code', $validated['code'])->exists()) {
            $this->addError('code', __('This department code is already used in this facility.'));

            return;
        }

        $facility->departments()->create($validated);

        $this->reset(['name', 'code', 'type', 'facility_id', 'showForm']);
    }

    public function toggleActive(Department $department): void
    {
        abort_unless(Auth::user()->can('departments.manage'), 403);

        $department->update(['active' => ! $department->active]);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Departments') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="$set('showForm', true)">
            {{ __('Add department') }}
        </flux:button>
    </div>

    @if ($showForm)
        <form wire:submit="create" class="grid max-w-2xl gap-4 rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Name')" required autofocus />
                <flux:input wire:model="code" :label="__('Code')" required />
            </div>
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:select wire:model="facility_id" :label="__('Facility')" required>
                    <flux:select.option value="">{{ __('Select facility') }}</flux:select.option>
                    @foreach ($this->facilities as $facility)
                        <flux:select.option value="{{ $facility->id }}">{{ $facility->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="type" :label="__('Type')">
                    <flux:select.option value="clinical">{{ __('Clinical') }}</flux:select.option>
                    <flux:select.option value="ancillary">{{ __('Ancillary') }}</flux:select.option>
                    <flux:select.option value="administrative">{{ __('Administrative') }}</flux:select.option>
                </flux:select>
            </div>
            <div class="flex gap-3">
                <flux:button variant="primary" type="submit">{{ __('Save department') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Facility') }}</th>
                    <th class="px-4 py-3">{{ __('Type') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @foreach ($this->departments as $department)
                    <tr wire:key="department-{{ $department->id }}">
                        <td class="px-4 py-3">{{ $department->name }}</td>
                        <td class="px-4 py-3">{{ $department->facility->name }}</td>
                        <td class="px-4 py-3">{{ ucfirst($department->type) }}</td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$department->active ? 'lime' : 'zinc'">
                                {{ $department->active ? __('Active') : __('Inactive') }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            <flux:button size="sm" variant="ghost" wire:click="toggleActive({{ $department->id }})">
                                {{ $department->active ? __('Deactivate') : __('Activate') }}
                            </flux:button>
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
