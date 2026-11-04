<?php

namespace App\Console\Commands;

use App\Models\Tenant;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Database\Seeders\TenantCatalogSeeder;
use Illuminate\Console\Command;
use Spatie\Permission\Models\Role;
use Spatie\Permission\PermissionRegistrar;

/**
 * Upgrade path for existing tenants after new modules ship: creates any
 * new catalog permissions and ADDS missing default grants to each tenant's
 * default roles. It never revokes a permission, so tenant customisations
 * made by administrators are preserved.
 */
class SyncTenantRoles extends Command
{
    protected $signature = 'hms:sync-roles {--catalogs : Also seed missing starter catalogs (tariffs, tests, medicines, wards)}';

    protected $description = 'Add newly introduced permissions/default roles (and optionally starter catalogs) to every tenant';

    public function handle(TenantProvisioningService $provisioning, PermissionRegistrar $registrar): int
    {
        $provisioning->ensurePermissionsExist();
        $defaults = RoleCatalog::defaultPermissions();
        $previous = $registrar->getPermissionsTeamId();

        try {
            foreach (Tenant::all() as $tenant) {
                $registrar->setPermissionsTeamId($tenant->id);

                foreach (RoleCatalog::tenantDefaults() as $roleName) {
                    $role = Role::findOrCreate($roleName, 'web');
                    $missing = array_diff($defaults[$roleName] ?? [], $role->permissions->pluck('name')->all());

                    if ($missing) {
                        $role->givePermissionTo($missing);
                        $this->line("{$tenant->name}: {$roleName} +".count($missing));
                    }
                }

                if ($this->option('catalogs')) {
                    app(TenantCatalogSeeder::class)->seedFor($tenant);
                    $this->line("{$tenant->name}: catalogs seeded");
                }
            }
        } finally {
            $registrar->setPermissionsTeamId($previous);
            $registrar->forgetCachedPermissions();
        }

        $this->info('Tenant roles synchronised.');

        return self::SUCCESS;
    }
}
