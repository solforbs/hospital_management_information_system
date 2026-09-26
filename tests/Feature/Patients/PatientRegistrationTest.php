<?php

namespace Tests\Feature\Patients;

use App\Models\Patient;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Livewire\Livewire;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class PatientRegistrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_a_receptionist_can_register_a_patient_and_receives_a_generated_mrn(): void
    {
        $tenant = app(TenantProvisioningService::class)->provision(
            ['name' => 'Test Hospital'],
            ['name' => 'Main', 'code' => 'MAIN'],
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $receptionist = User::factory()->create();
        $tenant->users()->attach($receptionist, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
        $receptionist->assignRole(RoleCatalog::RECEPTIONIST);

        app(TenantContext::class)->set($tenant);

        Livewire::actingAs($receptionist)
            ->test('pages::patients.create')
            ->set('first_name', 'Jane')
            ->set('last_name', 'Doe')
            ->set('sex', 'female')
            ->set('category', 'general')
            ->call('register')
            ->assertHasNoErrors();

        $this->assertDatabaseHas('patients', [
            'tenant_id' => $tenant->id,
            'first_name' => 'Jane',
            'last_name' => 'Doe',
        ]);

        $patient = Patient::where('tenant_id', $tenant->id)->firstOrFail();
        $this->assertNotEmpty($patient->mrn);
    }

    public function test_a_user_without_the_permission_cannot_register_a_patient(): void
    {
        $tenant = app(TenantProvisioningService::class)->provision(
            ['name' => 'Test Hospital'],
            ['name' => 'Main', 'code' => 'MAIN'],
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $cashier = User::factory()->create();
        $tenant->users()->attach($cashier, ['status' => 'active', 'is_default' => true, 'joined_at' => now()]);
        $cashier->assignRole(RoleCatalog::CASHIER);

        Livewire::actingAs($cashier)
            ->test('pages::patients.create')
            ->assertStatus(403);
    }

    public function test_patients_do_not_leak_across_tenants(): void
    {
        $provisioning = app(TenantProvisioningService::class);
        $tenantA = $provisioning->provision(['name' => 'Hospital A'], ['name' => 'Main', 'code' => 'MAIN']);
        $tenantB = $provisioning->provision(['name' => 'Hospital B'], ['name' => 'Main', 'code' => 'MAIN']);

        $registrar = app(PermissionRegistrar::class);

        $registrar->setPermissionsTeamId($tenantA->id);
        $patientA = Patient::factory()->for($tenantA)->create();

        app(TenantContext::class)->set($tenantB);

        $this->assertFalse(Patient::all()->contains($patientA));
    }
}
