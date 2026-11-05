<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Tests\TestCase;

class CreatePlatformAdminTest extends TestCase
{
    use RefreshDatabase;

    public function test_creates_platform_admin_with_strong_password(): void
    {
        $this->artisan('hms:create-platform-admin', ['--email' => 'ops@example.com', '--name' => 'Ops'])
            ->expectsQuestion('Password (min 12 chars, upper/lower case, number, symbol)', 'Str0ng!Passw0rd')
            ->expectsQuestion('Confirm password', 'Str0ng!Passw0rd')
            ->assertSuccessful();

        $user = User::where('email', 'ops@example.com')->firstOrFail();
        $this->assertTrue($user->is_platform_admin);
        $this->assertTrue(Hash::check('Str0ng!Passw0rd', $user->password));
    }

    public function test_rejects_weak_or_mismatched_password(): void
    {
        $this->artisan('hms:create-platform-admin', ['--email' => 'ops@example.com', '--name' => 'Ops'])
            ->expectsQuestion('Password (min 12 chars, upper/lower case, number, symbol)', 'password')
            ->expectsQuestion('Confirm password', 'password')
            ->assertFailed();

        $this->artisan('hms:create-platform-admin', ['--email' => 'ops@example.com', '--name' => 'Ops'])
            ->expectsQuestion('Password (min 12 chars, upper/lower case, number, symbol)', 'Str0ng!Passw0rd')
            ->expectsQuestion('Confirm password', 'Str0ng!Passw0rd2')
            ->assertFailed();

        $this->assertFalse(User::where('email', 'ops@example.com')->exists());
    }
}
