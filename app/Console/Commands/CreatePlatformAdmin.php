<?php

namespace App\Console\Commands;

use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Validator;
use Illuminate\Validation\Rules\Password;

/**
 * Creates (or promotes) a platform super administrator on a fresh server,
 * so production never relies on the demo seeder's well-known password.
 */
class CreatePlatformAdmin extends Command
{
    protected $signature = 'hms:create-platform-admin {--email=} {--name=}';

    protected $description = 'Create a platform super administrator (prompts for a password)';

    public function handle(): int
    {
        $email = $this->option('email') ?: $this->ask('Email');
        $name = $this->option('name') ?: $this->ask('Full name', 'Platform Administrator');
        $password = $this->secret('Password (min 12 chars, upper/lower case, number, symbol)');
        $confirm = $this->secret('Confirm password');

        $validator = Validator::make(
            ['email' => $email, 'name' => $name, 'password' => $password, 'password_confirmation' => $confirm],
            [
                'email' => ['required', 'email', 'max:255'],
                'name' => ['required', 'string', 'max:255'],
                'password' => ['required', 'confirmed', Password::min(12)->mixedCase()->letters()->numbers()->symbols()],
            ],
        );

        if ($validator->fails()) {
            foreach ($validator->errors()->all() as $error) {
                $this->error($error);
            }

            return self::FAILURE;
        }

        $user = User::firstOrNew(['email' => $email]);
        $user->forceFill([
            'name' => $name,
            'password' => Hash::make($password),
            'email_verified_at' => now(),
            'is_platform_admin' => true,
        ])->save();

        $this->info(($user->wasRecentlyCreated ? 'Created' : 'Updated')." platform administrator {$email}.");

        return self::SUCCESS;
    }
}
