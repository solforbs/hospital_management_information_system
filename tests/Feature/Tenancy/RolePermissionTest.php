<?php

namespace Tests\Feature\Tenancy;

use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;
use Tests\TestCase;

class RolePermissionTest extends TestCase
{
    use RefreshDatabase;

    public function test_provisioning_a_tenant_creates_the_standard_role_set(): void
    {
        $tenant = app(TenantProvisioningService::class)->provision(
            ['name' => 'Test Hospital'],
            ['name' => 'Main', 'code' => 'MAIN'],
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $this->assertTrue(Role::where('name', RoleCatalog::DOCTOR)->exists());
    }

    public function test_a_role_grants_only_its_assigned_permissions(): void
    {
        $tenant = app(TenantProvisioningService::class)->provision(
            ['name' => 'Test Hospital'],
            ['name' => 'Main', 'code' => 'MAIN'],
        );

        app(PermissionRegistrar::class)->setPermissionsTeamId($tenant->id);

        $doctor = User::factory()->create();
        $doctor->assignRole(RoleCatalog::DOCTOR);

        $cashier = User::factory()->create();
        $cashier->assignRole(RoleCatalog::CASHIER);

        $this->assertTrue($doctor->can('clinical_notes.sign'));
        $this->assertFalse($doctor->can('billing.payments.receive'));

        $this->assertTrue($cashier->can('billing.payments.receive'));
        $this->assertFalse($cashier->can('clinical_notes.sign'));
    }

    public function test_roles_and_permissions_do_not_leak_across_tenants(): void
    {
        $provisioning = app(TenantProvisioningService::class);
        $tenantA = $provisioning->provision(['name' => 'Hospital A'], ['name' => 'Main', 'code' => 'MAIN']);
        $tenantB = $provisioning->provision(['name' => 'Hospital B'], ['name' => 'Main', 'code' => 'MAIN']);

        $registrar = app(PermissionRegistrar::class);

        $registrar->setPermissionsTeamId($tenantA->id);
        $userInA = User::factory()->create();
        $userInA->assignRole(RoleCatalog::HOSPITAL_ADMINISTRATOR);

        $registrar->setPermissionsTeamId($tenantB->id);
        $this->assertFalse($userInA->fresh()->hasRole(RoleCatalog::HOSPITAL_ADMINISTRATOR));
    }
}
