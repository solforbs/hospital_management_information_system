<?php

namespace App\Support;

use App\Models\Facility;
use App\Models\User;
use App\Services\Tenancy\TenantContext;
use Illuminate\Support\Str;

/**
 * Single source of truth for the application's modules: drives the
 * sidebar, the dashboard module selector, the role-aware landing redirect
 * and route access. A module is visible when the user holds one of its
 * permissions AND the active facility offers it — by KEPH level
 * ('min_level') unless an administrator overrode it for that facility.
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
            // group, key, label, route, route pattern, icon, permissions(any), min KEPH level
            ['group' => 'Clinical', 'key' => 'consultation', 'label' => 'Doctor worklist', 'route' => 'consultation.index', 'pattern' => 'consultation.*', 'icon' => 'clipboard-document-list', 'permissions' => ['encounters.create'], 'min_level' => 2],
            ['group' => 'Front office', 'key' => 'reception', 'label' => 'Reception', 'route' => 'reception.index', 'pattern' => 'reception.*', 'icon' => 'queue-list', 'permissions' => ['reception.checkin'], 'min_level' => 1],
            ['group' => 'Clinical', 'key' => 'triage', 'label' => 'Triage', 'route' => 'triage.index', 'pattern' => 'triage.*', 'icon' => 'heart', 'permissions' => ['triage.record'], 'min_level' => 2],
            ['group' => 'Clinical', 'key' => 'emergency', 'label' => 'Emergency', 'route' => 'emergency.index', 'pattern' => 'emergency.*', 'icon' => 'bolt', 'permissions' => ['emergency.manage'], 'min_level' => 3],
            ['group' => 'Diagnostics', 'key' => 'laboratory', 'label' => 'Laboratory', 'route' => 'lab.worklist', 'pattern' => 'lab.worklist', 'icon' => 'beaker', 'permissions' => ['lab.orders.process'], 'min_level' => 2],
            ['group' => 'Diagnostics', 'key' => 'radiology', 'label' => 'Radiology', 'route' => 'radiology.worklist', 'pattern' => 'radiology.worklist', 'icon' => 'photo', 'permissions' => ['radiology.orders.process'], 'min_level' => 4],
            ['group' => 'Pharmacy', 'key' => 'pharmacy', 'label' => 'Dispensing', 'route' => 'pharmacy.queue', 'pattern' => 'pharmacy.queue', 'icon' => 'shopping-bag', 'permissions' => ['pharmacy.dispense'], 'min_level' => 2],
            ['group' => 'Maternity', 'key' => 'maternity', 'label' => 'ANC / PNC clinic', 'route' => 'maternity.index', 'pattern' => 'maternity.*', 'icon' => 'heart', 'permissions' => ['maternity.view', 'maternity.manage'], 'min_level' => 2],
            ['group' => 'Maternity', 'key' => 'child-welfare', 'label' => 'Child welfare clinic', 'route' => 'cwc.index', 'pattern' => 'cwc.*', 'icon' => 'face-smile', 'permissions' => ['cwc.manage'], 'min_level' => 2],
            ['group' => 'Maternity', 'key' => 'labour-ward', 'label' => 'Labour ward', 'route' => 'labour.index', 'pattern' => 'labour.*', 'icon' => 'sparkles', 'permissions' => ['maternity.deliver'], 'min_level' => 3],
            ['group' => 'Inpatient', 'key' => 'critical-care', 'label' => 'Critical care', 'route' => 'critical-care.index', 'pattern' => 'critical-care.*', 'icon' => 'heart', 'permissions' => ['critical_care.chart'], 'min_level' => 4],
            ['group' => 'Inpatient', 'key' => 'wards', 'label' => 'Ward board', 'route' => 'wards.board', 'pattern' => 'wards.board', 'icon' => 'home-modern', 'permissions' => ['wards.view', 'nursing.tasks.manage'], 'min_level' => 3],
            ['group' => 'Inpatient', 'key' => 'admissions', 'label' => 'Admissions', 'route' => 'wards.admissions', 'pattern' => 'wards.admissions*', 'icon' => 'arrow-right-end-on-rectangle', 'permissions' => ['wards.beds.allocate', 'wards.admissions.create'], 'min_level' => 3],
            ['group' => 'Inpatient', 'key' => 'theatre', 'label' => 'Theatre', 'route' => 'theatre.index', 'pattern' => 'theatre.*', 'icon' => 'scissors', 'permissions' => ['theatre.schedule', 'theatre.procedures.manage'], 'min_level' => 4],
            ['group' => 'Finance', 'key' => 'billing', 'label' => 'Cashier', 'route' => 'billing.index', 'pattern' => 'billing.index', 'icon' => 'banknotes', 'permissions' => ['billing.payments.receive', 'billing.invoices.view'], 'min_level' => 1],
            ['group' => 'Finance', 'key' => 'insurance', 'label' => 'Insurance', 'route' => 'insurance.index', 'pattern' => 'insurance.*', 'icon' => 'shield-check', 'permissions' => ['insurance.manage'], 'min_level' => 1],
            ['group' => 'Front office', 'key' => 'appointments', 'label' => 'Appointments', 'route' => 'appointments.index', 'pattern' => 'appointments.*', 'icon' => 'calendar-days', 'permissions' => ['appointments.view', 'appointments.manage'], 'min_level' => 1],
            ['group' => 'Front office', 'key' => 'referrals', 'label' => 'Referrals', 'route' => 'referrals.index', 'pattern' => 'referrals.*', 'icon' => 'arrows-right-left', 'permissions' => ['referrals.view', 'referrals.manage'], 'min_level' => 1],
            ['group' => 'Front office', 'key' => 'patients', 'label' => 'Patients', 'route' => 'patients.index', 'pattern' => 'patients.*', 'icon' => 'users', 'permissions' => ['patients.view'], 'min_level' => 1],
            ['group' => 'Pharmacy', 'key' => 'medicines', 'label' => 'Medicines & stock', 'route' => 'pharmacy.medicines', 'pattern' => 'pharmacy.medicines', 'icon' => 'archive-box', 'permissions' => ['pharmacy.stock.manage', 'inventory.manage'], 'min_level' => 2],
            ['group' => 'Diagnostics', 'key' => 'lab-catalog', 'label' => 'Lab test catalog', 'route' => 'lab.catalog', 'pattern' => 'lab.catalog', 'icon' => 'list-bullet', 'permissions' => ['lab.catalog.manage'], 'min_level' => 2],
            ['group' => 'Diagnostics', 'key' => 'radiology-catalog', 'label' => 'Imaging catalog', 'route' => 'radiology.catalog', 'pattern' => 'radiology.catalog', 'icon' => 'list-bullet', 'permissions' => ['radiology.catalog.manage'], 'min_level' => 4],
            ['group' => 'Inpatient', 'key' => 'ward-setup', 'label' => 'Wards & beds', 'route' => 'wards.setup', 'pattern' => 'wards.setup', 'icon' => 'cog-6-tooth', 'permissions' => ['wards.manage'], 'min_level' => 3],
            ['group' => 'Finance', 'key' => 'services', 'label' => 'Service tariffs', 'route' => 'billing.services', 'pattern' => 'billing.services', 'icon' => 'tag', 'permissions' => ['billing.catalog.manage'], 'min_level' => 1],
            ['group' => 'Management', 'key' => 'reports', 'label' => 'Reports', 'route' => 'reports.index', 'pattern' => 'reports.*', 'icon' => 'chart-bar', 'permissions' => ['reports.view'], 'min_level' => 1],
            ['group' => 'Management', 'key' => 'audit', 'label' => 'Audit log', 'route' => 'admin.audit', 'pattern' => 'admin.audit', 'icon' => 'finger-print', 'permissions' => ['audit.view'], 'min_level' => 1, 'core' => true],
            ['group' => 'Administration', 'key' => 'institution', 'label' => 'Institution profile', 'route' => 'admin.institution', 'pattern' => 'admin.institution', 'icon' => 'building-library', 'permissions' => ['settings.manage'], 'min_level' => 1, 'core' => true],
            ['group' => 'Administration', 'key' => 'facilities', 'label' => 'Facilities', 'route' => 'admin.facilities', 'pattern' => 'admin.facilities', 'icon' => 'building-office-2', 'permissions' => ['facilities.manage'], 'min_level' => 1, 'core' => true],
            ['group' => 'Administration', 'key' => 'departments', 'label' => 'Departments', 'route' => 'admin.departments', 'pattern' => 'admin.departments', 'icon' => 'squares-2x2', 'permissions' => ['departments.manage'], 'min_level' => 1, 'core' => true],
            ['group' => 'Administration', 'key' => 'integrations', 'label' => 'SHA & HIE integration', 'route' => 'admin.integrations', 'pattern' => 'admin.integrations', 'icon' => 'link', 'permissions' => ['integrations.manage'], 'min_level' => 1, 'core' => true],
            ['group' => 'Administration', 'key' => 'roles', 'label' => 'Roles & permissions', 'route' => 'admin.roles', 'pattern' => 'admin.roles', 'icon' => 'key', 'permissions' => ['roles.manage'], 'min_level' => 1, 'core' => true],
            ['group' => 'Stores', 'key' => 'stores', 'label' => 'Stores & purchasing', 'route' => 'stores.index', 'pattern' => 'stores.*', 'icon' => 'cube', 'permissions' => ['inventory.manage', 'procurement.manage'], 'min_level' => 2],
            ['group' => 'Administration', 'key' => 'staff', 'label' => 'Staff', 'route' => 'admin.staff', 'pattern' => 'admin.staff', 'icon' => 'identification', 'permissions' => ['staff.manage'], 'min_level' => 1, 'core' => true],
            ['group' => 'Administration', 'key' => 'training-records', 'label' => 'Training records', 'route' => 'admin.training-records', 'pattern' => 'admin.training-records', 'icon' => 'academic-cap', 'permissions' => ['staff.manage', 'audit.view'], 'min_level' => 1, 'core' => true],
            ['group' => 'Specialist services', 'key' => 'mortuary', 'label' => 'Mortuary', 'route' => 'mortuary.index', 'pattern' => 'mortuary.*', 'icon' => 'archive-box-x-mark', 'permissions' => ['mortuary.manage'], 'min_level' => 4],
            ['group' => 'Specialist services', 'key' => 'blood-bank', 'label' => 'Blood bank', 'route' => 'bloodbank.index', 'pattern' => 'bloodbank.*', 'icon' => 'beaker', 'permissions' => ['bloodbank.manage', 'bloodbank.request'], 'min_level' => 4],
            ['group' => 'Specialist services', 'key' => 'dialysis', 'label' => 'Renal / dialysis', 'route' => 'dialysis.index', 'pattern' => 'dialysis.*', 'icon' => 'arrow-path-rounded-square', 'permissions' => ['dialysis.manage'], 'min_level' => 5],
            ...self::clinicModules(),
        ];
    }

    /** One module per specialist clinic (App\Support\SpecialtyClinics). */
    protected static function clinicModules(): array
    {
        return collect(SpecialtyClinics::CLINICS)->map(fn ($c, $key) => [
            'group' => 'Specialist services', 'key' => 'clinic-'.$key, 'label' => $c['label'], 'route' => 'clinics.'.$key,
            'pattern' => 'clinics.'.$key, 'icon' => $c['icon'], 'permissions' => ['clinics.manage'], 'min_level' => $c['min_level'],
        ])->values()->all();
    }

    /**
     * Colour identity per sidebar section: [main, gradient partner].
     * Pages inherit their section's colour (headings, card strips, tabs,
     * primary buttons) and the sidebar icons use the same colours.
     */
    public const GROUP_COLORS = [
        'Overview' => ['#4E7CFF', '#7033FF'],
        'Front office' => ['#4E7CFF', '#1FB6D9'],
        'Clinical' => ['#F65164', '#F5872F'],
        'Maternity' => ['#E84393', '#7033FF'],
        'Diagnostics' => ['#7033FF', '#4E7CFF'],
        'Pharmacy' => ['#14B8A6', '#1FB6D9'],
        'Inpatient' => ['#F5872F', '#F65164'],
        'Specialist services' => ['#C2410C', '#E84393'],
        'Finance' => ['#22A06B', '#14B8A6'],
        'Stores' => ['#8B5CF6', '#1FB6D9'],
        'Management' => ['#1FB6D9', '#4E7CFF'],
        'Administration' => ['#6A4DFF', '#4E7CFF'],
    ];

    /** @return array{0: string, 1: string} */
    public static function colorsForGroup(?string $group): array
    {
        return self::GROUP_COLORS[$group] ?? self::GROUP_COLORS['Overview'];
    }

    /** Colours for the page at the given route (its module's section). */
    public static function colorsForRoute(?string $routeName): array
    {
        $module = $routeName ? self::moduleForRoute($routeName) : null;

        // Detail pages without their own module entry inherit from their family.
        $module ??= match (true) {
            $routeName === null => null,
            str_starts_with($routeName, 'wards.') => self::find('wards'),
            str_starts_with($routeName, 'sha.') => self::find('insurance'),
            str_starts_with($routeName, 'billing.') => self::find('billing'),
            str_starts_with($routeName, 'admin.') => self::find('facilities'),
            default => null,
        };

        return self::colorsForGroup($module['group'] ?? null);
    }

    public static function groupOrder(): array
    {
        return ['Front office', 'Clinical', 'Maternity', 'Diagnostics', 'Pharmacy', 'Inpatient', 'Specialist services', 'Finance', 'Stores', 'Management', 'Administration'];
    }

    public static function find(string $key): ?array
    {
        return collect(self::modules())->firstWhere('key', $key);
    }

    /** Modules an administrator may switch on/off per facility (core admin pages cannot be disabled). */
    public static function toggleable(): array
    {
        return array_values(array_filter(self::modules(), fn ($m) => empty($m['core'])));
    }

    public static function moduleForRoute(string $routeName): ?array
    {
        return collect(self::modules())->first(fn ($m) => Str::is($m['pattern'], $routeName));
    }

    /** Is the module offered at this level by default? A null level offers everything. */
    public static function defaultAtLevel(array $module, ?int $level): bool
    {
        return $level === null || $level >= ($module['min_level'] ?? 1);
    }

    public static function enabledAt(?Facility $facility, string $key): bool
    {
        $module = self::find($key);

        if (! $module || ! $facility || ! empty($module['core'])) {
            return true;
        }

        $overrides = $facility->enabled_modules ?? [];

        if (array_key_exists($key, $overrides)) {
            return (bool) $overrides[$key];
        }

        return self::defaultAtLevel($module, $facility->level);
    }

    public static function forUser(?User $user): array
    {
        if (! $user) {
            return [];
        }

        $facility = app(TenantContext::class)->facility();

        return array_values(array_filter(
            self::modules(),
            fn (array $m) => self::enabledAt($facility, $m['key'])
                && collect($m['permissions'])->contains(fn ($p) => $user->can($p))
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
        return ['consultation', 'laboratory', 'radiology', 'pharmacy', 'billing', 'insurance', 'theatre', 'wards', 'critical-care', 'admissions', 'labour-ward', 'maternity', 'child-welfare', 'dialysis', 'blood-bank', 'mortuary', 'triage', 'reception', 'emergency', 'appointments', 'referrals', 'patients', 'reports', 'medicines'];
    }
}
