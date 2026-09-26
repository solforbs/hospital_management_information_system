<?php

namespace App\Services\Tenancy;

use App\Models\Department;
use App\Models\Facility;
use App\Models\Tenant;
use App\Support\PermissionCatalog;
use App\Support\RoleCatalog;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Creates a new tenant with its default facility, departments, and the
 * standard set of tenant-scoped roles. This is the only place tenants
 * should be created so provisioning stays consistent.
 */
class TenantProvisioningService
{
    public function __construct(protected PermissionRegistrar $registrar) {}

    public function provision(array $tenantData, array $facilityData): Tenant
    {
        // Permissions are global application data, not tenant data: create
        // them outside the tenant transaction so a role sync inside the
        // transaction always sees committed rows rather than depending on
        // same-transaction read-your-writes behaviour.
        $this->ensurePermissionsExist();

        return DB::transaction(function () use ($tenantData, $facilityData) {
            $tenant = Tenant::create(array_merge([
                'slug' => Str::slug($tenantData['name']),
            ], $tenantData));

            $facility = $tenant->facilities()->create(array_merge([
                'is_main' => true,
            ], $facilityData));

            $this->seedDefaultDepartments($tenant, $facility);
            $this->seedDefaultRoles($tenant);

            return $tenant->fresh(['facilities']);
        });
    }

    public function seedDefaultDepartments(Tenant $tenant, Facility $facility): void
    {
        $defaults = [
            ['name' => 'Reception', 'code' => 'RCP', 'type' => 'administrative', 'module' => 'reception'],
            ['name' => 'Outpatient', 'code' => 'OPD', 'type' => 'clinical', 'module' => 'consultation'],
            ['name' => 'Laboratory', 'code' => 'LAB', 'type' => 'clinical', 'module' => 'laboratory'],
            ['name' => 'Radiology', 'code' => 'RAD', 'type' => 'clinical', 'module' => 'radiology'],
            ['name' => 'Pharmacy', 'code' => 'PHM', 'type' => 'clinical', 'module' => 'pharmacy'],
            ['name' => 'Wards', 'code' => 'WRD', 'type' => 'clinical', 'module' => 'wards'],
            ['name' => 'Theatre', 'code' => 'THR', 'type' => 'clinical', 'module' => 'theatre'],
            ['name' => 'Billing', 'code' => 'BIL', 'type' => 'administrative', 'module' => 'billing'],
        ];

        foreach ($defaults as $department) {
            Department::firstOrCreate(
                ['tenant_id' => $tenant->id, 'facility_id' => $facility->id, 'code' => $department['code']],
                $department
            );
        }
    }

    public function ensurePermissionsExist(): void
    {
        foreach (PermissionCatalog::all() as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // PermissionRegistrar caches the permission list in-process on first
        // access and does not invalidate that cache when permissions are
        // created via a plain Eloquent create(), so force a reload here to
        // guarantee the role sync below sees every permission just created.
        $this->registrar->forgetCachedPermissions();
    }

    public function seedDefaultRoles(Tenant $tenant): void
    {
        $previousTeamId = $this->registrar->getPermissionsTeamId();
        $this->registrar->setPermissionsTeamId($tenant->id);

        try {
            $permissionMap = RoleCatalog::defaultPermissions();

            foreach (RoleCatalog::tenantDefaults() as $roleName) {
                $role = Role::findOrCreate($roleName, 'web');
                $role->syncPermissions($permissionMap[$roleName] ?? []);
            }
        } finally {
            $this->registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
