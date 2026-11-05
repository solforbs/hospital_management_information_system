<?php

use App\Services\Audit\AuditLogger;
use App\Services\Tenancy\TenantContext;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Flux\Flux;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Str;
use Livewire\Attributes\Computed;
use Livewire\Attributes\Title;
use Livewire\Component;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

new #[Title('Roles & permissions')] class extends Component {
    public ?int $roleId = null;

    /** @var array<int, string> */
    public array $granted = [];

    public string $newRole = '';

    public string $copyFrom = '';

    /** Permissions an admin role must keep, so nobody can lock the hospital out. */
    protected const PROTECTED = ['roles.manage', 'staff.manage', 'settings.manage'];

    public function mount(): void
    {
        abort_unless(Auth::user()->can('roles.manage'), 403);
    }

    #[Computed]
    public function roles()
    {
        return Role::where('tenant_id', app(TenantContext::class)->id())->withCount('users')->orderBy('name')->get();
    }

    #[Computed]
    public function role(): ?Role
    {
        return $this->roleId ? $this->roles->firstWhere('id', $this->roleId) : null;
    }

    /** Permission catalog grouped by area (prefix before the first dot). */
    #[Computed]
    public function groups(): array
    {
        return collect(PermissionCatalog::all())->groupBy(fn ($p) => Str::before($p, '.'))->map(fn ($g) => $g->values()->all())->all();
    }

    public function select(int $id): void
    {
        $role = $this->roles->firstWhere('id', $id);
        abort_unless($role, 404);
        $this->roleId = $role->id;
        $this->granted = $role->permissions->pluck('name')->all();
    }

    public function toggleGroup(string $group, bool $on): void
    {
        $perms = $this->groups[$group] ?? [];
        $this->granted = $on ? array_values(array_unique(array_merge($this->granted, $perms))) : array_values(array_diff($this->granted, $perms));
    }

    protected function isAdminRole(Role $role): bool
    {
        return in_array($role->name, [RoleCatalog::HOSPITAL_OWNER, RoleCatalog::HOSPITAL_ADMINISTRATOR], true);
    }

    public function save(AuditLogger $audit): void
    {
        abort_unless(Auth::user()->can('roles.manage'), 403);
        $role = $this->role;
        abort_unless($role, 404);

        $granted = array_values(array_intersect($this->granted, PermissionCatalog::all()));

        // Platform-level permission is never grantable from a hospital.
        $granted = array_values(array_diff($granted, ['tenants.manage']));
        if ($this->isAdminRole($role) && $role->hasPermissionTo('tenants.manage')) {
            $granted[] = 'tenants.manage';
        }

        if ($this->isAdminRole($role)) {
            $missing = array_diff(self::PROTECTED, $granted);
            if ($missing) {
                $this->addError('granted', __('The :role role must keep: :list (prevents locking everyone out).', ['role' => $role->name, 'list' => implode(', ', $missing)]));

                return;
            }
        }

        $before = $role->permissions->pluck('name')->all();
        $role->syncPermissions($granted);
        app(PermissionRegistrar::class)->forgetCachedPermissions();

        $audit->log('roles.permissions_changed', null, [
            'role' => $role->name,
            'added' => array_values(array_diff($granted, $before)),
            'removed' => array_values(array_diff($before, $granted)),
        ]);

        unset($this->roles, $this->role);
        Flux::toast(variant: 'success', text: __('Permissions for :role saved.', ['role' => $role->name]));
    }

    public function createRole(AuditLogger $audit): void
    {
        abort_unless(Auth::user()->can('roles.manage'), 403);
        $this->validate([
            'newRole' => ['required', 'string', 'min:3', 'max:60', 'regex:/^[\pL0-9 \-\/&()]+$/u'],
            'copyFrom' => ['nullable', 'integer'],
        ], [], ['newRole' => 'role name']);

        $tenantId = app(TenantContext::class)->id();
        if (Role::where('tenant_id', $tenantId)->where('name', $this->newRole)->exists()) {
            $this->addError('newRole', __('A role with this name already exists.'));

            return;
        }

        $role = Role::create(['name' => trim($this->newRole), 'guard_name' => 'web', 'tenant_id' => $tenantId]);

        if ($this->copyFrom !== '' && ($source = $this->roles->firstWhere('id', (int) $this->copyFrom))) {
            $role->syncPermissions(array_diff($source->permissions->pluck('name')->all(), ['tenants.manage']));
        }

        $audit->log('roles.created', null, ['role' => $role->name]);
        $this->reset(['newRole', 'copyFrom']);
        unset($this->roles);
        $this->select($role->id);
    }

    public function deleteRole(AuditLogger $audit): void
    {
        abort_unless(Auth::user()->can('roles.manage'), 403);
        $role = $this->role;
        abort_unless($role, 404);

        if (in_array($role->name, RoleCatalog::tenantDefaults(), true)) {
            $this->addError('granted', __('Built-in roles cannot be deleted. Remove their permissions instead.'));

            return;
        }

        if ($role->users_count > 0) {
            $this->addError('granted', __('Reassign the :n staff member(s) with this role first.', ['n' => $role->users_count]));

            return;
        }

        $audit->log('roles.deleted', null, ['role' => $role->name]);
        $role->delete();
        $this->reset(['roleId', 'granted']);
        unset($this->roles);
    }
};
?>

