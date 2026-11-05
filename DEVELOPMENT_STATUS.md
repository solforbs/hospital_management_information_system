# Hospital Management System — Development Status

Last updated: 2026-11-04 (Phases 3–12: clinical, inpatient, finance, reporting and platform modules)

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

## Phases 3–12 increment — clinical, inpatient, finance and platform modules (2026-11-04)

Everything below is built, migrated on the local MySQL db, and exercised
end-to-end (see "Verification").

### Shared infrastructure
- `App\Services\Numbering\NumberSequenceService`: tenant-scoped, row-locked
  numbering for every document (`ENC-26-000001`, `LAB-`, `RAD-`, `RX-`, `INV-`,
  `RCT-`, `ADM-`, `SUR-`, `CLM-`, `APT-`, `SPM-`) and daily queue tickets.
- `App\Services\Audit\AuditLogger` + `audit_logs`; viewer at `admin.audit`.
  Logs patient views/edits, note sign/amend, result verify/release,
  dispensing, stock adjustments, payments, discounts, voids, tariff changes,
  admissions/transfers/discharges, theatre events, claims, report exports,
  and tenant create/suspend/activate.
- `App\Support\EncounterStage` is the patient-flow workflow. `Encounter` is the
  visit, and every department order hangs off it. Each stage change writes an
  `encounter_transitions` row (actor, from, to, reason, time).
- `App\Support\ModuleRegistry` is the single source for the sidebar, the
  dashboard module selector and the **role-aware landing** (`/home`, now
  `config('fortify.home')`): cashier → Cashier, lab → Lab worklist, triage
  nurse → Triage, ward nurse → Ward board, admins → Dashboard, platform admin
  without a tenant → Platform tenants.
- In-app notifications (`ClinicalAlert`, database channel, tagged with the
  tenant) go through `DepartmentNotifier`: new lab/imaging requests go to the
  department, results go to the ordering doctor (critical results are
  flagged), admission requests go to bed managers, and prescriptions go to
  pharmacy. The sidebar shows the unread count; the list is at `notifications`.
- `IdentifyTenant` now also resolves the **facility**: a session choice
  re-validated against `StaffAssignment`, otherwise the primary or main
  facility. The dashboard has a facility switcher. **Middleware priority fix:**
  `IdentifyTenant` runs before `SubstituteBindings`, so route-model binding is
  tenant-scoped and a foreign id returns 404.
- `EnsureTenantIsActive` (`tenant.active`) locks suspended tenants out.
  `EnsurePlatformAdmin` (`platform`) guards the platform pages.
- `config/hms.php` holds: currency, independent lab verification, pharmacy
  expiry margin, settle-before-discharge, and payment methods.
- `php artisan hms:sync-roles [--catalogs]` is the upgrade command. It adds new
  permissions and default grants to existing tenants (it never revokes) and
  can optionally seed starter catalogs. Use it instead of `migrate:fresh` on a
  database with real data.

### Modules (route → page under `resources/views/pages/`)
- Patients: the profile has tabs for visits, appointments, insurance cover
  and billing. Edit page is `patients.edit`. **Duplicate detection** runs on
  register and edit (national ID, name + DOB, phone + surname). It only warns
  and never merges automatically.
- Appointments (`appointments.index`): day view, booking that prevents
  double-booking a doctor, and confirm / no-show / cancel / check-in.
- Reception (`reception.index`): live queue with counts per stage, walk-in
  check-in (payer type checked against insurance cover), check-in of booked
  appointments, and cancel visit. Charges `REG-FEE`.
- Triage (`triage.index`): vitals, BMI, and an early-warning **suggested**
  triage colour that staff can override. Sends the patient to the doctor queue.
- Emergency (`emergency.index`): rapid registration including unidentified
  patients, and an ED board sorted by triage colour. Charges `ER-FEE`.
- Doctor worklist + consultation workspace (`consultation.index/show`):
  - SOAP notes go from draft to signed. Signed notes are immutable; an
    **amendment** creates a linked, signed new version.
  - Diagnoses (ICD code) and allergies.
  - Lab and imaging orders.
  - Prescribing with allergy warnings, stock on hand and automatic quantity.
  - Admission request, theatre booking link, and visit timeline.
  - Complete visit requires a diagnosis and no unsigned drafts.
  - Charges `CONSULT` once.
