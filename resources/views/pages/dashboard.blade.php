<?php

use App\Models\Admission;
use App\Models\Bed;
use App\Models\Encounter;
use App\Models\Facility;
use App\Models\LabOrderItem;
use App\Models\Patient;
use App\Models\Payment;
use App\Models\Prescription;
use App\Models\StaffAssignment;
use App\Services\Tenancy\TenantContext;
use App\Support\EncounterStage;
use App\Support\ModuleRegistry;
use Illuminate\Support\Facades\Auth;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;

new #[Title('Dashboard')] class extends Component {
    #[Computed]
    public function tenant()
    {
        return app(TenantContext::class)->tenant();
    }

    #[Computed]
    public function facility()
    {
        return app(TenantContext::class)->facility();
    }

    #[Computed]
    public function memberships()
    {
        return Auth::user()->tenants()->wherePivot('status', 'active')->get();
    }

    #[Computed]
    public function facilities()
    {
        if (! $this->tenant) {
            return collect();
        }

        $all = Facility::where('active', true)->orderBy('name')->get();
        $assigned = StaffAssignment::where('user_id', Auth::id())->pluck('facility_id');

        return $assigned->isEmpty() ? $all : $all->whereIn('id', $assigned)->values();
    }

    #[Computed]
    public function roles()
    {
        return Auth::user()->getRoleNames();
    }

    #[Computed]
    public function permissions()
    {
        return Auth::user()->getAllPermissions()->pluck('name')->sort()->values();
    }

    #[Computed]
    public function modules()
    {
        return ModuleRegistry::forUser(Auth::user());
    }

    #[Computed]
    public function stats()
    {
        if (! $this->tenant) {
            return null;
        }

        $user = Auth::user();
        $beds = Bed::count();
        $stats = [];

        if ($user->canAny(['reception.checkin', 'encounters.view', 'reports.view'])) {
            $stats[] = [__('Visits today'), Encounter::whereDate('started_at', today())->count(), 'reception.index'];
            $stats[] = [__('Open visits'), Encounter::where('status', 'open')->count(), 'reception.index'];
            $stats[] = [__('Registered today'), Patient::whereDate('registered_at', today())->count(), 'patients.index'];
        }
        if ($user->can('triage.record')) {
            $stats[] = [__('Waiting for triage'), Encounter::where('status', 'open')->where('stage', EncounterStage::WAITING_TRIAGE)->count(), 'triage.index'];
        }
        if ($user->can('encounters.create')) {
            $stats[] = [__('Waiting for doctor'), Encounter::where('status', 'open')->where('stage', EncounterStage::WAITING_DOCTOR)->count(), 'consultation.index'];
        }
        if ($user->can('lab.orders.view')) {
            $stats[] = [__('Lab tests pending'), LabOrderItem::whereIn('status', ['ordered', 'collected', 'resulted', 'verified'])->count(), $user->can('lab.orders.process') ? 'lab.worklist' : null];
        }
        if ($user->can('pharmacy.prescriptions.view')) {
            $stats[] = [__('Prescriptions to dispense'), Prescription::whereIn('status', ['pending', 'partially_dispensed'])->count(), 'pharmacy.queue'];
        }
        if ($user->canAny(['wards.view', 'nursing.tasks.manage', 'reports.view'])) {
            $occupied = Bed::where('status', 'occupied')->count();
            $stats[] = [__('Inpatients'), Admission::where('status', 'admitted')->count(), $user->canAny(['wards.view', 'nursing.tasks.manage']) ? 'wards.board' : null];
            $stats[] = [__('Bed occupancy'), ($beds ? round($occupied / $beds * 100) : 0).'% ('.$occupied.'/'.$beds.')', null];
            $stats[] = [__('Admission requests'), Admission::where('status', 'requested')->count(), $user->canAny(['wards.beds.allocate', 'wards.admissions.create']) ? 'wards.admissions' : null];
        }
        if ($user->canAny(['billing.payments.receive', 'reports.view'])) {
            $stats[] = [__('Collected today'), config('hms.currency').' '.number_format((float) Payment::whereDate('received_at', today())->sum('amount'), 2), $user->canAny(['billing.invoices.view', 'billing.payments.receive']) ? 'billing.index' : null];
        }

        return $stats;
    }

    public function switchTenant(int $tenantId): void
    {
        $allowed = Auth::user()->tenants()->wherePivot('status', 'active')->pluck('tenants.id');

        abort_unless($allowed->contains($tenantId), 403);

        session(['current_tenant_id' => $tenantId]);
        session()->forget('current_facility_id');

        $this->redirect(route('dashboard'), navigate: true);
    }

    public function switchFacility(int $facilityId): void
    {
        // Re-validated server-side; IdentifyTenant re-checks on every request too.
        abort_unless($this->facilities->contains('id', $facilityId), 403);

        session(['current_facility_id' => $facilityId]);

        $this->redirect(route('dashboard'), navigate: true);
    }
};
?>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        @if ($this->tenant)
            <div class="flex flex-wrap items-start justify-between gap-4 rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <div>
                    <flux:heading level="2">{{ $this->tenant->name }}</flux:heading>
                    <flux:text class="mt-1">
                        {{ __('Type') }}: {{ ucfirst($this->tenant->type) }}
                        &middot; {{ __('Status') }}: {{ ucfirst($this->tenant->status) }}
                        &middot; {{ __('Plan') }}: {{ ucfirst($this->tenant->subscription_plan) }}
                        @if ($this->facility) &middot; {{ __('Facility') }}: {{ $this->facility->name }} @endif
                    </flux:text>
                    <flux:text class="mt-1">{{ __('Welcome') }}, {{ auth()->user()->name }} — {{ $this->roles->join(', ') ?: __('no role assigned') }}</flux:text>
                </div>
                <div class="flex flex-col items-end gap-2">
                    @if ($this->memberships->count() > 1)
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->memberships as $membership)
                                <flux:button size="sm" :variant="$this->tenant->id === $membership->id ? 'primary' : 'ghost'" wire:click="switchTenant({{ $membership->id }})">{{ $membership->name }}</flux:button>
                            @endforeach
                        </div>
                    @endif
                    @if ($this->facilities->count() > 1)
                        <div class="flex flex-wrap gap-2">
                            @foreach ($this->facilities as $f)
                                <flux:button size="xs" :variant="$this->facility?->id === $f->id ? 'filled' : 'ghost'" wire:click="switchFacility({{ $f->id }})">{{ $f->name }}</flux:button>
                            @endforeach
                        </div>
                    @endif
                </div>
            </div>

            @if ($this->stats)
                <div class="grid grid-cols-2 gap-3 md:grid-cols-3 xl:grid-cols-6">
                    @foreach ($this->stats as [$label, $value, $route])
                        @if ($route)
                            <a href="{{ route($route) }}" wire:navigate class="rounded-xl border border-neutral-200 p-4 hover:border-indigo-400 dark:border-neutral-700">
                        @else
                            <div class="rounded-xl border border-neutral-200 p-4 dark:border-neutral-700">
                        @endif
                            <div class="text-xs text-neutral-500">{{ $label }}</div>
                            <div class="mt-1 text-2xl font-semibold">{{ $value }}</div>
                        {!! $route ? '</a>' : '</div>' !!}
                    @endforeach
                </div>
            @endif

            <div>
                <flux:heading level="3" class="mb-3">{{ __('Your modules') }}</flux:heading>
                <div class="grid grid-cols-2 gap-3 md:grid-cols-4 xl:grid-cols-6">
                    @forelse ($this->modules as $module)
                        <a href="{{ route($module['route']) }}" wire:navigate class="flex items-center gap-3 rounded-xl border border-neutral-200 p-4 hover:border-indigo-400 dark:border-neutral-700">
                            <flux:icon :name="$module['icon']" class="size-6 text-indigo-500" />
                            <div>
                                <div class="text-sm font-medium">{{ __($module['label']) }}</div>
                                <div class="text-xs text-neutral-500">{{ __($module['group']) }}</div>
                            </div>
                        </a>
                    @empty
                        <flux:text>{{ __('No modules are enabled for your role yet. Ask your hospital administrator to assign permissions.') }}</flux:text>
                    @endforelse
                </div>
            </div>
        @else
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-6 dark:border-amber-700 dark:bg-amber-950">
                <flux:heading level="2">{{ __('No hospital assigned') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Your account is not a member of any hospital tenant yet. Contact your administrator.') }}</flux:text>
                @if (auth()->user()->isPlatformAdmin())
                    <flux:button class="mt-3" :href="route('platform.tenants')" wire:navigate>{{ __('Go to platform administration') }}</flux:button>
                @endif
            </div>
        @endif

        <details class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
            <summary class="cursor-pointer text-sm font-medium">{{ __('Your roles and permissions') }}</summary>
            <div class="mt-4 grid gap-4 md:grid-cols-2">
                <div>
                    <flux:heading level="3">{{ __('Your roles') }}</flux:heading>
                    <div class="mt-3 flex flex-wrap gap-2">
                        @forelse ($this->roles as $role)
                            <flux:badge>{{ $role }}</flux:badge>
                        @empty
                            <flux:text>{{ __('No roles assigned in this hospital.') }}</flux:text>
                        @endforelse
                    </div>
                </div>
                <div>
                    <flux:heading level="3">{{ __('Your permissions') }}</flux:heading>
                    <div class="mt-3 flex max-h-48 flex-wrap gap-2 overflow-y-auto">
                        @forelse ($this->permissions as $permission)
                            <flux:badge size="sm" color="zinc">{{ $permission }}</flux:badge>
                        @empty
                            <flux:text>{{ __('No permissions granted.') }}</flux:text>
                        @endforelse
                    </div>
                </div>
            </div>
        </details>
    </div>
