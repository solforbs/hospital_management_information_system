<?php

use App\Models\Tenant;
use App\Models\User;
use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;
use Illuminate\Validation\Rule;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\PermissionRegistrar;

/**
 * Platform administration. Platform admins manage tenant lifecycle only —
 * they deliberately have no tenant membership, so they cannot open any
 * tenant's clinical records from here.
 */
new #[Title('Tenants')] class extends Component {
    public bool $showForm = false;

    public array $form = ['name' => '', 'type' => 'hospital', 'email' => '', 'phone' => '', 'county' => '', 'subscription_plan' => 'standard', 'facility_name' => 'Main Campus', 'facility_code' => 'MAIN', 'facility_level' => '4', 'admin_name' => '', 'admin_email' => '', 'admin_password' => ''];

    public ?int $suspendingId = null;

    public string $suspension_reason = '';

    #[Computed]
    public function tenants()
    {
        return Tenant::withCount(['facilities', 'users'])->orderBy('name')->get();
    }

    public function create(TenantProvisioningService $provisioning, AuditLogger $audit): void
    {
        $data = $this->validate([
            'form.name' => ['required', 'string', 'max:150', Rule::unique('tenants', 'name')],
            'form.type' => ['required', 'in:hospital,clinic,medical_centre,nursing_home'],
            'form.email' => ['nullable', 'email'],
            'form.phone' => ['nullable', 'string', 'max:30'],
            'form.county' => ['nullable', 'string', 'max:60'],
            'form.subscription_plan' => ['required', 'in:trial,basic,standard,enterprise'],
            'form.facility_name' => ['required', 'string', 'max:150'],
            'form.facility_code' => ['required', 'string', 'max:20'],
            'form.facility_level' => ['nullable', 'integer', 'between:1,6'],
            'form.admin_name' => ['required', 'string', 'max:150'],
            'form.admin_email' => ['required', 'email', 'max:255'],
            'form.admin_password' => ['required', 'string', 'min:8'],
        ])['form'];

        if (Tenant::where('slug', Str::slug($data['name']))->exists()) {
            $this->addError('form.name', __('A tenant with this name already exists.'));

            return;
        }

        DB::transaction(function () use ($data, $provisioning, $audit) {
            $tenant = $provisioning->provision(
                ['name' => $data['name'], 'type' => $data['type'], 'email' => $data['email'] ?: null, 'phone' => $data['phone'] ?: null, 'county' => $data['county'] ?: null, 'subscription_plan' => $data['subscription_plan'], 'status' => 'active'],
                ['name' => $data['facility_name'], 'code' => strtoupper($data['facility_code']), 'type' => 'main', 'level' => ($data['facility_level'] ?? '') !== '' ? (int) $data['facility_level'] : null],
            );

            $admin = User::firstOrCreate(['email' => $data['admin_email']], [
                'name' => $data['admin_name'],
                'password' => Hash::make($data['admin_password']),
                'email_verified_at' => now(),
            ]);

            $tenant->users()->syncWithoutDetaching([$admin->id => ['status' => 'active', 'is_default' => ! $admin->tenants()->exists(), 'joined_at' => now()]]);

            $registrar = app(PermissionRegistrar::class);
            $previous = $registrar->getPermissionsTeamId();
            $registrar->setPermissionsTeamId($tenant->id);
            try {
                $admin->assignRole(RoleCatalog::HOSPITAL_ADMINISTRATOR);
            } finally {
                $registrar->setPermissionsTeamId($previous);
            }

            app(\Database\Seeders\TenantCatalogSeeder::class)->seedFor($tenant);

            $audit->log('platform.tenant_created', $tenant, ['admin' => $admin->email], $tenant->id);
        });

        Flux::toast(variant: 'success', text: __('Tenant provisioned with default departments, roles and catalogs.'));
        $this->reset(['showForm', 'form']);
        unset($this->tenants);
    }

    public function suspend(AuditLogger $audit): void
    {
        $this->validate(['suspension_reason' => ['required', 'string', 'max:255']]);
        $tenant = Tenant::findOrFail($this->suspendingId);
        $tenant->update(['status' => 'suspended', 'suspended_at' => now(), 'suspension_reason' => $this->suspension_reason]);
        $audit->log('platform.tenant_suspended', $tenant, ['reason' => $this->suspension_reason], $tenant->id);
        $this->reset(['suspendingId', 'suspension_reason']);
        unset($this->tenants);
    }

    public function activate(int $id, AuditLogger $audit): void
    {
        $tenant = Tenant::findOrFail($id);
        $tenant->update(['status' => 'active', 'suspended_at' => null, 'suspension_reason' => null]);
        $audit->log('platform.tenant_activated', $tenant, [], $tenant->id);
        unset($this->tenants);
    }

    public ?int $supportTenantId = null;

    public string $support_reason = '';

    public string $support_ticket = '';

    public string $support_minutes = '60';

    /**
     * Break-glass: time-boxed, read-only access to one tenant for support.
     * Recorded in that tenant's own audit log and announced to its admins.
     */
    public function startSupport(AuditLogger $audit): void
    {
        abort_unless(Auth::user()->isPlatformAdmin(), 403);
        $this->validate([
            'support_reason' => ['required', 'string', 'min:10', 'max:255'],
            'support_ticket' => ['nullable', 'string', 'max:60'],
            'support_minutes' => ['required', 'integer', 'in:30,60,120,240'],
        ], [], ['support_reason' => 'reason']);

        $tenant = Tenant::findOrFail($this->supportTenantId);
        $grant = \App\Models\SupportAccessGrant::create([
            'tenant_id' => $tenant->id,
            'user_id' => Auth::id(),
            'reason' => $this->support_reason,
            'ticket_reference' => $this->support_ticket ?: null,
            'expires_at' => now()->addMinutes((int) $this->support_minutes),
        ]);

        $audit->log('platform.support_access_granted', $tenant, ['by' => Auth::user()->email, 'reason' => $grant->reason, 'ticket' => $grant->ticket_reference, 'expires_at' => $grant->expires_at->toIso8601String()], $tenant->id);

        // Tell the hospital's administrators.
        $context = app(\App\Services\Tenancy\TenantContext::class);
        $context->set($tenant);
        app(\App\Services\Notifications\DepartmentNotifier::class)->notifyPermission('settings.manage', __('Support access started'), __(':who has read-only support access until :t. Reason: :r', ['who' => Auth::user()->name, 't' => $grant->expires_at->format('H:i'), 'r' => $grant->reason]), null, 'warning');
        $context->clear();

        session(['support_tenant_id' => $tenant->id]);
        $this->redirect(route('dashboard'));
    }

    public function endSupport(AuditLogger $audit): void
    {
        $tenantId = session('support_tenant_id');
        if ($tenantId) {
            \App\Models\SupportAccessGrant::where('user_id', Auth::id())->where('tenant_id', $tenantId)->whereNull('revoked_at')->update(['revoked_at' => now()]);
            $audit->log('platform.support_access_ended', null, [], (int) $tenantId);
        }
        session()->forget('support_tenant_id');
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div class="flex items-center justify-between">
        <div>
            <flux:heading level="1">{{ __('Platform — tenants') }}</flux:heading>
            <flux:text>{{ __('Platform administrators manage organisations and subscriptions. Clinical data is only visible to tenant members.') }}</flux:text>
        </div>
        <flux:button variant="primary" icon="plus" wire:click="$set('showForm', true)">{{ __('New tenant') }}</flux:button>
    </div>

    @if ($showForm)
        <form wire:submit="create" class="grid max-w-4xl gap-3 rounded-xl border border-neutral-200 p-5 dark:border-neutral-700">
            <flux:heading level="3">{{ __('Organisation') }}</flux:heading>
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:input wire:model="form.name" :label="__('Name')" />
                <flux:select wire:model="form.type" :label="__('Type')">
                    <flux:select.option value="hospital">{{ __('Hospital') }}</flux:select.option>
                    <flux:select.option value="clinic">{{ __('Clinic') }}</flux:select.option>
                    <flux:select.option value="medical_centre">{{ __('Medical centre') }}</flux:select.option>
                    <flux:select.option value="nursing_home">{{ __('Nursing home') }}</flux:select.option>
                </flux:select>
                <flux:select wire:model="form.subscription_plan" :label="__('Plan')">
                    @foreach (['trial', 'basic', 'standard', 'enterprise'] as $p)
                        <flux:select.option value="{{ $p }}">{{ ucfirst($p) }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:input wire:model="form.email" :label="__('Email')" />
                <flux:input wire:model="form.phone" :label="__('Phone')" />
                <flux:input wire:model="form.county" :label="__('County')" />
            </div>
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:input wire:model="form.facility_name" :label="__('Main facility name')" />
                <flux:input wire:model="form.facility_code" :label="__('Facility code')" />
                <flux:select wire:model="form.facility_level" :label="__('KEPH level')">
                    <flux:select.option value="">{{ __('Not set') }}</flux:select.option>
                    @foreach (\App\Support\FacilityLevel::options() as $k => $l)
                        <flux:select.option value="{{ $k }}">{{ $l }}</flux:select.option>
                    @endforeach
                </flux:select>
            </div>
            <flux:heading level="3">{{ __('First hospital administrator') }}</flux:heading>
            <div class="grid gap-3 sm:grid-cols-3">
                <flux:input wire:model="form.admin_name" :label="__('Name')" />
                <flux:input wire:model="form.admin_email" type="email" :label="__('Email')" />
                <flux:input wire:model="form.admin_password" type="password" :label="__('Initial password')" />
            </div>
            <div class="flex gap-2">
                <flux:button type="submit" variant="primary">{{ __('Provision tenant') }}</flux:button>
                <flux:button variant="ghost" wire:click="$set('showForm', false)">{{ __('Cancel') }}</flux:button>
            </div>
        </form>
    @endif

    @if (session('support_tenant_id'))
        <flux:callout variant="warning" icon="lifebuoy">
            <flux:callout.text>{{ __('You have an active support session.') }} <flux:button size="xs" wire:click="endSupport">{{ __('End support session') }}</flux:button></flux:callout.text>
        </flux:callout>
    @endif

    @if ($supportTenantId)
        <form wire:submit="startSupport" class="grid max-w-2xl gap-3 rounded-xl border border-amber-300 p-4 dark:border-amber-800">
            <flux:heading level="3">{{ __('Break-glass support access') }} — {{ $this->tenants->firstWhere('id', $supportTenantId)?->name }}</flux:heading>
            <flux:text class="text-xs">{{ __('Read-only, time-limited, recorded in the hospital\'s audit log and announced to its administrators.') }}</flux:text>
            <flux:input wire:model="support_reason" :label="__('Reason')" />
            <div class="grid gap-3 sm:grid-cols-2">
                <flux:input wire:model="support_ticket" :label="__('Support ticket reference')" />
                <flux:select wire:model="support_minutes" :label="__('Duration')">
                    @foreach (['30', '60', '120', '240'] as $m)<flux:select.option value="{{ $m }}">{{ $m }} {{ __('minutes') }}</flux:select.option>@endforeach
                </flux:select>
            </div>
            <div class="flex gap-2"><flux:button type="submit" size="sm" variant="primary">{{ __('Start support session') }}</flux:button><flux:button size="sm" variant="ghost" wire:click="$set('supportTenantId', null)">{{ __('Cancel') }}</flux:button></div>
        </form>
    @endif

    @if ($suspendingId)
        <form wire:submit="suspend" class="flex max-w-xl items-end gap-2 rounded-xl border border-red-300 p-4 dark:border-red-800">
            <flux:input wire:model="suspension_reason" :label="__('Suspension reason')" class="flex-1" />
            <flux:button type="submit" variant="danger" size="sm">{{ __('Suspend') }}</flux:button>
            <flux:button size="sm" variant="ghost" wire:click="$set('suspendingId', null)">{{ __('Cancel') }}</flux:button>
        </form>
    @endif

    <div class="overflow-hidden rounded-xl border border-neutral-200 dark:border-neutral-700">
        <table class="w-full text-left text-sm">
            <thead class="bg-neutral-50 text-xs uppercase text-neutral-500 dark:bg-neutral-900">
                <tr><th class="px-3 py-2">{{ __('Tenant') }}</th><th class="px-3 py-2">{{ __('Type') }}</th><th class="px-3 py-2">{{ __('Plan') }}</th><th class="px-3 py-2">{{ __('Facilities') }}</th><th class="px-3 py-2">{{ __('Users') }}</th><th class="px-3 py-2">{{ __('Status') }}</th><th></th></tr>
            </thead>
            <tbody class="divide-y divide-neutral-200 dark:divide-neutral-700">
                @foreach ($this->tenants as $t)
                    <tr wire:key="tn-{{ $t->id }}">
                        <td class="px-3 py-2">{{ $t->name }}<div class="font-mono text-xs text-neutral-500">{{ $t->slug }}</div></td>
                        <td class="px-3 py-2">{{ ucfirst(str_replace('_', ' ', $t->type)) }}</td>
                        <td class="px-3 py-2">{{ ucfirst($t->subscription_plan) }}</td>
                        <td class="px-3 py-2">{{ $t->facilities_count }}</td>
                        <td class="px-3 py-2">{{ $t->users_count }}</td>
                        <td class="px-3 py-2">
                            <flux:badge size="sm" :color="$t->status === 'active' ? 'lime' : 'red'">{{ $t->status }}</flux:badge>
                            @if ($t->suspension_reason) <div class="text-xs text-neutral-500">{{ $t->suspension_reason }}</div> @endif
                        </td>
                        <td class="px-3 py-2 text-right">
                            @if ($t->status === 'active')
                                <flux:button size="xs" variant="ghost" icon="lifebuoy" wire:click="$set('supportTenantId', {{ $t->id }})">{{ __('Support access') }}</flux:button>
                                <flux:button size="xs" variant="ghost" wire:click="$set('suspendingId', {{ $t->id }})">{{ __('Suspend') }}</flux:button>
                            @else
                                <flux:button size="xs" wire:click="activate({{ $t->id }})">{{ __('Activate') }}</flux:button>
                            @endif
                        </td>
                    </tr>
                @endforeach
            </tbody>
        </table>
    </div>
</div>
