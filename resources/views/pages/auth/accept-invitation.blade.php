<x-layouts::auth :title="__('Set your password')">
    <div class="flex flex-col gap-6">
        @if ($problem)
            <x-auth-header :title="__('Invitation link not valid')" :description="$problem" />

            <flux:button variant="primary" class="w-full" :href="route('login')">{{ __('Go to login') }}</flux:button>
            <flux:link class="text-center text-sm" :href="route('password.request')">{{ __('Forgot your password?') }}</flux:link>
        @else
            <x-auth-header :title="__('Welcome, :name', ['name' => $user->name])" :description="__('Choose a password to finish setting up your account.')" />

            <form method="POST" action="{{ request()->fullUrl() }}" class="flex flex-col gap-6">
                @csrf

                <flux:input :label="__('Email')" type="email" value="{{ $user->email }}" readonly disabled />

                <flux:input
                    name="password"
                    :label="__('Password')"
                    type="password"
                    required
                    autofocus
                    autocomplete="new-password"
                    :placeholder="__('Password')"
                    passwordrules="{{ \Illuminate\Validation\Rules\Password::defaults()->toPasswordRulesString() }}"
                    viewable
                />

                <flux:input
                    name="password_confirmation"
                    :label="__('Confirm password')"
                    type="password"
                    required
                    autocomplete="new-password"
                    :placeholder="__('Confirm password')"
                    viewable
                />

                @if (app()->isProduction())
                    <flux:text class="text-xs">{{ __('At least 12 characters, with upper and lower case letters, a number and a symbol.') }}</flux:text>
                @endif

                <flux:button type="submit" variant="primary" class="w-full" data-test="set-password-button">
                    {{ __('Set password and log in') }}
                </flux:button>
            </form>
        @endif
    </div>
</x-layouts::auth>
