<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StaffInvitation;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Notification;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class StaffInvitationTest extends TestCase
{
    use RefreshDatabase;

    protected Tenant $tenant;

    protected User $admin;

    protected function setUp(): void
    {
        parent::setUp();
        Notification::fake();

        $this->tenant = app(TenantProvisioningService::class)->provision(['name' => 'Kisumu County Hospital'], ['name' => 'Main', 'code' => 'MAIN']);
        app(TenantCatalogSeeder::class)->seedFor($this->tenant);
        $this->admin = User::factory()->create(['email_verified_at' => now(), 'name' => 'Grace Admin']);
        $this->tenant->users()->attach($this->admin, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->admin->assignRole(RoleCatalog::HOSPITAL_ADMINISTRATOR);
        $this->actAsAdmin();
    }

    protected function actAsAdmin(): void
    {
        $c = app(TenantContext::class);
        $c->set($this->tenant->fresh());
        $c->setFacility($this->tenant->facilities()->first());
        app(PermissionRegistrar::class)->setPermissionsTeamId($this->tenant->id);
        $this->actingAs($this->admin);
    }

    protected function addStaff(string $email, string $name = 'Dr. Otieno'): void
    {
        Livewire::test('pages::admin.staff')
            ->set('name', $name)->set('email', $email)->set('role', RoleCatalog::DOCTOR)
            ->set('facility_id', $this->tenant->facilities()->first()->id)
            ->call('create')->assertHasNoErrors();
    }

    protected function inviteLink(User $user): string
    {
        $link = null;
        Notification::assertSentTo($user, StaffInvitation::class, function (StaffInvitation $n) use (&$link) {
            $link = $n->setPasswordUrl;

            return true;
        });

        return $link;
    }

    public function test_new_staff_member_is_emailed_and_sets_their_own_password(): void
    {
        $this->addStaff('otieno@example.com');
        $doctor = User::where('email', 'otieno@example.com')->firstOrFail();

        $this->assertNotNull($doctor->invited_at);
        $this->assertNull($doctor->password_set_at);
        Livewire::test('pages::admin.staff')->assertSee('Invite pending')->assertSee('Resend invite');

        $link = $this->inviteLink($doctor);
        $this->assertNotNull($link);
        Notification::assertSentTo($doctor, StaffInvitation::class, function (StaffInvitation $n) use ($doctor) {
            $mail = $n->toMail($doctor);

            return str_contains($mail->subject, 'Kisumu County Hospital')
                && $mail->actionText === 'Set your password'
                && str_contains(implode(' ', $mail->introLines), 'Grace Admin has added you')
                && str_contains(implode(' ', $mail->introLines), 'Doctor');
        });

        // The doctor opens the link logged out.
        auth()->logout();
        $this->get($link)->assertOk()->assertSee('Welcome, Dr. Otieno')->assertSee('otieno@example.com');

        $this->post($link, ['password' => 'Kisumu#2026pass', 'password_confirmation' => 'Kisumu#2026pass'])
            ->assertRedirect(route('landing'));

        $doctor->refresh();
        $this->assertAuthenticatedAs($doctor);
        $this->assertTrue(Hash::check('Kisumu#2026pass', $doctor->password));
        $this->assertNotNull($doctor->password_set_at);
        $this->assertNotNull($doctor->email_verified_at);

        // The link is single-use.
        auth()->logout();
        $this->get($link)->assertStatus(410)->assertSee('already been used');
        $this->post($link, ['password' => 'Other#2026pass', 'password_confirmation' => 'Other#2026pass'])->assertStatus(410);
        $this->assertTrue(Hash::check('Kisumu#2026pass', $doctor->fresh()->password));

        // And they can log in normally afterwards.
        $this->post(route('login.store'), ['email' => 'otieno@example.com', 'password' => 'Kisumu#2026pass']);
        $this->assertAuthenticatedAs($doctor);
    }

    public function test_tampered_or_expired_links_are_refused(): void
    {
        $this->addStaff('wanjiru@example.com', 'Nurse Wanjiru');
        $nurse = User::where('email', 'wanjiru@example.com')->firstOrFail();
        $link = $this->inviteLink($nurse);
        auth()->logout();

        $other = User::factory()->create();
        $this->get(str_replace("/invitation/{$nurse->id}", "/invitation/{$other->id}", $link))->assertStatus(410);
        $this->get(preg_replace('/h=[^&]+/', 'h=forged', $link))->assertStatus(410);

        $this->post($link, ['password' => 'short', 'password_confirmation' => 'short'])->assertSessionHasErrors('password');
        $this->post($link, ['password' => 'Kisumu#2026pass', 'password_confirmation' => 'different'])->assertSessionHasErrors('password');

        $this->travel(config('hms.invitation_hours') + 1)->hours();
        $this->get($link)->assertStatus(410)->assertSee('expired');
        $this->assertNull($nurse->fresh()->password_set_at);
    }

    public function test_existing_account_gets_access_email_without_password_link(): void
    {
        $existing = User::factory()->create(['email' => 'mutua@example.com', 'name' => 'Dr. Mutua']);

        $this->addStaff('mutua@example.com', 'Dr. Mutua');

        Notification::assertSentTo($existing, StaffInvitation::class, fn (StaffInvitation $n) => $n->setPasswordUrl === null
            && $n->toMail($existing)->actionText === 'Log in');
        $this->assertNull($existing->fresh()->invited_at);
        Livewire::test('pages::admin.staff')->assertDontSee('Invite pending');
    }

    public function test_resend_until_password_is_set_including_via_forgot_password(): void
    {
        $this->addStaff('achieng@example.com', 'Achieng');
        $user = User::where('email', 'achieng@example.com')->firstOrFail();

        Livewire::test('pages::admin.staff')->call('resendInvite', $user->id);
        Notification::assertSentToTimes($user, StaffInvitation::class, 2);

        // Using "Forgot your password?" instead also completes the invitation.
        event(new PasswordReset($user->fresh()));
        $this->assertNotNull($user->fresh()->password_set_at);

        Livewire::test('pages::admin.staff')->call('resendInvite', $user->id)->assertDontSee('Invite pending');
        Notification::assertSentToTimes($user, StaffInvitation::class, 2);
    }

    public function test_cannot_resend_for_someone_outside_this_hospital(): void
    {
        $stranger = User::factory()->create(['invited_at' => now()]);

        Livewire::test('pages::admin.staff')->call('resendInvite', $stranger->id)->assertNotFound();
        Notification::assertNothingSentTo($stranger);
    }
}
