<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\StaffAssignment;
use App\Models\User;
use App\Services\Tenancy\TenantProvisioningService;
use App\Support\RoleCatalog;
use Illuminate\Database\Seeder;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\PermissionRegistrar;

/**
 * Seeds a demo tenant with a full set of role-representative users so the
 * application can be exercised end-to-end in local development.
 */
class DemoTenantSeeder extends Seeder
{
    public function run(): void
    {
        $provisioning = app(TenantProvisioningService::class);

        $tenant = $provisioning->provision(
            [
                'name' => 'Nairobi General Hospital',
                'type' => 'hospital',
                'email' => 'info@nairobigeneral.test',
                'phone' => '+254700000000',
                'county' => 'Nairobi',
            ],
            [
                'name' => 'Main Campus',
                'code' => 'MAIN',
                'type' => 'main',
            ]
        );

        $facility = $tenant->facilities()->first();
        $opd = Department::where('tenant_id', $tenant->id)->where('code', 'OPD')->first();

        $accounts = [
            ['name' => 'Hospital Administrator', 'email' => 'admin@demo.hms.test', 'role' => RoleCatalog::HOSPITAL_ADMINISTRATOR],
            ['name' => 'Dr. Wanjiku Doctor', 'email' => 'doctor@demo.hms.test', 'role' => RoleCatalog::DOCTOR],
            ['name' => 'Amina Receptionist', 'email' => 'reception@demo.hms.test', 'role' => RoleCatalog::RECEPTIONIST],
            ['name' => 'Otieno Nurse', 'email' => 'nurse@demo.hms.test', 'role' => RoleCatalog::TRIAGE_NURSE],
            ['name' => 'Kip Lab Tech', 'email' => 'lab@demo.hms.test', 'role' => RoleCatalog::LABORATORY_TECHNICIAN],
            ['name' => 'Faith Pharmacist', 'email' => 'pharmacy@demo.hms.test', 'role' => RoleCatalog::PHARMACIST],
            ['name' => 'Brian Cashier', 'email' => 'cashier@demo.hms.test', 'role' => RoleCatalog::CASHIER],
        ];

        $registrar = app(PermissionRegistrar::class);
        $previousTeamId = $registrar->getPermissionsTeamId();
        $registrar->setPermissionsTeamId($tenant->id);

        try {
            foreach ($accounts as $account) {
                $user = User::firstOrCreate(
                    ['email' => $account['email']],
                    [
                        'name' => $account['name'],
                        'password' => Hash::make('password'),
                        'email_verified_at' => now(),
                    ]
                );

                $tenant->users()->syncWithoutDetaching([
                    $user->id => ['status' => 'active', 'is_default' => true, 'joined_at' => now()],
                ]);

                StaffAssignment::firstOrCreate([
                    'tenant_id' => $tenant->id,
                    'user_id' => $user->id,
                    'facility_id' => $facility->id,
                    'department_id' => $opd?->id,
                ], [
                    'title' => $account['role'],
                    'is_primary' => true,
                ]);

                $user->syncRoles([$account['role']]);
            }
        } finally {
            $registrar->setPermissionsTeamId($previousTeamId);
        }
    }
}
