<?php

namespace Tests\Feature;

use App\Models\Tenant;
use App\Models\User;
use App\Notifications\StaffInvitation;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\MailBrand;
use App\Support\RoleCatalog;
use Illuminate\Auth\Notifications\ResetPassword;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Route;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class BrandingTest extends TestCase
{
    use RefreshDatabase;

    protected function hospital(array $attrs = []): Tenant
    {
        return app(TenantProvisioningService::class)->provision($attrs + ['name' => 'Kisumu County Hospital'], ['name' => 'Main', 'code' => 'MAIN']);
    }

    protected function member(Tenant $tenant): User
    {
        $user = User::factory()->create(['email_verified_at' => now()]);
        $tenant->users()->attach($user, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);
        $user->assignRole(RoleCatalog::HOSPITAL_ADMINISTRATOR);

        return $user;
    }

    protected function html(object $notification, User $to): string
    {
        return (string) $notification->toMail($to)->render();
    }

    public function test_hospital_logo_is_used_in_email_when_uploaded(): void
    {
        $tenant = $this->hospital(['motto' => 'Care with dignity', 'phone' => '0712000000']);
        $tenant->forceFill(['logo_path' => 'branding/1/logo.png', 'branding' => ['primary_color' => '#0A5C36', 'secondary_color' => '#14804A']])->save();
        $to = User::factory()->make(['name' => 'Dr. Otieno']);

        $html = $this->html(new StaffInvitation($tenant->name, 'Doctor', null, null, 'https://example.test/x', 72, MailBrand::forTenant($tenant)->toArray()), $to);

        $this->assertStringContainsString($tenant->logoUrl(), $html);
        $this->assertStringNotContainsString('images/hmis-logo.png', $html);
        $this->assertStringContainsString('Kisumu County Hospital', $html);
        $this->assertStringContainsString('Care with dignity', $html);
        $this->assertStringContainsString('#0A5C36', $html, 'hospital colour on header and button');
        $this->assertStringContainsString('Tel: 0712000000', $html);
        $this->assertStringContainsString('Sent via HMIS', $html);
    }

    public function test_hmis_logo_is_the_fallback_until_the_hospital_uploads_one(): void
    {
        $tenant = $this->hospital();
        $to = User::factory()->make();

        $html = $this->html(new StaffInvitation($tenant->name, 'Nurse', null, null, 'https://example.test/x', 72, MailBrand::forTenant($tenant)->toArray()), $to);

        $this->assertStringContainsString('images/hmis-logo.png', $html);
        $this->assertStringContainsString('Kisumu County Hospital', $html, 'still named after the hospital');
        $this->assertFileExists(public_path('images/hmis-logo.png'));
    }

    public function test_password_reset_uses_the_persons_hospital_or_hmis(): void
    {
        $tenant = $this->hospital();
        $tenant->forceFill(['logo_path' => 'branding/1/logo.png'])->save();
        $member = $this->member($tenant);

        $html = $this->html(new ResetPassword('tok'), $member);
        $this->assertStringContainsString($tenant->logoUrl(), $html);
        $this->assertStringContainsString('Reset your password', (new ResetPassword('tok'))->toMail($member)->subject);

        $loner = User::factory()->create();
        $html = $this->html(new ResetPassword('tok'), $loner);
        $this->assertStringContainsString('images/hmis-logo.png', $html);
        $this->assertStringContainsString('Hospital Management Information System', $html);
    }

    public function test_custom_error_pages_with_institution_branding(): void
    {
        $this->get('/no-such-page')->assertNotFound()->assertSee('Page not found')->assertSee('Go to login')->assertDontSee('Laravel');

        $tenant = $this->hospital();
        $member = $this->member($tenant);
        $this->actingAs($member);
        app(TenantContext::class)->set($tenant->fresh());

        $this->get('/no-such-page')->assertNotFound()->assertSee('Kisumu County Hospital')->assertSee('Go to my dashboard');
        app(TenantContext::class)->clear();
        $this->get(route('platform.tenants'))->assertForbidden()->assertSee('Access not allowed');
    }

    public function test_403_shows_the_specific_reason(): void
    {
        Route::get('/_test/forbidden', fn () => abort(403, 'Access to X is restricted to approved networks.'))->middleware('web');

        $this->get('/_test/forbidden')->assertForbidden()->assertSee('restricted to approved networks');
    }

    public function test_500_page_never_leaks_exception_details_in_production(): void
    {
        config(['app.debug' => false]);
        Route::get('/_test/boom', fn () => throw new \RuntimeException('SQLSTATE secret db password'))->middleware('web');

        $this->get('/_test/boom')->assertStatus(500)->assertSee('Something went wrong')->assertDontSee('secret db password');
    }

    public function test_maintenance_and_expired_session_pages_render(): void
    {
        $this->assertStringContainsString('be right back', view('errors.503')->render());
        $this->assertStringContainsString('http-equiv="refresh"', view('errors.503')->render());
        $this->assertStringContainsString('session expired', view('errors.419')->render());
        $this->assertStringContainsString('Too many attempts', view('errors.429')->render());
    }
}
