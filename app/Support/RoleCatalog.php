<?php

namespace App\Support;

/**
 * The default role names provisioned for every new tenant. Hospital
 * administrators may add custom roles on top of this baseline.
 */
class RoleCatalog
{
    public const PLATFORM_SUPER_ADMINISTRATOR = 'Platform Super Administrator';

    public const HOSPITAL_OWNER = 'Hospital Owner';

    public const HOSPITAL_ADMINISTRATOR = 'Hospital Administrator';

    public const FACILITY_ADMINISTRATOR = 'Facility Administrator';

    public const MEDICAL_DIRECTOR = 'Medical Director';

    public const DOCTOR = 'Doctor';

    public const SPECIALIST = 'Specialist';

    public const CLINICAL_OFFICER = 'Clinical Officer';

    public const NURSE = 'Nurse';

    public const NURSING_OFFICER_IN_CHARGE = 'Nursing Officer in Charge';

    public const RECEPTIONIST = 'Receptionist';

    public const TRIAGE_NURSE = 'Triage Nurse';

    public const LABORATORY_TECHNICIAN = 'Laboratory Technician';

    public const LABORATORY_MANAGER = 'Laboratory Manager';

    public const RADIOLOGIST = 'Radiologist';

    public const RADIOLOGY_TECHNICIAN = 'Radiology Technician';

    public const PHARMACIST = 'Pharmacist';

    public const PHARMACY_TECHNICIAN = 'Pharmacy Technician';

    public const THEATRE_NURSE = 'Theatre Nurse';

    public const SURGEON = 'Surgeon';

    public const ANESTHETIST = 'Anesthetist';

    public const WARD_CLERK = 'Ward Clerk';

    public const ADMISSION_OFFICER = 'Admission Officer';

    public const CASHIER = 'Cashier';

    public const ACCOUNTANT = 'Accountant';

    public const INSURANCE_OFFICER = 'Insurance Officer';

    public const PROCUREMENT_OFFICER = 'Procurement Officer';

    public const STOREKEEPER = 'Storekeeper';

    public const HR_OFFICER = 'HR Officer';

    public const AUDITOR = 'Auditor';

    public const RECORDS_OFFICER = 'Records Officer';

    /**
     * Tenant-scoped roles provisioned automatically when a tenant is created.
     * Platform Super Administrator is intentionally excluded: it is a
     * platform-level (non-tenant) role granted directly on the user.
     */
    public static function tenantDefaults(): array
    {
        return [
            self::HOSPITAL_OWNER,
            self::HOSPITAL_ADMINISTRATOR,
            self::FACILITY_ADMINISTRATOR,
            self::MEDICAL_DIRECTOR,
            self::DOCTOR,
            self::SPECIALIST,
            self::CLINICAL_OFFICER,
            self::NURSE,
            self::NURSING_OFFICER_IN_CHARGE,
            self::RECEPTIONIST,
            self::TRIAGE_NURSE,
            self::LABORATORY_TECHNICIAN,
            self::LABORATORY_MANAGER,
            self::RADIOLOGIST,
            self::RADIOLOGY_TECHNICIAN,
            self::PHARMACIST,
            self::PHARMACY_TECHNICIAN,
            self::THEATRE_NURSE,
            self::SURGEON,
            self::ANESTHETIST,
            self::WARD_CLERK,
            self::ADMISSION_OFFICER,
            self::CASHIER,
            self::ACCOUNTANT,
            self::INSURANCE_OFFICER,
            self::PROCUREMENT_OFFICER,
            self::STOREKEEPER,
            self::HR_OFFICER,
            self::AUDITOR,
            self::RECORDS_OFFICER,
        ];
    }

