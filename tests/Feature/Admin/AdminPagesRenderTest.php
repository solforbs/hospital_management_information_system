<?php

namespace Tests\Feature\Admin;

use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class AdminPagesRenderTest extends TestCase
{
    use RefreshDatabase;

    protected function actingAsHospitalAdministrator()
    {
        $tenant = app(TenantProvisioningService::class)->provision(
            ['name' => 'Test Hospital'],
            ['name' => 'Main', 'code' => 'MAIN'],
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $admin = User::factory()->create(['email_verified_at' => now()]);
        $tenant->users()->attach($admin, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
        $admin->assignRole(RoleCatalog::HOSPITAL_ADMINISTRATOR);

        $this->actingAs($admin);

        return [$tenant, $admin];
    }

    public function test_the_facilities_admin_page_renders_and_can_create_a_facility(): void
    {
        [$tenant] = $this->actingAsHospitalAdministrator();

        $this->get(route('admin.facilities'))->assertOk()->assertSee('Main');

        Livewire::actingAs(auth()->user())
            ->test('pages::admin.facilities')
            ->set('name', 'Westlands Clinic')
            ->set('code', 'WEST')
            ->set('type', 'clinic')
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('facilities', [
            'tenant_id' => $tenant->id,
            'code' => 'WEST',
        ]);
    }

    public function test_the_departments_admin_page_renders_and_can_create_a_department(): void
    {
        [$tenant] = $this->actingAsHospitalAdministrator();

        $facility = $tenant->facilities()->first();

        $this->get(route('admin.departments'))->assertOk()->assertSee('Laboratory');

        Livewire::actingAs(auth()->user())
            ->test('pages::admin.departments')
            ->set('name', 'Dental')
            ->set('code', 'DNT')
            ->set('facility_id', $facility->id)
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('departments', [
            'facility_id' => $facility->id,
            'code' => 'DNT',
        ]);
    }

    public function test_the_staff_admin_page_renders_and_can_add_a_staff_member(): void
    {
        [$tenant] = $this->actingAsHospitalAdministrator();

        $facility = $tenant->facilities()->first();

        $this->get(route('admin.staff'))->assertOk();

        Livewire::actingAs(auth()->user())
            ->test('pages::admin.staff')
            ->set('name', 'New Doctor')
            ->set('email', 'newdoctor@demo.hms.test')
            ->set('role', RoleCatalog::DOCTOR)
            ->set('facility_id', $facility->id)
            ->call('create')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('users', ['email' => 'newdoctor@demo.hms.test']);

        $newUser = User::where('email', 'newdoctor@demo.hms.test')->firstOrFail();
        $this->assertTrue($tenant->users()->where('users.id', $newUser->id)->exists());
    }
}
