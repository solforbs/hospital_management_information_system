# Hospital Management System — Development Status

Last updated: 2026-09-26 (Phase 2 increment: patient registry + tenant admin UI)

## How to resume work

Read this file first. It reflects the actual state of the repository, not
the aspirational spec in `project_prompt.md`. Continue from "Next
implementation tasks" below. Do not re-plan from scratch.

## Stack (as actually installed, verified in this repo)

- Laravel 13.17, PHP 8.3, MySQL 8 (via XAMPP, `hospital_management_system` db)
- Livewire 4.4 with `livewire/flux` (Flux UI) and `livewire/blaze`
- Laravel Fortify 1.37 for authentication scaffolding (login, register,
  2FA, passkeys, email verification) — do not rebuild this, it already works
- Spatie Laravel Permission 8.3, **with the "teams" feature enabled**,
  `tenant_id` used as the team key (see "Tenancy design" below)
- Starter kit convention: `resources/views/pages/**` is the `pages::`
  Blade namespace, `resources/views/layouts/**` is `layouts::`. Pages are
  Livewire single-file components (`<?php new class extends Component {...}; ?>`
  followed by a single-root Blade template) registered via
  `Route::livewire($uri, 'pages::dotted.path')`. Do **not** wrap a page's
  template in `<x-layouts::app>` — `Route::livewire` already applies
  `layouts::app` per `config('livewire.component_layout')`; doing both
  causes a `MultipleRootElementsDetectedException`.
- Tests: PHPUnit (not Pest), sqlite `:memory:` for the test env
  (`phpunit.xml`), run with `php artisan test`. Style: `./vendor/bin/pint`.

## Tenancy design (Phase 1 foundation — implemented)

- `Tenant` (hospital/clinic), `TenantDomain`, `Facility`, `Department`,
  `StaffAssignment` models. `tenant_user` pivot table tracks membership
  (`status`, `is_default`, `joined_at`).
- `App\Models\Concerns\BelongsToTenant` trait: every tenant-owned model
  uses it. It adds a global scope filtering by
  `app(TenantContext::class)->id()` and auto-stamps `tenant_id` on create.
  **Every new tenant-owned model must use this trait.**
- `App\Services\Tenancy\TenantContext` — request-scoped singleton, the only
  source of truth for "which tenant is active". Never trust a tenant id
  from client input.
- `App\Http\Middleware\IdentifyTenant` — appended to the `web` middleware
  group (see `bootstrap/app.php`). For an authenticated request, resolves
  the tenant from the user's verified memberships (`tenant_user` where
  `status = active`), honouring a previously-selected `current_tenant_id`
  session value **only if the user still belongs to it**, otherwise falling
  back to the default/only membership. Also available as the `tenant` alias.
