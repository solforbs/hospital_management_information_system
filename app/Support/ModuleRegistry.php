<?php

namespace App\Support;

use App\Models\User;

/**
 * Single source of truth for the application's modules: drives the
 * sidebar, the dashboard module selector, and the role-aware landing
 * redirect after login. A module is visible only if the user holds at
 * least one of its permissions in the active tenant.
 */
class ModuleRegistry
{
    /**
     * Ordered by landing priority: the first module a user can access is
     * where they land after login (so a cashier lands on the cashier desk,
     * a lab technician on the lab worklist, etc.).
     */
    public static function modules(): array
    {
        return [
            // group, key, label, route, route pattern, icon, permissions(any)
            ['group' => 'Clinical', 'key' => 'consultation', 'label' => 'Doctor worklist', 'route' => 'consultation.index', 'pattern' => 'consultation.*', 'icon' => 'clipboard-document-list', 'permissions' => ['encounters.create']],
            ['group' => 'Front office', 'key' => 'reception', 'label' => 'Reception', 'route' => 'reception.index', 'pattern' => 'reception.*', 'icon' => 'queue-list', 'permissions' => ['reception.checkin']],
            ['group' => 'Clinical', 'key' => 'triage', 'label' => 'Triage', 'route' => 'triage.index', 'pattern' => 'triage.*', 'icon' => 'heart', 'permissions' => ['triage.record']],
            ['group' => 'Clinical', 'key' => 'emergency', 'label' => 'Emergency', 'route' => 'emergency.index', 'pattern' => 'emergency.*', 'icon' => 'bolt', 'permissions' => ['emergency.manage']],
            ['group' => 'Diagnostics', 'key' => 'laboratory', 'label' => 'Laboratory', 'route' => 'lab.worklist', 'pattern' => 'lab.worklist', 'icon' => 'beaker', 'permissions' => ['lab.orders.process']],
            ['group' => 'Diagnostics', 'key' => 'radiology', 'label' => 'Radiology', 'route' => 'radiology.worklist', 'pattern' => 'radiology.worklist', 'icon' => 'photo', 'permissions' => ['radiology.orders.process']],
            ['group' => 'Pharmacy', 'key' => 'pharmacy', 'label' => 'Dispensing', 'route' => 'pharmacy.queue', 'pattern' => 'pharmacy.queue', 'icon' => 'shopping-bag', 'permissions' => ['pharmacy.dispense']],
            ['group' => 'Inpatient', 'key' => 'wards', 'label' => 'Ward board', 'route' => 'wards.board', 'pattern' => 'wards.board', 'icon' => 'home-modern', 'permissions' => ['wards.view', 'nursing.tasks.manage']],
            ['group' => 'Inpatient', 'key' => 'admissions', 'label' => 'Admissions', 'route' => 'wards.admissions', 'pattern' => 'wards.admissions*', 'icon' => 'arrow-right-end-on-rectangle', 'permissions' => ['wards.beds.allocate', 'wards.admissions.create']],
            ['group' => 'Inpatient', 'key' => 'theatre', 'label' => 'Theatre', 'route' => 'theatre.index', 'pattern' => 'theatre.*', 'icon' => 'scissors', 'permissions' => ['theatre.schedule', 'theatre.procedures.manage']],
            ['group' => 'Finance', 'key' => 'billing', 'label' => 'Cashier', 'route' => 'billing.index', 'pattern' => 'billing.index', 'icon' => 'banknotes', 'permissions' => ['billing.payments.receive', 'billing.invoices.view']],
            ['group' => 'Finance', 'key' => 'insurance', 'label' => 'Insurance', 'route' => 'insurance.index', 'pattern' => 'insurance.*', 'icon' => 'shield-check', 'permissions' => ['insurance.manage']],
            ['group' => 'Front office', 'key' => 'appointments', 'label' => 'Appointments', 'route' => 'appointments.index', 'pattern' => 'appointments.*', 'icon' => 'calendar-days', 'permissions' => ['appointments.view', 'appointments.manage']],
            ['group' => 'Front office', 'key' => 'patients', 'label' => 'Patients', 'route' => 'patients.index', 'pattern' => 'patients.*', 'icon' => 'users', 'permissions' => ['patients.view']],
            ['group' => 'Pharmacy', 'key' => 'medicines', 'label' => 'Medicines & stock', 'route' => 'pharmacy.medicines', 'pattern' => 'pharmacy.medicines', 'icon' => 'archive-box', 'permissions' => ['pharmacy.stock.manage', 'inventory.manage']],
            ['group' => 'Diagnostics', 'key' => 'lab-catalog', 'label' => 'Lab test catalog', 'route' => 'lab.catalog', 'pattern' => 'lab.catalog', 'icon' => 'list-bullet', 'permissions' => ['lab.catalog.manage']],
            ['group' => 'Diagnostics', 'key' => 'radiology-catalog', 'label' => 'Imaging catalog', 'route' => 'radiology.catalog', 'pattern' => 'radiology.catalog', 'icon' => 'list-bullet', 'permissions' => ['radiology.catalog.manage']],
            ['group' => 'Inpatient', 'key' => 'ward-setup', 'label' => 'Wards & beds', 'route' => 'wards.setup', 'pattern' => 'wards.setup', 'icon' => 'cog-6-tooth', 'permissions' => ['wards.manage']],
            ['group' => 'Finance', 'key' => 'services', 'label' => 'Service tariffs', 'route' => 'billing.services', 'pattern' => 'billing.services', 'icon' => 'tag', 'permissions' => ['billing.catalog.manage']],
            ['group' => 'Management', 'key' => 'reports', 'label' => 'Reports', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'chart-bar', 'permissions' => ['reports.view']],
            ['group' => 'Management', 'key' => 'audit', 'label' => 'Audit log', 'route' => 'admin.audit', 'pattern' => 'admin.audit', 'icon' => 'finger-print', 'permissions' => ['audit.view']],
            ['group' => 'Administration', 'key' => 'facilities', 'label' => 'Facilities', 'route' => 'admin.facilities', 'pattern' => 'admin.facilities', 'icon' => 'building-office-2', 'permissions' => ['facilities.manage']],
            ['group' => 'Administration', 'key' => 'departments', 'label' => 'Departments', 'route' => 'admin.departments', 'pattern' => 'admin.departments', 'icon' => 'squares-2x2', 'permissions' => ['departments.manage']],
            ['group' => 'Administration', 'key' => 'staff', 'label' => 'Staff', 'route' => 'admin.staff', 'pattern' => 'admin.staff', 'icon' => 'identification', 'permissions' => ['staff.manage']],
        ];
    }

