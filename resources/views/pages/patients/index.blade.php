<?php

use App\Models\Patient;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Title;
use Livewire\Attributes\Url;
use Livewire\Component;
use Livewire\WithPagination;

new #[Title('Patients')] class extends Component {
    use WithPagination;

    #[Url]
    public string $search = '';

    public function mount(): void
    {
        abort_unless(Auth::user()->can('patients.view'), 403);
    }

    public function updatingSearch(): void
    {
        $this->resetPage();
    }

    public function getPatientsProperty()
    {
        return Patient::query()
            ->when($this->search, function ($query) {
                $term = trim($this->search);
                $query->where(function ($q) use ($term) {
                    $q->where('mrn', 'like', "%{$term}%")
                        ->orWhere('first_name', 'like', "%{$term}%")
                        ->orWhere('last_name', 'like', "%{$term}%")
                        ->orWhere('phone', 'like', "%{$term}%")
                        ->orWhere('national_id_hash', \App\Models\Patient::identifierHash(trim(str_replace('%', '', "%{$term}%"))));
                });
            })
            ->latest('registered_at')
            ->paginate(15);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-4">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Patients') }}</flux:heading>

        @can('patients.merge')
            <flux:button icon="arrows-pointing-in" :href="route('patients.merge')" wire:navigate class="ms-auto me-2">{{ __('Merge duplicates') }}</flux:button>
        @endcan
        @can('patients.create')
            <flux:button variant="primary" icon="plus" :href="route('patients.create')" wire:navigate>
                {{ __('Register patient') }}
            </flux:button>
        @endcan
    </div>

    <flux:input
        wire:model.live.debounce.400ms="search"
        icon="magnifying-glass"
        :placeholder="__('Search by MRN, name, phone, or national ID')"
        class="max-w-md"
    />

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3">{{ __('MRN') }}</th>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Sex') }}</th>
                    <th class="px-4 py-3">{{ __('Age') }}</th>
                    <th class="px-4 py-3">{{ __('Phone') }}</th>
                    <th class="px-4 py-3">{{ __('Registered') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @forelse ($this->patients as $patient)
                    <tr wire:key="patient-{{ $patient->id }}">
                        <td class="px-4 py-3 font-mono text-xs">{{ $patient->mrn }}</td>
                        <td class="px-4 py-3">{{ $patient->full_name }}</td>
                        <td class="px-4 py-3">{{ ucfirst($patient->sex) }}</td>
                        <td class="px-4 py-3">{{ $patient->age ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $patient->phone ?? '—' }}</td>
                        <td class="px-4 py-3">{{ $patient->registered_at?->format('d M Y') }}</td>
                        <td class="px-4 py-3 text-right">
                            <flux:button size="sm" variant="ghost" :href="route('patients.show', $patient)" wire:navigate>
                                {{ __('View') }}
                            </flux:button>
                        </td>
                    </tr>
                @empty
                    <tr>
                        <td colspan="7" class="px-4 py-8 text-center text-neutral-500">
                            {{ __('No patients found.') }}
                        </td>
                    </tr>
                @endforelse
            </tbody>
        </table>
    </div>

    {{ $this->patients->links() }}
</div>
