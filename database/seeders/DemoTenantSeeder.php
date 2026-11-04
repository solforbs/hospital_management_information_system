<?php

namespace Database\Seeders;

use App\Models\Department;
use App\Models\Patient;
use App\Models\StaffAssignment;
use App\Models\Tenant;
use App\Models\User;
use App\Services\Patients\PatientRegistrationService;
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

        // Idempotent: re-running the seeder reuses the existing demo tenant.
        $tenant = Tenant::where('slug', 'nairobi-general-hospital')->first() ?? $provisioning->provision(
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

        // The demo hospital is a Level 5 county referral hospital (all modules).
        if (! $facility->level) {
            $facility->update(['level' => 5]);
        }
        $opd = Department::where('tenant_id', $tenant->id)->where('code', 'OPD')->first();

        $accounts = [
            ['name' => 'Hospital Administrator', 'email' => 'admin@demo.hms.test', 'role' => RoleCatalog::HOSPITAL_ADMINISTRATOR],
            ['name' => 'Dr. Wanjiku Doctor', 'email' => 'doctor@demo.hms.test', 'role' => RoleCatalog::DOCTOR],
            ['name' => 'Amina Receptionist', 'email' => 'reception@demo.hms.test', 'role' => RoleCatalog::RECEPTIONIST],
            ['name' => 'Otieno Nurse', 'email' => 'nurse@demo.hms.test', 'role' => RoleCatalog::TRIAGE_NURSE],
            ['name' => 'Kip Lab Tech', 'email' => 'lab@demo.hms.test', 'role' => RoleCatalog::LABORATORY_TECHNICIAN],
            ['name' => 'Faith Pharmacist', 'email' => 'pharmacy@demo.hms.test', 'role' => RoleCatalog::PHARMACIST],
            ['name' => 'Brian Cashier', 'email' => 'cashier@demo.hms.test', 'role' => RoleCatalog::CASHIER],
            ['name' => 'Grace Lab Manager', 'email' => 'labmanager@demo.hms.test', 'role' => RoleCatalog::LABORATORY_MANAGER],
            ['name' => 'Dr. Mwangi Radiologist', 'email' => 'radiology@demo.hms.test', 'role' => RoleCatalog::RADIOLOGIST],
            ['name' => 'Jane Ward Nurse', 'email' => 'wardnurse@demo.hms.test', 'role' => RoleCatalog::NURSE],
            ['name' => 'Mary Nursing Officer', 'email' => 'noic@demo.hms.test', 'role' => RoleCatalog::NURSING_OFFICER_IN_CHARGE],
            ['name' => 'Dr. Ochieng Surgeon', 'email' => 'surgeon@demo.hms.test', 'role' => RoleCatalog::SURGEON],
            ['name' => 'Peter Theatre Nurse', 'email' => 'theatre@demo.hms.test', 'role' => RoleCatalog::THEATRE_NURSE],
            ['name' => 'Ann Admissions', 'email' => 'admissions@demo.hms.test', 'role' => RoleCatalog::ADMISSION_OFFICER],
            ['name' => 'Tom Accountant', 'email' => 'accounts@demo.hms.test', 'role' => RoleCatalog::ACCOUNTANT],
            ['name' => 'Lucy Insurance Officer', 'email' => 'insurance@demo.hms.test', 'role' => RoleCatalog::INSURANCE_OFFICER],
            ['name' => 'Rose Midwife', 'email' => 'midwife@demo.hms.test', 'role' => RoleCatalog::MIDWIFE],
            ['name' => 'Dr. Kamau Medical Director', 'email' => 'director@demo.hms.test', 'role' => RoleCatalog::MEDICAL_DIRECTOR],
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

        app(TenantCatalogSeeder::class)->seedFor($tenant);

        $registration = app(PatientRegistrationService::class);
        $registrar_id = User::where('email', 'reception@demo.hms.test')->value('id');

        foreach ([
            ['Mercy', 'Achieng', 'Otieno', '1990-04-12', 'female', '24567890', '+254712000001', 'Nairobi'],
            ['John', 'Kiprotich', 'Kirui', '1978-11-02', 'male', '13579246', '+254712000002', 'Kiambu'],
            ['Fatuma', null, 'Hassan', '2001-07-23', 'female', '35791357', '+254712000003', 'Mombasa'],
            ['Peter', 'Njoroge', 'Kamau', '1965-01-30', 'male', '11223344', '+254712000004', 'Nakuru'],
            ['Baby', null, 'Wambui', now()->subMonths(8)->toDateString(), 'female', null, '+254712000005', 'Nairobi'],
        ] as [$first, $middle, $last, $dob, $sex, $nid, $phone, $county]) {
            if (Patient::withoutGlobalScope('tenant')->where('tenant_id', $tenant->id)->where('phone', $phone)->exists()) {
                continue;
            }

            $registration->register($tenant, [
                'first_name' => $first, 'middle_name' => $middle, 'last_name' => $last,
                'date_of_birth' => $dob, 'sex' => $sex, 'national_id' => $nid, 'phone' => $phone,
                'county' => $county, 'category' => $nid ? 'general' : 'minor', 'registered_by' => $registrar_id,
                'next_of_kin_name' => 'Next of kin', 'next_of_kin_phone' => '+254700111222', 'next_of_kin_relationship' => 'Spouse',
            ], $facility);
        }
    }
}
