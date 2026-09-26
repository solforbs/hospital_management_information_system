<?php

use App\Services\Tenancy\TenantContext;
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
    public function memberships()
    {
        return Auth::user()->tenants()->wherePivot('status', 'active')->get();
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

    public function switchTenant(int $tenantId): void
    {
        $allowed = Auth::user()->tenants()->wherePivot('status', 'active')->pluck('tenants.id');

        abort_unless($allowed->contains($tenantId), 403);

        session(['current_tenant_id' => $tenantId]);

        $this->redirect(route('dashboard'), navigate: true);
    }
};
?>

    <div class="flex h-full w-full flex-1 flex-col gap-6 rounded-xl">
        @if ($this->tenant)
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading level="2">{{ $this->tenant->name }}</flux:heading>
                <flux:text class="mt-1">
                    {{ __('Type') }}: {{ ucfirst($this->tenant->type) }}
                    &middot; {{ __('Status') }}: {{ ucfirst($this->tenant->status) }}
                    &middot; {{ __('Plan') }}: {{ ucfirst($this->tenant->subscription_plan) }}
                </flux:text>
            </div>
        @else
            <div class="rounded-xl border border-amber-300 bg-amber-50 p-6 dark:border-amber-700 dark:bg-amber-950">
                <flux:heading level="2">{{ __('No hospital assigned') }}</flux:heading>
                <flux:text class="mt-1">{{ __('Your account is not a member of any hospital tenant yet. Contact your administrator.') }}</flux:text>
            </div>
        @endif

        @if ($this->memberships->count() > 1)
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading level="3">{{ __('Switch hospital') }}</flux:heading>
                <div class="mt-3 flex flex-wrap gap-2">
                    @foreach ($this->memberships as $membership)
                        <flux:button
                            size="sm"
                            :variant="$this->tenant && $this->tenant->id === $membership->id ? 'primary' : 'ghost'"
                            wire:click="switchTenant({{ $membership->id }})"
                        >
                            {{ $membership->name }}
                        </flux:button>
                    @endforeach
                </div>
            </div>
        @endif

        <div class="grid gap-4 md:grid-cols-2">
            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
                <flux:heading level="3">{{ __('Your roles') }}</flux:heading>
                <div class="mt-3 flex flex-wrap gap-2">
                    @forelse ($this->roles as $role)
                        <flux:badge>{{ $role }}</flux:badge>
                    @empty
                        <flux:text>{{ __('No roles assigned in this hospital.') }}</flux:text>
                    @endforelse
                </div>
            </div>

            <div class="rounded-xl border border-neutral-200 p-6 dark:border-neutral-700">
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
    </div>
