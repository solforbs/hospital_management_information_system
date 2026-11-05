<!DOCTYPE html>
<html lang="{{ str_replace('_', '-', app()->getLocale()) }}">
    <head>
        @include('partials.head')
    </head>
    <body class="min-h-screen bg-hms-bg text-hms-text antialiased dark:bg-zinc-950 dark:text-zinc-100">
        <flux:sidebar sticky collapsible="mobile" class="border-e border-hms-line bg-white dark:border-zinc-800 dark:bg-zinc-900">
            <flux:sidebar.header>
                <x-app-logo :sidebar="true" href="{{ route('dashboard') }}" wire:navigate />
                <flux:sidebar.collapse class="lg:hidden" />
            </flux:sidebar.header>

            @php
                $tenancy = app(\App\Services\Tenancy\TenantContext::class);
                $unreadAlerts = $tenancy->hasTenant()
                    ? auth()->user()->unreadNotifications()->where('data->tenant_id', $tenancy->id())->count()
                    : 0;
            @endphp

            @if ($tenant = $tenancy->tenant())
                <div class="mx-1 rounded-lg bg-hms-bg px-3 py-2.5 dark:bg-zinc-800">
                    <div class="truncate text-xs font-semibold text-hms-text dark:text-zinc-100">{{ $tenant->name }}</div>
                    @if ($facility = $tenancy->facility())
                        <div class="mt-0.5 flex items-center gap-1.5 text-[0.7rem] text-zinc-500">
                            <span class="truncate">{{ $facility->name }}</span>
                            @if ($facility->level)<span class="rounded bg-brand-1/10 px-1.5 py-px font-semibold text-brand-1">L{{ $facility->level }}</span>@endif
                        </div>
                    @endif
                </div>
            @endif

            <flux:sidebar.nav>
                <flux:sidebar.group :heading="__('Overview')" class="grid" style="--item-color: #4E7CFF; --item-color-2: #7033FF;">
                    @if ($tenancy->hasTenant())
                        <flux:sidebar.item icon="home" :href="route('dashboard')" :current="request()->routeIs('dashboard')" wire:navigate>
                            {{ __('Dashboard') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="bell" :href="route('notifications')" :current="request()->routeIs('notifications')" :badge="$unreadAlerts ?: null" wire:navigate>
                            {{ __('Notifications') }}
                        </flux:sidebar.item>
                    @endif
                    @if (auth()->user()->isPlatformAdmin())
                        <flux:sidebar.item icon="globe-alt" :href="route('platform.tenants')" :current="request()->routeIs('platform.tenants')" wire:navigate>
                            {{ __('Platform: tenants') }}
                        </flux:sidebar.item>
                        <flux:sidebar.item icon="book-open" :href="route('platform.icd')" :current="request()->routeIs('platform.icd')" wire:navigate>
                            {{ __('Platform: ICD-11 codes') }}
                        </flux:sidebar.item>
                    @endif
                </flux:sidebar.group>

                @if ($tenancy->hasTenant())
                    @foreach (\App\Support\ModuleRegistry::groupedForUser(auth()->user()) as $group => $modules)
                        @php
                            [$gc, $gc2] = \App\Support\ModuleRegistry::colorsForGroup($group);
                        @endphp
                        <flux:sidebar.group :heading="__($group)" class="grid" style="--item-color: {{ $gc }}; --item-color-2: {{ $gc2 }};">
                            @foreach ($modules as $module)
                                <flux:sidebar.item :icon="$module['icon']" :href="route($module['route'])" :current="request()->routeIs($module['pattern'])" wire:navigate>
                                    {{ __($module['label']) }}
                                </flux:sidebar.item>
                            @endforeach
                        </flux:sidebar.group>
                    @endforeach
                @endif
            </flux:sidebar.nav>

            <flux:spacer />

            <x-desktop-user-menu class="hidden lg:block" :name="auth()->user()->name" />
        </flux:sidebar>

        <!-- Mobile User Menu -->
        <flux:header class="lg:hidden">
            <flux:sidebar.toggle class="lg:hidden" icon="bars-2" inset="left" />

            <flux:spacer />

            <flux:dropdown position="top" align="end">
                <flux:profile
                    :initials="auth()->user()->initials()"
                    icon-trailing="chevron-down"
                />

                <flux:menu>
                    <flux:menu.radio.group>
                        <div class="p-0 text-sm font-normal">
                            <div class="flex items-center gap-2 px-1 py-1.5 text-start text-sm">
                                <flux:avatar
                                    :name="auth()->user()->name"
                                    :initials="auth()->user()->initials()"
                                />

                                <div class="grid flex-1 text-start text-sm leading-tight">
                                    <flux:heading class="truncate">{{ auth()->user()->name }}</flux:heading>
                                    <flux:text class="truncate">{{ auth()->user()->email }}</flux:text>
                                </div>
                            </div>
                        </div>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <flux:menu.radio.group>
                        <flux:menu.item :href="route('profile.edit')" icon="cog" wire:navigate>
                            {{ __('Settings') }}
                        </flux:menu.item>
                    </flux:menu.radio.group>

                    <flux:menu.separator />

                    <form method="POST" action="{{ route('logout') }}" class="w-full">
                        @csrf
                        <flux:menu.item
                            as="button"
                            type="submit"
                            icon="arrow-right-start-on-rectangle"
                            class="w-full cursor-pointer"
                            data-test="logout-button"
                        >
                            {{ __('Log out') }}
                        </flux:menu.item>
                    </form>
                </flux:menu>
            </flux:dropdown>
        </flux:header>

        {{ $slot }}

        @persist('toast')
            <flux:toast.group>
                <flux:toast />
            </flux:toast.group>
        @endpersist

        @fluxScripts
    </body>
</html>
