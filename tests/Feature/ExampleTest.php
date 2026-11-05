<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ExampleTest extends TestCase
{
    use RefreshDatabase;

    public function test_guests_land_on_the_login_page(): void
    {
        $this->get(route('home'))->assertRedirect(route('login'));
        $this->get(route('login'))->assertOk()->assertSee('Log in');
    }

    public function test_signed_in_users_land_in_their_workspace(): void
    {
        $this->actingAs(User::factory()->create(['email_verified_at' => now(), 'is_platform_admin' => true]));

        $this->get(route('home'))->assertRedirect(route('landing'));
    }
}