- Laboratory (`lab.worklist`, `lab.catalog`): collect (specimen number) →
  result (automatic low/high/critical flag) → verify (by a different person;
  configurable) → release (notifies the doctor). Cancelling a test removes
  its charge.
- Radiology (`radiology.worklist`, `radiology.catalog`): schedule → perform →
  report → verify/release.
- Pharmacy (`pharmacy.queue`, `pharmacy.medicines`):
  - Dispensing supports partial fills and draws stock first-expiry-first-out
    with row locks. Every movement goes into a stock ledger, and only what
    was dispensed is billed.
  - Stock is received by batch and expiry; adjustments and write-offs are
    supported; filters show low stock and expiring items.
- Wards (`wards.board`, `wards.setup`, `wards.admissions`, `wards.admission`):
  - Bed board and ward/bed setup.
  - Admission requests → bed allocation. The bed row is locked, so no
    double booking.
  - Inpatient chart: nursing notes, observations, medication administration
    record, and transfers with bed history.
  - Discharge summary bills the bed-days and sends the bed to cleaning; staff
    then mark it ready.
- Theatre (`theatre.index/show`): booking with room clash detection. The WHO
  safety checklist and consent are a **gate** before the case can start.
  Operative notes are recorded, and the fee is billed on completion.
- Billing (`billing.index`, `billing.invoice`, `billing.services`,
  `billing.receipt`):
  - One invoice per visit; charges after settlement go on a supplementary
    invoice.
  - Charges are priced only from the catalog, and each source item is billed
    at most once.
  - Payments lock the invoice row and reject overpayment or a missing
    reference.
  - Discounts, voids and charge removal are permission-gated and audited.
  - Printable receipt. Settling the bill automatically completes a visit that
    is waiting at billing.
- Insurance (`insurance.index`): providers (SHA/private/corporate) and claims.
  A claim goes draft → submit → approved/partial/rejected/paid; "paid" posts
  an insurance payment. Submission goes through `App\Contracts\ClaimGateway`.
  The default is `ManualClaimGateway`; swap in an SHA/insurer API client when
  credentials exist.
- Reports (`reports.index`): visits, admissions, deaths, occupancy, lab
  turnaround time, billed vs collected, revenue by category, collections by
  method, top diagnoses, a daily visits chart, and CSV export (audited).
- Platform (`platform.tenants`): provisions a tenant with its first admin and
  starter catalogs, and suspends/activates tenants. Platform admins have no
  tenant membership, so they have no clinical access.

### Data / seeding
- 5 migrations `2026_11_04_10000{1..5}_*` (about 35 tables). Every
  tenant-owned model uses `BelongsToTenant`.
- `TenantCatalogSeeder::seedFor($tenant)` seeds:
  - tariffs, including the auto-charged codes `REG-FEE`, `ER-FEE`,
    `CONSULT` and `BED-DAY`
  - 22 lab tests and 10 imaging exams
  - 18 medicines with opening stock
  - 6 wards with 38 beds, 2 theatres, and 4 payers
- The demo seeder is now idempotent. It adds `labmanager@`, `radiology@`,
  `wardnurse@`, `noic@`, `surgeon@`, `theatre@`, `admissions@`, `accounts@`,
  `insurance@` and `director@` (all `@demo.hms.test` / `password`), plus 5
  demo patients.

### Verification (2026-11-04)
- `php artisan migrate` (additive) applied all 5 migrations on MySQL;
  `hms:sync-roles` and `db:seed` ran cleanly.
- `php artisan test`: **54 passed, 0 failed**. The new
  `tests/Feature/Workflow/HospitalWorkflowTest.php` drives the real Livewire
  pages through:
  - The full outpatient journey: reception → triage → consultation → lab
    (critical result, independent verification) → radiology → pharmacy →
    split M-Pesa/cash payment → visit completes automatically → receipt.
  - Inpatient flow: admit, refusal of a double-booked bed, transfer,
    discharge, bed release.
  - Appointments, an unidentified ER patient, theatre clash and checklist
    gate, stock receipt, insurance claim through to payment, and platform
    tenant provisioning.
  - Every page renders, role restrictions hold, other tenants' records
    return 404, and suspension locks users out.
