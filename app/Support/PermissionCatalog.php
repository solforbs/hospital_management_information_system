<?php

namespace App\Support;

/**
 * The authoritative list of permission strings available in the system.
 * Grows as each module is implemented; a permission must exist here before
 * a role can be granted it.
 */
class PermissionCatalog
{
    public static function all(): array
    {
        return [
            // Platform / tenant administration
            'tenants.manage',
            'facilities.manage',
            'departments.manage',
            'staff.manage',
            'users.manage',
            'roles.manage',
            'permissions.manage',
            'audit.view',
            'settings.manage',

            // Patients
            'patients.view',
            'patients.create',
            'patients.update',
            'patients.merge',
            'patients.export',

            // Appointments and reception
            'appointments.view',
            'appointments.manage',
            'reception.checkin',
            'triage.record',
            'emergency.manage',

            // Encounters and clinical notes
            'encounters.view',
            'encounters.create',
            'encounters.update',
            'encounters.sign',
            'clinical_notes.view',
            'clinical_notes.create',
            'clinical_notes.sign',
            'clinical_notes.amend',

            // Orders
            'orders.create',
            'orders.view',
            'orders.process',

            // Laboratory
            'lab.orders.view',
            'lab.orders.process',
            'lab.results.enter',
            'lab.results.verify',
            'lab.results.release',
            'lab.catalog.manage',

            // Radiology
            'radiology.orders.view',
            'radiology.orders.process',
            'radiology.reports.verify',
            'radiology.catalog.manage',

            // Pharmacy
            'pharmacy.prescriptions.view',
            'pharmacy.dispense',
            'pharmacy.stock.manage',

            // Wards / inpatient
            'wards.view',
            'wards.manage',
            'wards.admissions.create',
            'wards.discharge',
            'wards.beds.allocate',
            'wards.transfers.approve',
            'nursing.tasks.manage',

            // Theatre
            'theatre.schedule',
            'theatre.procedures.manage',
            'theatre.records.sign',

            // Billing
            'billing.invoices.view',
            'billing.invoices.create',
            'billing.payments.receive',
            'billing.refunds.approve',
            'billing.discounts.approve',
            'billing.catalog.manage',

            // Insurance
            'insurance.manage',
            'insurance.claims.submit',

            // Procurement / inventory
            'inventory.manage',
            'procurement.manage',

            // Reporting
            'reports.view',
            'reports.export',
        ];
    }
}
