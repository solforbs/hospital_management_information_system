<?php

use App\Models\Department;
use App\Models\Facility;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Support\RoleCatalog;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Staff')] class extends Component {
    public bool $showForm = false;

    public string $name = '';

    public string $email = '';

    public string $role = '';

    public ?int $facility_id = null;

    public ?int $department_id = null;

    public function mount(): void
    {
        abort_unless(Auth::user()->can('staff.manage'), 403);
    }

    public function getTenantProperty()
    {
        return app(TenantContext::class)->tenant();
    }

    public function getFacilitiesProperty()
    {
        return Facility::orderBy('name')->get();
    }

    public function getDepartmentsProperty()
    {
        if (! $this->facility_id) {
            return collect();
        }

        return Department::where('facility_id', $this->facility_id)->orderBy('name')->get();
    }

    public function getRolesProperty(): array
    {
        return RoleCatalog::tenantDefaults();
    }

    public function getStaffProperty()
    {
        return $this->tenant->users()
            ->withPivot(['status'])
            ->with(['staffAssignments' => fn ($q) => $q->where('tenant_id', $this->tenant->id)->with('facility', 'department')])
            ->orderBy('name')
            ->get();
    }

    protected function rules(): array
    {
        return [
            'name' => ['required', 'string', 'max:150'],
            'email' => ['required', 'email', 'max:255'],
            'role' => ['required', 'in:'.implode(',', RoleCatalog::tenantDefaults())],
            'facility_id' => ['required', 'exists:facilities,id'],
            'department_id' => ['nullable', 'exists:departments,id'],
        ];
    }

    public function create(): void
    {
        abort_unless(Auth::user()->can('staff.manage'), 403);

        $validated = $this->validate();

        $tenant = $this->tenant;
        abort_unless($tenant, 403);

        $user = User::firstOrCreate(
            ['email' => $validated['email']],
            [
                'name' => $validated['name'],
                'password' => Hash::make(Str::random(24)),
            ]
        );

        $tenant->users()->syncWithoutDetaching([
            $user->id => ['status' => 'active', 'is_default' => false, 'joined_at' => now()],
        ]);

        StaffAssignment::firstOrCreate([
            'tenant_id' => $tenant->id,
            'user_id' => $user->id,
            'facility_id' => $validated['facility_id'],
            'department_id' => $validated['department_id'] ?? null,
        ], [
            'title' => $validated['role'],
            'is_primary' => true,
        ]);

        $user->assignRole($validated['role']);

        $this->reset(['name', 'email', 'role', 'facility_id', 'department_id', 'showForm']);
    }

    public function removeFromTenant(int $userId): void
    {
        abort_unless(Auth::user()->can('staff.manage'), 403);

        $tenant = $this->tenant;
        abort_if($userId === Auth::id(), 403, "You cannot remove your own access.");

        $tenant->users()->updateExistingPivot($userId, ['status' => 'suspended']);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <flux:heading level="1">{{ __('Staff') }}</flux:heading>
        <flux:button variant="primary" icon="plus" wire:click="$set('showForm', true)">
            {{ __('Add staff member') }}
        </flux:button>
    </div>

    @if ($showForm)
        <form wire:submit="create" class="grid max-w-2xl gap-4 rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <div class="grid gap-4 sm:grid-cols-2">
                <flux:input wire:model="name" :label="__('Full name')" required autofocus />
                <flux:input wire:model="email" type="email" :label="__('Email')" required />
            </div>
            <div class="grid gap-4 sm:grid-cols-3">
                <flux:select wire:model="role" :label="__('Role')" required>
                    <flux:select.option value="">{{ __('Select role') }}</flux:select.option>
                    @foreach ($this->roles as $roleName)
                        <flux:select.option value="{{ $roleName }}">{{ $roleName }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model.live="facility_id" :label="__('Facility')" required>
                    <flux:select.option value="">{{ __('Select facility') }}</flux:select.option>
                    @foreach ($this->facilities as $facility)
                        <flux:select.option value="{{ $facility->id }}">{{ $facility->name }}</flux:select.option>
                    @endforeach
                </flux:select>
                <flux:select wire:model="department_id" :label="__('Department')">
                    <flux:select.option value="">{{ __('None') }}</flux:select.option>
                    @foreach ($this->departments as $department)
                        <flux:select.option value="{{ $department->id }}">{{ $department->name }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:text class="text-sm text-neutral-500">
                {{ __('A new user account is created if the email is not already registered. Existing users are simply added to this hospital.') }}
            </flux:text>
            <div class="flex gap-3">
                <flux:button variant="primary" type="submit">{{ __('Add staff member') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900 dark:text-neutral-400">
                <tr>
                    <th class="px-4 py-3">{{ __('Name') }}</th>
                    <th class="px-4 py-3">{{ __('Email') }}</th>
                    <th class="px-4 py-3">{{ __('Role') }}</th>
                    <th class="px-4 py-3">{{ __('Facility') }}</th>
                    <th class="px-4 py-3">{{ __('Status') }}</th>
                    <th class="px-4 py-3"></th>
                </tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @foreach ($this->staff as $member)
                    <tr wire:key="staff-{{ $member->id }}">
                        <td class="px-4 py-3">{{ $member->name }}</td>
                        <td class="px-4 py-3">{{ $member->email }}</td>
                        <td class="px-4 py-3">
                            @foreach ($member->staffAssignments->pluck('title')->unique() as $title)
                                <flux:badge size="sm">{{ $title }}</flux:badge>
                            @endforeach
                        </td>
                        <td class="px-4 py-3">{{ $member->staffAssignments->first()?->facility?->name ?? '—' }}</td>
                        <td class="px-4 py-3">
                            <flux:badge :color="$member->pivot->status === 'active' ? 'lime' : 'zinc'">
                                {{ ucfirst($member->pivot->status) }}
                            </flux:badge>
                        </td>
                        <td class="px-4 py-3 text-right">
                            @if ($member->id !== auth()->id() && $member->pivot->status === 'active')
                                <flux:button size="sm" variant="ghost" wire:click="removeFromTenant({{ $member->id }})" wire:confirm="{{ __('Suspend this staff member\'s access?') }}">
                                    {{ __('Suspend') }}
                                </flux:button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