<div class="flex h-full w-full flex-1 flex-col gap-6">
    <div>
        <flux:heading level="1">{{ __('Roles & permissions') }}</flux:heading>
        <flux:text class="mt-1">{{ __('Choose what each role can do in this hospital. Changes apply to everyone with the role immediately and are recorded in the audit log.') }}</flux:text>
    </div>

    <div class="grid gap-6 xl:grid-cols-4">
        <div class="space-y-3">
            @foreach ($this->roles as $r)
                <button type="button" wire:key="r-{{ $r->id }}" wire:click="select({{ $r->id }})" class="flex w-full items-center justify-between rounded-lg px-3 py-2 text-left text-sm {{ $roleId === $r->id ? 'bg-indigo-50 font-semibold text-indigo-600 dark:bg-zinc-800' : 'hover:bg-zinc-50 dark:hover:bg-zinc-800' }}">
                    <span>{{ $r->name }}</span><span class="text-xs text-zinc-500">{{ $r->users_count }}</span>
                </button>
            @endforeach

            <form wire:submit="createRole" class="space-y-2 rounded-xl border border-neutral-200 p-3 dark:border-neutral-700">
                <flux:input wire:model="newRole" size="sm" :label="__('New role')" :placeholder="__('e.g. Physiotherapist')" />
                <flux:select wire:model="copyFrom" size="sm" :label="__('Start from')">
                    <flux:select.option value="">{{ __('No permissions') }}</flux:select.option>
                    @foreach ($this->roles as $r)<flux:select.option value="{{ $r->id }}">{{ $r->name }}</flux:select.option>@endforeach
                </flux:select>
                <flux:button type="submit" size="sm" variant="primary">{{ __('Create role') }}</flux:button>
            </form>
        </div>

        <div class="xl:col-span-3">
            @if ($this->role)
                <form wire:submit="save" class="space-y-4">
                    <div class="flex flex-wrap items-center justify-between gap-2">
                        <flux:heading level="3">{{ $this->role->name }} · {{ count($granted) }} {{ __('permissions') }}</flux:heading>
                        <div class="flex gap-2">
                            @unless (in_array($this->role->name, \App\Support\RoleCatalog::tenantDefaults(), true))
                                <flux:button size="sm" variant="ghost" wire:click="deleteRole" wire:confirm="{{ __('Delete this role?') }}">{{ __('Delete role') }}</flux:button>
                            @endunless
                            <flux:button type="submit" variant="primary">{{ __('Save permissions') }}</flux:button>
                        </div>
                    </div>
                    @error('granted')<flux:callout variant="danger" icon="x-circle"><flux:callout.text>{{ $message }}</flux:callout.text></flux:callout>@enderror
                    <div class="grid gap-3 md:grid-cols-2 xl:grid-cols-3">
                        @foreach ($this->groups as $group => $perms)
                            @php
                                $on = count(array_intersect($perms, $granted));
                            @endphp
                            <div wire:key="g-{{ $group }}" class="rounded-xl border border-neutral-200 p-3 dark:border-neutral-700">
                                <div class="mb-2 flex items-center justify-between">
                                    <span class="text-xs font-semibold uppercase tracking-wide">{{ str_replace('_', ' ', $group) }}</span>
                                    <button type="button" wire:click="toggleGroup('{{ $group }}', {{ $on < count($perms) ? 'true' : 'false' }})" class="text-xs text-indigo-600 underline">{{ $on < count($perms) ? __('All') : __('None') }}</button>
                                </div>
                                @foreach ($perms as $p)
                                    @if ($p !== 'tenants.manage')
                                        <label wire:key="p-{{ $p }}" class="flex items-center gap-2 py-0.5 text-sm"><input type="checkbox" wire:model="granted" value="{{ $p }}" class="rounded" /> {{ \Illuminate\Support\Str::after($p, '.') }}</label>
                                    @endif
                                @endforeach
                            </div>
                        @endforeach
                    </div>
                </form>
            @else
                <flux:text>{{ __('Select a role to edit its permissions.') }}</flux:text>
            @endif
        </div>
    </div>
</div>
