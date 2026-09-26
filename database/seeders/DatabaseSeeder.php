<?php

namespace Database\Seeders;

use App\Models\User;
use Illuminate\Database\Console\Seeds\WithoutModelEvents;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;

class DatabaseSeeder extends Seeder
{
    use WithoutModelEvents;

    /**
     * Seed the application's database.
     */
    public function run(): void
    {
        // Platform Super Administrator is not tenant-scoped, so it is
        // represented by the is_platform_admin flag rather than a Spatie
        // role (Spatie's "teams" pivot tables require a non-null team id).
        User::firstOrCreate(
            ['email' => 'platform@hms.test'],
            [
                'name' => 'Platform Super Administrator',
                'password' => Hash::make('password'),
                'email_verified_at' => now(),
                'is_platform_admin' => true,
            ]
        );

        $this->call(DemoTenantSeeder::class);
    }
}