    /**
     * Baseline permission grants per default role. Roles omitted here start
     * with no permissions and are configured by a tenant administrator.
     * Administrative roles receive the full catalog; clinical/operational
     * roles receive a starter set matching their department.
     */
    public static function defaultPermissions(): array
    {
        $all = PermissionCatalog::all();

        return [
            self::HOSPITAL_OWNER => $all,
            self::HOSPITAL_ADMINISTRATOR => $all,
            self::FACILITY_ADMINISTRATOR => array_values(array_diff($all, ['tenants.manage'])),
            self::MEDICAL_DIRECTOR => [
                'patients.view', 'encounters.view', 'encounters.update', 'encounters.sign',
                'clinical_notes.view', 'clinical_notes.create', 'clinical_notes.sign', 'clinical_notes.amend',
                'orders.create', 'orders.view', 'reports.view', 'reports.export', 'audit.view',
            ],
            self::DOCTOR => [
                'patients.view', 'encounters.view', 'encounters.create', 'encounters.update', 'encounters.sign',
                'clinical_notes.view', 'clinical_notes.create', 'clinical_notes.sign', 'clinical_notes.amend',
                'orders.create', 'orders.view',
                'lab.orders.view', 'radiology.orders.view', 'pharmacy.prescriptions.view',
            ],
            self::SPECIALIST => [
                'patients.view', 'encounters.view', 'encounters.create', 'encounters.update', 'encounters.sign',
                'clinical_notes.view', 'clinical_notes.create', 'clinical_notes.sign',
                'orders.create', 'orders.view', 'theatre.records.sign',
            ],
            self::CLINICAL_OFFICER => [
                'patients.view', 'encounters.view', 'encounters.create', 'encounters.update',
                'clinical_notes.view', 'clinical_notes.create', 'orders.create', 'orders.view',
            ],
            self::NURSE => [
                'patients.view', 'triage.record', 'nursing.tasks.manage', 'wards.beds.allocate',
                'orders.view',
            ],
            self::NURSING_OFFICER_IN_CHARGE => [
                'patients.view', 'nursing.tasks.manage', 'wards.admissions.create', 'wards.beds.allocate',
                'wards.transfers.approve', 'reports.view',
            ],
            self::RECEPTIONIST => [
                'patients.view', 'patients.create', 'patients.update', 'appointments.view',
                'appointments.manage', 'reception.checkin',
            ],
            self::TRIAGE_NURSE => [
                'patients.view', 'triage.record', 'reception.checkin',
            ],
            self::LABORATORY_TECHNICIAN => [
                'lab.orders.view', 'lab.orders.process', 'lab.results.enter',
            ],
            self::LABORATORY_MANAGER => [
                'lab.orders.view', 'lab.orders.process', 'lab.results.enter',
                'lab.results.verify', 'lab.results.release', 'reports.view',
            ],
            self::RADIOLOGIST => [
                'radiology.orders.view', 'radiology.orders.process', 'radiology.reports.verify',
            ],
            self::RADIOLOGY_TECHNICIAN => [
                'radiology.orders.view', 'radiology.orders.process',
            ],
            self::PHARMACIST => [
                'pharmacy.prescriptions.view', 'pharmacy.dispense', 'pharmacy.stock.manage',
            ],
            self::PHARMACY_TECHNICIAN => [
                'pharmacy.prescriptions.view', 'pharmacy.dispense',
            ],
            self::THEATRE_NURSE => [
                'theatre.schedule', 'theatre.procedures.manage',
            ],
            self::SURGEON => [
                'theatre.schedule', 'theatre.procedures.manage', 'theatre.records.sign',
                'patients.view', 'encounters.view', 'clinical_notes.create', 'clinical_notes.sign',
            ],
            self::ANESTHETIST => [
                'theatre.procedures.manage', 'theatre.records.sign', 'patients.view',
            ],
            self::WARD_CLERK => [
                'patients.view', 'wards.admissions.create', 'reports.view',
            ],
            self::ADMISSION_OFFICER => [
                'patients.view', 'wards.admissions.create', 'wards.beds.allocate',
            ],
            self::CASHIER => [
                'billing.invoices.view', 'billing.invoices.create', 'billing.payments.receive',
            ],
            self::ACCOUNTANT => [
                'billing.invoices.view', 'billing.refunds.approve', 'reports.view', 'reports.export',
            ],
            self::INSURANCE_OFFICER => [
                'insurance.manage', 'insurance.claims.submit', 'billing.invoices.view',
            ],
            self::PROCUREMENT_OFFICER => [
                'procurement.manage', 'inventory.manage',
            ],
            self::STOREKEEPER => [
                'inventory.manage',
            ],
            self::HR_OFFICER => [
                'staff.manage',
            ],
            self::AUDITOR => [
                'audit.view', 'reports.view', 'reports.export',
            ],
            self::RECORDS_OFFICER => [
                'patients.view', 'patients.update', 'patients.export',
            ],
        ];
    }
}