    public static function groupOrder(): array
    {
        return ['Front office', 'Clinical', 'Diagnostics', 'Pharmacy', 'Inpatient', 'Finance', 'Management', 'Administration'];
    }

    public static function forUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        return array_values(array_filter(
            self::modules(),
            fn (array $m) => collect($m['permissions'])->contains(fn ($p) => $user->can($p))
        ));
    }

    /**
     * @return array<string, array<int, array>>
     */
    public static function groupedForUser(?User $user): array
    {
        $grouped = collect(self::forUser($user))->groupBy('group');

        return collect(self::groupOrder())
            ->filter(fn ($g) => $grouped->has($g))
            ->mapWithKeys(fn ($g) => [$g => $grouped[$g]->all()])
            ->all();
    }

    public static function landingRoute(?User $user): string
    {
        if ($user?->isPlatformAdmin() && ! $user->tenants()->exists()) {
            return route('platform.tenants');
        }

        // Administrators land on the hospital operations dashboard.
        if ($user?->can('settings.manage')) {
            return route('dashboard');
        }

        $available = collect(self::forUser($user))->keyBy('key');

        foreach (self::landingPriority() as $key) {
            if ($available->has($key)) {
                return route($available[$key]['route']);
            }
        }

        $first = $available->first();

        return $first ? route($first['route']) : route('dashboard');
    }

    /**
     * Department workspaces win over generic modules: a ward nurse (who can
     * also triage) lands on the ward board, a triage nurse (who can also
     * check in) lands on triage, a receptionist on reception.
     */
    public static function landingPriority(): array
    {
        return ['consultation', 'laboratory', 'radiology', 'pharmacy', 'billing', 'insurance', 'theatre', 'wards', 'admissions', 'triage', 'reception', 'emergency', 'appointments', 'patients', 'reports', 'medicines'];
    }
}