- Spatie Permission "teams" feature is enabled
  (`config/permission.php`: `teams => true`,
  `column_names.team_foreign_key => 'tenant_id'`). Roles are tenant-scoped;
  permissions are global (shared catalog, not tenant-scoped — see
  `App\Support\PermissionCatalog`). The team id resolver is
  `App\Services\Tenancy\TenantTeamResolver`, which delegates to
  `TenantContext` unless explicitly overridden (needed for provisioning
  and console/seeder code where there is no active HTTP tenant context).
  - **Gotcha (already fixed, don't reintroduce):** Spatie's
    `PermissionRegistrar` caches its permission list in-process on first
    read and does **not** invalidate that cache when a `Permission` is
    created via a plain Eloquent `create()`/`findOrCreate()` (no model
    boot hook does that, unlike role/permission *assignment* which does
    flush the cache). If you create permissions and then immediately
    `syncPermissions()`/`assignRole()` against them in the same process,
    call `app(PermissionRegistrar::class)->forgetCachedPermissions()`
    after creating, before syncing. `TenantProvisioningService::ensurePermissionsExist()`
    does this already — reuse it rather than creating permissions ad hoc.
  - **Gotcha:** Spatie's `model_has_roles`/`model_has_permissions` pivot
    tables require a **non-null** team id when teams are enabled (the
    migration does not make it nullable even though `roles.tenant_id` is
    nullable). So there is no such thing as a "platform-wide Spatie role"
    in this setup. Platform Super Administrator is modelled as a plain
    `users.is_platform_admin` boolean instead — see `User::isPlatformAdmin()`.
- `App\Services\Tenancy\TenantProvisioningService` is the **only** place
  that should create a tenant. It creates the tenant, its main facility,
  8 default departments (Reception/OPD/Lab/Radiology/Pharmacy/Wards/
  Theatre/Billing), the global permission catalog if missing, and the
  full default role set (`App\Support\RoleCatalog::tenantDefaults()`)
  scoped to that tenant with baseline permissions
  (`RoleCatalog::defaultPermissions()`).
- `App\Support\PermissionCatalog` / `App\Support\RoleCatalog` are the
  single source of truth for permission strings and role names. Add new
  permissions here as each module is built; a role can't be granted a
  permission that isn't in the catalog.
- Demo data: `database/seeders/DemoTenantSeeder.php` creates tenant
  "Nairobi General Hospital" with one user per role
  (`{role}@demo.hms.test` / password `password`, e.g. `admin@demo.hms.test`,
  `doctor@demo.hms.test`, `reception@demo.hms.test`, `nurse@demo.hms.test`,
  `lab@demo.hms.test`, `pharmacy@demo.hms.test`, `cashier@demo.hms.test`).
  Platform admin: `platform@hms.test` / `password` (`is_platform_admin=true`,
  not tied to any tenant). Run `php artisan migrate:fresh --seed` to get a
  clean working environment.

## What is real and working right now

- MySQL connection configured and working (`.env`: `DB_CONNECTION=mysql`,
  db `hospital_management_system` on XAMPP's local MySQL). Note: this local
  MySQL instance is unusually slow for DDL (each migration ~1-50s) — not a
  code problem, just environment; don't be alarmed by slow `migrate` runs.
- Full migration set applies cleanly (`php artisan migrate:fresh`).
- `DatabaseSeeder` → `DemoTenantSeeder` → `TenantProvisioningService`
  provisions a working demo tenant with 7 role-representative users, all
  verified via `php artisan tinker` to have correctly scoped roles/permissions
  (e.g. Doctor can `lab.orders.view` but not `billing.payments.receive`).
- `User` model: added `HasRoles` (Spatie), `tenants()`, `staffAssignments()`,
  `isPlatformAdmin()`, `is_platform_admin` column/cast.
- Dashboard (`resources/views/pages/dashboard.blade.php`, a real Livewire
  page, not a static placeholder) shows the active tenant, lets a user with
  multiple tenant memberships switch between them (re-validated server-side
  against real membership — a user cannot switch into a tenant they don't
  belong to), and lists the current user's resolved roles/permissions. This
  is intentionally the full extent of the "dashboard" for now — role-specific
  dashboards (reception queue, doctor worklist, etc.) are later-phase work.
- Sidebar shows the active tenant name.
- Automated tests (`php artisan test`): **40 passed, 0 failed**, including:
  - all pre-existing Fortify/starter-kit auth tests (login, registration,
    2FA, password reset/confirmation, email verification, profile, security)
  - `tests/Feature/Tenancy/TenantIsolationTest.php`: tenant resolved from
    membership not client input; a user cannot switch into a tenant they
    don't belong to (server-side re-validation); a model using
    `BelongsToTenant` is invisible when the active tenant context differs
  - `tests/Feature/Tenancy/RolePermissionTest.php`: provisioning creates the
    standard role set; a role grants only its assigned permissions; roles/
    permissions do not leak across tenants (same role name, different tenant
    → independent grants)
- `./vendor/bin/pint --dirty` clean (all changed files pass style).
- Git: repository initialized (`git init`) in what was previously an
  un-versioned directory. First commit captured the untouched starter kit
  baseline; work since then is uncommitted pending your review — see
  "Next implementation tasks".

## Phase 2 increment — patient registry and tenant administration UI

This is what actually closes the "sidebar has nothing in it" gap: the
dashboard from Phase 1 only ever showed roles/permissions, there were no
other pages to link to. Now there are, and the sidebar links to them
based on the signed-in user's real permissions (`@can`/`@canany` in
`resources/views/layouts/app/sidebar.blade.php`) — a Cashier or Doctor
will legitimately see fewer sidebar sections than a Hospital Administrator.

- `Patient` model/migration (`BelongsToTenant`), `PatientNumberSequence`
  (`tenant_id`+`year`, row-locked via `lockForUpdate()`), and
  `App\Services\Patients\PatientRegistrationService` — the only place a
  `Patient` should be created. MRN format: `{PREFIX}-{YY}-{000001}`,
  prefix from `Facility.patient_number_prefix` or the tenant slug.
  Concurrency-safe: two simultaneous registrations in the same
  tenant/year cannot get the same MRN (verified via the unique
  `(tenant_id, year)` constraint + row lock; the sequence row is
  created lazily with a race-safe create/retry).
- Livewire pages under `resources/views/pages/patients/`: `index` (search
  by MRN/name/phone/national ID, paginated), `create` (registration form),
  `show` (profile view; explicitly a stub for future encounters/history —
  says so in the UI rather than pretending it's complete). Routes:
  `patients.index` / `patients.create` / `patients.show` (`GET
  /patients`, `/patients/create`, `/patients/{patient}`). Route model
  binding on `{patient}` automatically respects the `BelongsToTenant`
  scope, so a patient ID from another tenant 404s rather than leaking.
  Gated on `patients.view` / `patients.create` via `abort_unless(...->can(...))`
  in each component's `mount()`.
- Tenant administration pages under `resources/views/pages/admin/`:
  `facilities` (list + create + activate/deactivate, gated on
  `facilities.manage`), `departments` (list + create + activate/deactivate,
  gated on `departments.manage`), `staff` (list tenant members with their
  role/facility, add a staff member — creates the `User` if the email is
  new or attaches an existing one, creates a `StaffAssignment`, calls
  `assignRole()` — gated on `staff.manage`). Routes: `admin.facilities` /
  `admin.departments` / `admin.staff`.
- Sidebar (`resources/views/layouts/app/sidebar.blade.php`) reorganized
  into permission-gated groups: "Overview" (Dashboard, always visible),
  "Clinical" (Patients, if `patients.view`), "Administration" (Facilities/
  Departments/Staff, each independently gated, group itself gated on
  `@canany` of the three so it disappears entirely for clinical/ops roles).
- Tests added: `tests/Feature/Patients/PatientRegistrationTest.php`
  (registration produces an MRN, permission enforcement, cross-tenant
  isolation of `Patient` records), `tests/Feature/Patients/PatientPagesRenderTest.php`
  and `tests/Feature/Admin/AdminPagesRenderTest.php` (real HTTP GETs
  against every new route, not just component-level tests — this is
  deliberate: component tests alone would not have caught a bad Blade
  reference or a double-layout-wrap bug like the one hit in Phase 1).
  **47 passed, 0 failed** as of this increment.

### What a logged-in user sees now, by role (demo tenant)

- `admin@demo.hms.test` (Hospital Administrator): Dashboard, Patients,
  and all three Administration pages.
- `doctor@demo.hms.test`: Dashboard, Patients (view only — no "Register
  patient" button, since Doctor doesn't have `patients.create`; that's
  intentional per `RoleCatalog::defaultPermissions()`, not a bug).
- `reception@demo.hms.test`: Dashboard, Patients (with "Register patient").
- `nurse@demo.hms.test` (Triage Nurse role): Dashboard, Patients (view
  only — Triage Nurse has `patients.view` but not `patients.create`).
- `lab@demo.hms.test`, `pharmacy@demo.hms.test`, `cashier@demo.hms.test`:
  Dashboard only, for now — none of Laboratory Technician/Pharmacist/
  Cashier has `patients.view` or any admin permission, and their actual
  worklists (lab worklist, pharmacy queue, cashier till) are not built
  yet. This is expected, not a regression — see next section.

## Known limitations / things intentionally deferred

- No facility-selector UI yet (tenant selector exists on the dashboard;
  facility-level switching is not built — most tenants only have one
  facility so far, that's fine for now).
- No Livewire/Reverb real-time infrastructure yet (Phase 4 in the spec).
  `BROADCAST_CONNECTION=log` currently — Reverb is not installed/configured.
- No policies/Gates beyond Spatie's permission-based `can()` checks yet. A
  proper `TenantPolicy` / route-level authorization for
  "can this user manage this tenant" (platform-admin-only tenant CRUD) is
  not built — there is no tenant management UI yet either.
- REDIS_* env vars are present from the starter kit but Redis is not
  confirmed installed/running locally; queues/cache currently use
  `database` driver, which works fine for now.
- The permission catalog in `PermissionCatalog` is a reasonable starting
  set inferred from the spec's section 24 examples plus obvious gaps, not
  an exhaustive final list — expect to add permissions as each module
  (laboratory, pharmacy, wards, theatre, billing...) is actually built.
- `RoleCatalog::defaultPermissions()` baseline grants are a reasonable
  starting point, not a clinically-reviewed final policy. Tenant admins
  are expected to be able to adjust role permissions later (permission
  management UI not yet built).

## Next implementation tasks (in order)

Done since the last version of this list: facility/department management
UI, staff management UI, patient registry (items 3-5 below, previously).
Still uncommitted at time of writing — see git status before assuming
this is on a branch/pushed anywhere.

1. Platform administration: tenant CRUD for platform admins
   (`is_platform_admin` gate), tenant activation/suspension, using
   `TenantProvisioningService` as the only creation path. Add a
   `TenantPolicy`. (No UI yet — platform admin login exists
   (`platform@hms.test`) but has nothing to do once logged in.)
2. Patient registry follow-ups the spec calls for that aren't built yet:
   duplicate detection on register (configurable demographic match, e.g.
   name+DOB+phone — do not auto-merge, just warn), an authorized patient
   merge workflow with audit trail, editing an existing patient's details
   (currently create-only, no edit page).
3. A facility selector for multi-facility tenants — right now
   `TenantContext::facility()` exists and `IdentifyTenant` sets tenant but
   never sets a facility; patient registration passes
   `$context->facility()` which is currently always null. Needs UI +
   session-based selection analogous to the tenant switcher on the
   dashboard, re-validated server-side against `StaffAssignment` the same
   way tenant switching is re-validated against `tenant_user`.
4. Appointments and reception (Phase 3 of the spec): this is the next
   *big* chunk — appointment booking/calendar, check-in, a live queue,
   and the reception dashboard the spec describes. This is also where an
   `Encounter` model needs to appear (referenced all over later phases —
   Patient exists but there is no visit/encounter concept yet, so nothing
   clinical can be recorded against a patient today beyond demographics).
5. Continue per spec `project_prompt.md` phases 4+ (Reverb, EMR/consultation,
   laboratory, radiology, pharmacy, wards, theatre, maternity,
   billing/insurance, reporting) — each phase should add its permissions to
   `PermissionCatalog`, extend `RoleCatalog::defaultPermissions()` as
   appropriate, and follow the `BelongsToTenant` convention for every new
   tenant-owned table. Each new module's Livewire pages should follow the
   pattern in `resources/views/pages/patients/` and `resources/views/pages/admin/`
   (permission check in `mount()` via `abort_unless(...->can(...))`, single
   root element, no `<x-layouts::app>` wrapper) and get an entry in the
   sidebar gated the same way.

## Architecture decisions worth remembering

- Permissions are **global** (one shared catalog across all tenants);
  roles are **tenant-scoped** (each tenant gets its own copy of each
  default role, independently customizable). This matches "tenant-aware
  roles" from the spec while keeping the permission catalog centrally
  maintained as the app grows.
- Tenant creation is centralized in `TenantProvisioningService` on purpose
  — do not `Tenant::create()` directly anywhere else, or default
  facility/departments/roles won't be seeded consistently.
- `BelongsToTenant` is a global scope, not a manual `where('tenant_id', ...)`
  sprinkled through queries — this is the tenant-isolation guarantee the
  spec requires ("every query must enforce tenant isolation"). Any new
  tenant-owned Eloquent model must use it.