- Live check on `php artisan serve` + MySQL: the receptionist's login lands on
  Reception and check-in works. All 31 tenant pages return 200 for the admin,
  the platform page returns 403 for a tenant admin, and there are no console
  errors. `npm run build` is done.

## Phase 13 increment: compliance completion and operations (2026-11-05)

Everything from the previous "remaining work" list is now built. It is covered by
111 passing tests (810 assertions). Migrations `2026_11_05_100001`–`100008` are
applied on MySQL.

- **ICD-11**: there is a global `icd_codes` table (54 starter codes; load the full WHO
  linearization with `php artisan hms:import-icd11`, which downloads the
  WHO release; pass a file path to import offline). Consultation
  diagnosis search rejects free-text and non-ICD-11 codes. The lab and imaging
  catalogs carry `sha_intervention_code`.
- **SHA**:
  - Biometric (fingerprint) consent on `sha/visit`.
  - Pre-authorisations with insurer callbacks (`POST hooks/sha/{tenant}`, HMAC
    `X-HMS-Signature`).
  - Claim document uploads sent to `/claims/attachments`.
  - Remittance CSV import with automatic claim matching.
- **FHIR R4 / Shared Health Record**: `FhirMapper` builds a transaction Bundle.
  `PushEncounterToShr` is queued when a visit completes.
  `GET fhir/encounters/{encounter}` exports a single encounter.
- **MOH reports**: 705A/B, 717, 405, 333 and 406 at `reports/moh`, with CSV and
  DHIS2 `dataValueSets` export. The KHIS org-unit is set per facility.
- **Printed documents**: discharge summary, lab and radiology reports, medical
  certificate, birth notification, death notification (`print.*` routes).
- **Child welfare / HEI**:
  - KEPI schedule, immunisations and growth monitoring.
  - HEI enrolment and PCR/antibody tests.
  - Births enrol automatically.
- **Level 5–6 departments**: mortuary, blood bank (units, cross-match, issue),
  dialysis, and specialist clinics (oncology, physio, dental, eye, ENT,
  psychiatry).
- **Operations**:
  - Patient merge (audited; the source is hidden by the global scope).
  - Role/permission editor.
  - Payment refunds with a refund voucher.
  - General stores with suppliers, purchase orders, GRN and issues.
- **Live updates**: model events bump per-tenant cache "channels". `/live`
  returns their versions. `resources/js/app.js` polls every 3 s (paused in
  hidden tabs) and `$refresh`es components marked `data-live`. Reverb could not
  be installed (its psr7 dependency conflicts with the installed stack), so
  this replaces `wire:poll`. Swap it for Reverb later without touching the
  pages: only `Live::touch` and the poller change.
- **SMS and M-Pesa** (Admin → Integrations → "SMS & M-Pesa"):
  - SMS uses Africa's Talking, with daily appointment reminders from
    `hms:send-reminders` (08:00).
  - M-Pesa uses Daraja STK push from the invoice page. The callback
    `POST hooks/mpesa/{tenant}/{token}` is idempotent.
  - Both run in simulator mode until keys are entered. Credentials are stored
    encrypted.
- **Security**:
  - `national_id`, `cr_number` and `sha_number` are encrypted at rest.
    Searches use the `national_id_hash` HMAC blind index.
  - Per-tenant IP allow-list and idle timeout (Institution → Security), which
    refuses to lock out the saving admin.
  - Platform-admin break-glass access is time-boxed, needs a written reason,
    is audited, notifies the hospital admins, and is read-only.

## Remaining work

Only external credentials remain, and every integration runs on its simulator
until they are entered:

1. **DHA / SHA HIE API keys**: the client id/secret and facility registry
   code issued after DHA HMIS certification. Enter them under Admin →
   Integrations → SHA / HIE.
2. Non-DHA keys the hospital supplies itself:
   - Safaricom Daraja: consumer key/secret, shortcode, passkey.
   - Africa's Talking: username, API key, sender ID.
   - KHIS/DHIS2: data element UIDs in `config/moh.php` and the facility
     org-unit UID.
3. Push and PR: the branch is committed locally. Add a GitHub remote to push
   it and open the PR.
4. Redis is not confirmed locally; queue, cache and session use the
   `database` driver. `PushEncounterToShr` needs a queue worker
   (`php artisan queue:work`) in production.

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
