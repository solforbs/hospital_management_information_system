# MASTER PROMPT: BUILD A COMPLETE HOSPITAL MANAGEMENT SYSTEM

## 1. YOUR ROLE

You are a senior healthcare software architect, senior Laravel engineer, hospital operations specialist, database architect, security engineer, and UI/UX designer.

Your task is to develop a complete, secure, multi-tenant Hospital Management System (HMS) suitable for actual hospital operations in Kenya.

Build a fully functional application, not a prototype, demonstration, or collection of CRUD pages.

The system must manage the entire patient journey, including patient registration, appointments, reception, triage, consultations, laboratory, radiology, pharmacy, wards, nursing, admissions, theatre, emergency, maternity, billing, insurance, discharge, reporting, and hospital administration.

The system must support clinics, medical centres, and hospitals operating one or multiple facilities.

Study the operational concepts used by established Hospital Information Systems, Electronic Medical Records, Laboratory Information Systems, Pharmacy Management Systems, and Hospital Revenue Cycle Management systems.

Implement an integrated application in which all departments share a properly secured patient record and coordinated workflow.

### Autonomous development instructions

You must execute this project independently and continuously.

* Inspect the existing repository before making changes.
* Preserve existing functional code and configuration.
* Do not stop after writing a plan or architecture.
* Do not ask for approval after every phase.
* Do not repeatedly ask me questions about ordinary technical decisions.
* Make sensible, configurable decisions independently.
* Implement the actual backend, frontend, database, permissions, validation, and tests.
* Do not create fake buttons, dummy functionality, or placeholder dashboards and claim completion.
* Do not replace real business logic with hardcoded sample data.
* Do not remove existing functionality without a compelling reason.
* Do not downgrade Laravel or remove installed packages to work around an incompatibility.
* Diagnose errors, fix them, and continue.
* When external credentials are unavailable, implement the integration interfaces, configuration, sandbox architecture, and tests, then proceed with the rest of the system.

Ask me only when a genuinely critical decision cannot be made safely without my input, such as an irreversible production data operation or a missing credential that prevents a specific external operation.

If context limits or interruptions occur, save your progress and resume from the last incomplete task.

Your goal is to produce a working application with maintainable code and comprehensive operational workflows.

---

# 2. MANDATORY TECHNOLOGY STACK

Use the following technologies.

## Backend

* Laravel 13.
* PHP 8.3 or a version compatible with Laravel 13.
* MySQL 8 or compatible MySQL/MariaDB.
* Laravel Eloquent ORM.
* Laravel queues and scheduled jobs.
* Laravel Events, Listeners, Notifications, Policies, and Gates.
* Laravel Form Requests and server-side validation.

## Frontend

**Livewire 4 is mandatory and will be used as the primary frontend framework.**

The application must provide an SPA-style experience using Livewire 4 and its supported navigation features.

Use:

* Livewire 4 for all interactive application pages and modules.
* Livewire 4 single-file components where appropriate.
* Blade layouts and reusable Blade components.
* Livewire `wire:navigate` for SPA-like navigation.
* Tailwind CSS for styling.
* Alpine.js for lightweight browser interactions.
* Lucide or Font Awesome icons.

Do not introduce React, Vue, Next.js, Inertia, or a separate frontend application.

Do not create a separate Node.js web application.

Vite may be used for compiling frontend assets, but the application must remain a Laravel and Livewire application.

## Authentication and authorization

* Laravel authentication.
* Spatie Laravel Permission, compatible with Laravel 13.
* Tenant-aware roles and permissions.
* Laravel Policies and Gates.
* Optional two-factor authentication for privileged accounts.

## Real-time

* Laravel Reverb.
* Laravel Echo.
* Private and presence channels where appropriate.
* Persistent Laravel database notifications.
* Redis for queues, caching, and rate limiting where available.

## Testing

* Pest or PHPUnit, following the existing project.
* Laravel factories and seeders.
* Feature tests, unit tests, authorization tests, and workflow tests.

Inspect the repository and existing package versions before installing or changing dependencies.

---

# 3. LIVEWIRE SPA ARCHITECTURE

This is a mandatory architectural requirement.

Build the hospital application as a modern SPA-style system using Livewire 4.

The user should be able to move between hospital modules without ordinary full-page browser reloads.

## Navigation

Use `wire:navigate` for internal navigation wherever appropriate.

Provide persistent navigation components:

* Sidebar.
* Top navigation bar.
* Hospital and facility selector.
* Module selector.
* Patient search.
* Notification center.
* User profile and settings.
* Breadcrumbs.

Use Laravel routes and Livewire components as the page controllers.

Every page must support direct URL access with appropriate authentication and authorization.

Maintain browser history, route-based navigation, page titles, and navigation state.

Use the supported Livewire 4 component conventions. Verify APIs and syntax against the installed Livewire version instead of assuming older Livewire examples apply.

## Livewire implementation rules

Use Livewire for:

* Patient registration and search.
* Appointment booking.
* Reception queues.
* Triage forms.
* Consultation workspaces.
* Laboratory worklists.
* Laboratory result entry.
* Pharmacy worklists.
* Ward dashboards.
* Admission and discharge.
* Theatre scheduling.
* Billing and cashier interfaces.
* Reports and settings.
* Modals, tables, filters, and interactive forms.

Use server-side validation and authorization for all mutations.

Never trust browser-supplied patient IDs, tenant IDs, prices, permissions, clinical statuses, or billing totals.

Prevent duplicate submissions and race conditions.

Use loading indicators, validation feedback, confirmation dialogs, pagination, and efficient queries.

Manage browser and Echo event listeners correctly during Livewire navigation. Avoid duplicate event listeners, duplicate notifications, and stale patient information.

Use Alpine.js only for lightweight browser-side interactions, such as dropdowns, sidebar toggles, and notification sound controls.

Do not put critical clinical or financial business logic exclusively in JavaScript or Livewire component methods.

Keep complex business operations in reusable Laravel services or actions.

## Role-aware landing pages

After login, redirect users to an authorized workspace.

Examples:

* Hospital administrators: hospital operations dashboard.
* Doctors: consultation and clinical workspace.
* Receptionists: reception and patient-flow dashboard.
* Laboratory staff: laboratory worklist.
* Nurses: assigned ward and nursing dashboard.
* Pharmacists: pharmacy operations.
* Cashiers: billing and cashier dashboard.
* Theatre staff: theatre worklist.

Users with multiple authorized modules must have a module selector.

Display only modules the authenticated user is permitted to access.

---

# 4. MULTI-TENANCY

Implement true multi-tenancy from the beginning.

A tenant represents a hospital, clinic, or healthcare organization.

Each tenant may have multiple facilities, departments, wards, rooms, beds, pharmacies, laboratories, and staff.

Use a shared database architecture with tenant_id on tenant-owned tables unless the existing application requires another approach.

Implement:

1. Tenant registration and provisioning.
2. Tenant domains or subdomains where appropriate.
3. Tenant profiles and branding.
4. Multiple facilities and branches.
5. Tenant-specific staff and users.
6. Facility-specific departments and resources.
7. Tenant-specific patient and encounter numbering.
8. Tenant-specific price lists and billing configuration.
9. Tenant subscription plans and status.
10. Tenant activation and suspension.
11. Tenant-specific reports.
12. Tenant-specific documents and storage.

Create a centralized tenant resolution and context service.

Resolve the active tenant using authenticated membership and verified domain or subdomain configuration.

Never trust a tenant ID received from the browser without authorization.

Every query, mutation, export, download, notification, background job, and broadcast must enforce tenant isolation.

Ensure:

* A tenant cannot access another tenant's patients.
* A tenant cannot view another tenant's clinical records.
* A tenant cannot download another tenant's documents.
* Tenant data is isolated in cache and queues.
* Reverb channels are tenant-aware.
* Reports are scoped to authorized facilities.
* Tenant-specific numbering is concurrency-safe.

Implement automated cross-tenant security tests.

Platform administrators may manage tenants but must not automatically receive unrestricted access to sensitive clinical records.

Implement an audited and explicitly authorized process for exceptional support access.

---

# 5. HOSPITAL WORKFLOW ENGINE

Build a centralized patient-flow and workflow management system.

Do not represent the entire patient journey using one hardcoded status field.

Create workflow stages, workflow instances, transitions, assignments, timestamps, and history.

A workflow must support multiple branches, departments, requests, returns, cancellations, transfers, and escalations.

Each workflow transition must record the actor, department, time, previous state, new state, and relevant reason.

Allow hospital administrators to configure operational workflows while preserving mandatory clinical and security constraints.

## Outpatient journey

Appointment or walk-in

→ Reception

→ Patient registration or lookup

→ Check-in

→ Triage and vital signs

→ Doctor consultation

→ Clinical assessment and diagnosis

→ Laboratory, radiology, pharmacy, procedure, or referral orders as needed

→ Department processing

→ Results and status returned to clinician

→ Doctor review

→ Treatment and prescriptions

→ Billing and payment processing

→ Follow-up or outpatient discharge.

The patient must be able to move between consultation, laboratory, radiology, pharmacy, and other departments without restarting the encounter.

Support repeat consultations and multiple orders in one encounter.

## Inpatient journey

Admission request

→ Admission authorization

→ Bed allocation

→ Inpatient admission

→ Ward and nursing care

→ Doctor rounds

→ Laboratory and radiology

→ Medication orders and administration

→ Procedures or theatre

→ Billing and payer processing

→ Discharge planning

→ Clinical discharge authorization

→ Discharge summary and prescriptions

→ Final billing and settlement or approved credit arrangement

→ Bed cleaning and release

→ Follow-up appointment.

Support direct admission, emergency admission, planned admission, transfers, and referrals.

A patient can have multiple encounters and admissions over time.

Maintain complete patient history across encounters.

---

# 6. PATIENT REGISTRATION AND MASTER PATIENT INDEX

Build a complete patient registry.

Capture:

* Unique patient number/MRN.
* Full legal name.
* Date of birth or estimated age.
* Sex and relevant clinical demographics.
* National ID or passport where appropriate.
* Phone and alternative contact.
* Email where available.
* Address and county.
* Next of kin.
* Emergency contact.
* Relationship to next of kin.
* Preferred language.
* Patient category.
* Photograph where authorized.
* Registration date.

Support unidentified emergency patients, newborns, minors, and patients without identification documents.

For minors, maintain parent and guardian relationships and appropriate consent records.

Implement duplicate detection using configurable demographic identifiers.

Do not automatically merge records based on similar names or phone numbers.

Implement authorized patient merging with an audit trail and preservation of historical clinical and financial relationships.

Support patient search by MRN, name, phone, identification number, and encounter number.

Generate tenant-specific identifiers using database-backed, concurrency-safe numbering sequences.

---

# 7. APPOINTMENTS AND BOOKING

Implement:

* Doctor calendars.
* Specialist calendars.
* Department and facility booking.
* Appointment slots.
* Provider schedules and working hours.
* Leave and unavailable periods.
* Appointment duration and type.
* Walk-in appointments.
* Referral appointments.
* Rescheduling and cancellation.
* Waiting lists.
* No-show tracking.
* Follow-up bookings.
* Appointment reminders.
* Booking conflict detection.
* Patient check-in.
* Future patient self-booking architecture.

Use Livewire calendar interfaces and real-time appointment updates.

Allow authorized users to configure schedules, consultation durations, and booking policies.

---

# 8. RECEPTION AND PATIENT FLOW

Create a dedicated reception dashboard.

Display:

* Today's appointments.
* Walk-in patients.
* Patients awaiting registration.
* Checked-in patients.
* Patients awaiting triage.
* Patients waiting for consultation.
* Patients returning from laboratory or radiology.
* Patients awaiting admission.
* Patients awaiting authorized administrative steps.
* Completed visits.

Reception must support registration, patient lookup, check-in, queue numbers, patient cards, appointment slips, and authorized demographic corrections.

Implement a live patient queue.

Allow authorized staff to direct patients to departments, place them on hold, record transfers, and track their operational status.

Reception must receive real-time requests from doctors and other departments.

Do not expose sensitive clinical notes or diagnoses to reception users unless specifically authorized.

---

# 9. TRIAGE AND VITAL SIGNS

Implement configurable triage and patient observation workflows.

Capture:

* Blood pressure.
* Pulse.
* Respiratory rate.
* Temperature.
* Oxygen saturation.
* Weight.
* Height.
* BMI.
* Pain score.
* Blood glucose where applicable.
* Consciousness assessment.
* Presenting complaint.
* Relevant allergies.
* Triage category.
* Urgency and clinical notes.

Support pediatric and adult observations.

Maintain historical readings and timestamps.

Use configurable clinical alerts and reference ranges.

Do not substitute generic thresholds for age-specific clinical protocols or professional judgment.

Provide escalation workflows for critical observations.

Allow emergency patients to bypass routine queues through authorized emergency workflows.

---

# 10. DOCTOR CONSULTATION AND EMR

Create a comprehensive Electronic Medical Record.

## Doctor dashboard

Display:

* Appointments.
* Waiting patients.
* Assigned patients.
* Patients returning from laboratory.
* Pending laboratory results.
* Pending radiology reports.
* Outstanding prescriptions.
* Follow-up patients.
* Inpatient assignments.
* Ward rounds.
* Critical alerts.
* Unread requests.

## Consultation workspace

Display a unified patient chart containing:

* Demographics.
* Previous visits.
* Medical history.
* Allergies.
* Current medications.
* Previous diagnoses.
* Laboratory results.
* Radiology reports.
* Previous prescriptions.
* Admission and discharge history.
* Procedures.
* Vital signs.
* Relevant documents.

Implement configurable clinical templates and SOAP-style notes.

Capture:

* Chief complaint.
* History of presenting illness.
* Past medical and surgical history.
* Family and social history.
* Review of systems.
* Physical examination.
* Assessment.
* Differential diagnosis.
* Clinical diagnosis.
* Treatment plan.
* Medication orders.
* Laboratory requests.
* Imaging requests.
* Procedures.
* Referrals.
* Follow-up instructions.

Support ICD-10 and an extensible clinical terminology system.

Support multiple diagnoses per encounter.

Implement clinical notes with draft, signed, amended, and addendum states.

Signed notes must not be silently overwritten.

Corrections must preserve the original record, author, timestamps, and amendment reason.

Implement authenticated clinical attestation and professional authorization.

Provide printable consultation notes, referral letters, medical certificates, and patient instructions.

---

# 11. CLINICAL ORDERS AND INTERDEPARTMENTAL REQUESTS

Create a centralized order management system.

Orders may originate from consultation, emergency, inpatient rounds, theatre, and authorized clinical workflows.

Support:

* Laboratory.
* Radiology.
* Pharmacy.
* Procedures.
* Nursing tasks.
* Specialist consultations.
* Referrals.
* Other configurable services.

Every order must contain:

* Unique order number.
* Patient and encounter.
* Ordering clinician.
* Originating department.
* Destination department.
* Facility.
* Priority.
* Clinical indication.
* Instructions.
* Timestamp.
* Status.
* Assignment and processing history.

Use order-type-specific status transitions.

Support submission, acknowledgement, acceptance, processing, completion, rejection, cancellation, and correction where appropriate.

Record rejection and cancellation reasons.

Prevent duplicate processing and duplicate billing.

All important order transitions must be persisted and audited.

---

# 12. LARAVEL REVERB REAL-TIME COMMUNICATION

Laravel Reverb and Laravel Echo are mandatory.

Implement persistent, real-time communication between:

* Reception.
* Doctors.
* Nurses.
* Laboratory.
* Radiology.
* Pharmacy.
* Billing.
* Theatre.
* Wards.
* Admissions.
* Hospital administration.

Use authenticated private channels and presence channels where appropriate.

## Real-time request examples

### Doctor to reception

Doctor requests a patient to return to consultation.

Reception receives an immediate popup with permitted patient identification, queue number, destination, and priority.

A configurable sound plays.

Reception acknowledges the request.

The doctor receives the acknowledgement and patient-flow update.

### Doctor to laboratory

Doctor submits a laboratory order.

The laboratory worklist updates immediately.

A popup appears with order details, permitted patient identifiers, priority, and collection instructions.

Laboratory staff accept or reject the request.

The doctor receives status updates.

### Laboratory to doctor

Laboratory staff release a verified result.

The ordering clinician receives an immediate notification.

Critical results trigger configured escalation.

The clinician acknowledges and reviews the result.

The system records delivery, acknowledgement, review, and communication timestamps separately.

### Pharmacy

Pharmacy receives new prescriptions.

Dispensing status, partial fulfillment, stock shortages, and completion are communicated to the prescriber.

### Wards

Nurses receive medication orders, nursing tasks, admission information, and authorized clinical instructions.

Doctors receive escalations concerning critical observations and nursing requests.

## Notification infrastructure

Implement:

* Persistent database notifications.
* Reverb broadcasting.
* Livewire-compatible popup dialogs.
* Configurable notification sounds.
* Unread counts.
* Acknowledge, accept, reject, and mark-as-read actions.
* Priority levels.
* Department worklists.
* User-specific notifications.
* Escalation policies.
* Notification history.
* Reconnection and missed-event recovery.

WebSocket delivery must never be treated as the source of truth.

Persist every important request and recover outstanding requests after disconnection.

Prevent duplicate events and duplicate notifications.

Use private channels scoped to tenant, facility, department, and authorized users.

Do not broadcast complete medical records or sensitive clinical information to public channels.

Do not put sensitive clinical details in browser notification previews.

Implement configurable notification sounds and a user-controlled mute option for noncritical notifications.

Critical clinical escalation must not be silently discarded.

---

# 13. LABORATORY INFORMATION SYSTEM

Implement a complete Laboratory Information System.

Features:

* Laboratory departments and sections.
* Test catalog and panels.
* Specimen types.
* Collection instructions.
* Sample collection.
* Barcode generation and label printing.
* Sample accessioning.
* Specimen rejection and recollection.
* Specimen tracking.
* Laboratory worklists.
* Test assignment.
* Result entry.
* Qualitative and quantitative results.
* Units and reference ranges.
* Age/sex-specific reference ranges where configured.
* Technical verification.
* Authorized result release.
* Critical-result alerts.
* Corrected results and amendments.
* Laboratory report printing.
* Turnaround-time reports.
* Reagent and consumable inventory.
* Equipment maintenance and quality-control records.

Support hematology, chemistry, microbiology, serology, urinalysis, and configurable sections.

Allow multiple specimens and tests per order.

Design interfaces for future laboratory analyzer integration.

Do not permit unauthorized personnel to release results requiring professional verification.

Record critical result communication and acknowledgement.

---

# 14. RADIOLOGY AND IMAGING

Implement:

* Imaging test catalog.
* X-ray, ultrasound, CT, MRI, and configurable modalities.
* Imaging orders.
* Clinical indications.
* Radiology worklists.
* Scheduling.
* Patient preparation.
* Safety and contrast screening.
* Imaging status.
* Radiologist assignments.
* Image attachments.
* Reporting templates.
* Report verification and authorization.
* Critical findings alerts.
* Report amendments.
* Referring clinician notifications.
* Turnaround-time reports.

Design integration interfaces for PACS and DICOM-compatible systems.

Do not claim to have built a complete DICOM viewer or archive unless it is actually implemented or integrated.

Store images and reports privately with appropriate authorization.

---

# 15. PHARMACY AND MEDICATION MANAGEMENT

Build outpatient and inpatient pharmacy functionality.

## Medicine catalog

Support generic and brand names, strength, dosage form, route, unit, manufacturer, supplier, barcode, batch, expiry, purchase cost, selling price, and controlled medicine classification where applicable.

Allow hospital-specific formularies.

## Prescriptions

Capture medicine, dose, route, frequency, duration, quantity, start/end dates, instructions, diagnosis, and prescribing clinician.

Implement allergy warnings, duplicate medication checks, and configurable medication safety rules.

Warnings must not replace clinical judgment.

## Dispensing

Support:

* Prescription verification.
* Batch selection.
* FEFO stock handling.
* Full and partial dispensing.
* Unavailable medicine recording.
* Authorized substitutions.
* Labels and dispensing instructions.
* Returns and reversals.
* Expiry and stock alerts.
* Batch recalls.
* Stock adjustments.
* Damaged and expired stock.

Record dispensing and medication administration separately.

A prescription does not prove a medicine was administered.

Support medicine and consumable procurement, purchase orders, goods received notes, suppliers, stock transfers, and physical counts.

Prevent unauthorized stock adjustments.

Use immutable stock movements and audited corrections.

---

# 16. WARDS, ADMISSIONS AND NURSING

Implement complete inpatient and ward management.

## Ward configuration

Support facilities, buildings, floors, wards, rooms, bed spaces, bed categories, isolation beds, ICU, HDU, maternity, pediatric, general wards, and configurable ward types.

Bed states must include available, occupied, reserved, cleaning, blocked, and maintenance.

Prevent multiple active allocations to the same bed using database transactions and concurrency control.

## Admission

Support:

* Admission requests.
* Direct and emergency admission.
* Planned admission.
* Admission authorization.
* Diagnosis.
* Attending doctor.
* Responsible consultant.
* Admission notes.
* Consent.
* Bed allocation.
* Admission timestamps.

Preserve admission history and bed movements.

## Nursing

Implement:

* Nursing assessments.
* Nursing care plans.
* Nursing notes.
* Vital-sign charts.
* Fluid input/output charts.
* Pain assessments.
* Wound care.
* Nursing tasks.
* Medication administration records.
* Patient monitoring.
* Shift handovers.
* Fall-risk and other configurable assessments.
* Pressure injury assessments.
* Observation charts.

Medication administration must record actual administration, staff, dose, route, and time.

Support refused, withheld, omitted, delayed, and not-administered states with reasons.

Implement configurable observation schedules and overdue-task alerts.

## Ward transfers

Support transfers between beds, wards, facilities, and clinical teams.

Record origin, destination, reason, authorization, requester, timestamps, and receiving staff acknowledgement.

Preserve historical occupancy records.

---

# 17. THEATRE AND SURGERY

Implement a comprehensive theatre management module.

Support:

* Theatre rooms and equipment.
* Theatre availability.
* Elective and emergency surgery bookings.
* Procedure catalog.
* Surgical scheduling.
* Preoperative assessments.
* Consent.
* Anesthesia assessments.
* Preoperative checklists.
* Surgeon and assistant assignments.
* Anesthetists.
* Scrub and circulating nurses.
* Theatre worklists.
* Surgical safety sign-in, time-out, and sign-out.
* Operation notes.
* Anesthesia records.
* Intraoperative observations.
* Surgical counts.
* Specimens.
* Implants and consumables.
* Recovery and PACU.
* Postoperative instructions.
* Transfers to wards or ICU.

Capture procedure and anesthesia start/end times.

Implement theatre utilization, cancellations, delays, and procedure outcome reports.

Connect theatre medicines and consumables to inventory.

Connect theatre services to billing without duplicate charges.

Require appropriate authorization and consent for elective procedures.

Support authorized emergency workflows with appropriate documentation.

---

# 18. EMERGENCY AND CASUALTY

Implement a dedicated emergency department.

Support emergency arrival, ambulance arrivals, walk-ins, unidentified patients, emergency triage, resuscitation, emergency consultations, clinical notes, orders, medication administration, procedures, observation, admission, theatre, referrals, transfers, and discharge.

Provide configurable emergency prioritization.

Allow emergency stabilization workflows to proceed without ordinary appointment or advance-payment prerequisites where clinically required.

Implement subsequent documentation and financial reconciliation.

---

# 19. MATERNITY AND NEWBORN

Implement:

* Antenatal visits.
* Maternal history.
* Pregnancy records.
* Estimated delivery date.
* Antenatal observations.
* Obstetric risk assessments.
* Antenatal laboratory orders.
* Labor admission.
* Labor monitoring and partograph support.
* Delivery records.
* Delivery type and birth outcomes.
* Maternal complications.
* Postnatal care.
* Newborn registration.
* APGAR records.
* Birth measurements.
* Newborn examination.
* Newborn medication and immunization records.
* Maternal and newborn discharge.

Maintain separate mother and newborn patient records with explicit relationships.

Support newborn registration without official identification documents.

---

# 20. OTHER CLINICAL DEPARTMENTS

Provide extensible department workflows for:

* Dental.
* Ophthalmology.
* Physiotherapy.
* Mental health.
* Oncology.
* Dialysis.
* Intensive care.
* Nutrition.
* Specialist clinics.
* Other hospital-specific departments.

Use configurable clinical templates, encounters, orders, procedures, and billing services.

Allow authorized administrators to create departments and assign staff without modifying core application code.

---

# 21. BILLING, CASHIER AND FINANCE

Implement a full hospital revenue cycle.

## Service catalog and tariffs

Support consultations, procedures, medicines, laboratory, radiology, accommodation, theatre, consumables, and other services.

Support facility-specific tariffs, payer tariffs, corporate rates, package pricing, discounts, and configurable taxes.

Do not hardcode prices.

## Billing

Support:

* Estimates.
* Provisional invoices.
* Final invoices.
* Patient deposits.
* Partial payments.
* Payment allocations.
* Overpayments and credits.
* Refunds.
* Credit notes.
* Receipts.
* Reversals.
* Outstanding balances.
* Inpatient running bills.
* Authorized credit arrangements.
* Cashier shifts and reconciliation.

Use immutable financial transactions with audited reversals.

Use decimal types for money.

Generate tenant-specific invoice and receipt numbers.

Prevent duplicate charges when events are retried or an order is updated multiple times.

Implement centralized billing services and idempotency protection.

Support cash, card, bank transfer, mobile money, insurance, corporate credit, and configurable payment methods.

Do not mark payments as received without an authorized confirmation.

Prepare a Daraja/M-Pesa integration interface for later activation.

Support inpatient deposits and discharge billing.

---

# 22. INSURANCE AND SHA READINESS

Prepare the system for future integration with Kenya's Social Health Authority.

Do not claim live SHA integration, certification, or compliance without verified implementation and authorization.

Build a dedicated payer integration layer.

Implement:

* Payer registry.
* Insurance membership records.
* Eligibility verification interface.
* Coverage and benefits configuration.
* Preauthorization.
* Claim preparation.
* Claim submission adapter.
* Claim acknowledgement.
* Claim status tracking.
* Claim rejection and correction.
* Remittance advice.
* Payment reconciliation.
* Co-payment calculations.
* Insurance receivables.
* Supporting documents.
* Integration logs.

Create an extensible SHA adapter interface.

Keep SHA-specific fields, API clients, credentials, mappings, and protocols separate from core billing.

Use official SHA technical specifications and official documentation for actual integrations.

Where specifications are unavailable, implement the adapter contract and configurable fields without inventing endpoints or payload formats.

Support sandbox environments where officially available.

Design the system so changes in payer requirements do not require rewriting the core hospital system.

---

# 23. PROCUREMENT AND INVENTORY

Implement centralized hospital procurement and stock management.

Support:

* Stores and stock locations.
* Medical supplies.
* Medicines.
* Laboratory consumables.
* Theatre consumables.
* Equipment.
* Suppliers.
* Purchase requisitions.
* Purchase orders.
* Supplier quotations.
* Goods received notes.
* Supplier invoices.
* Stock transfers.
* Stock issues.
* Returns.
* Adjustments.
* Stock counts.
* Batch and expiry tracking.
* Reorder alerts.
* Inventory valuation.
* Procurement approvals.
* Supplier performance records.

Track inventory from procurement through storage, dispensing, administration, consumption, and disposal.

Use immutable stock movement records.

Implement appropriate segregation of duties and approval thresholds.

---

# 24. STAFF, USERS AND PERMISSIONS

Use Spatie Laravel Permission as the mandatory authorization foundation.

Implement tenant-aware and facility-aware roles and permissions.

A user may have different roles in different facilities and departments.

Seed configurable initial roles, including:

* Platform Super Administrator.
* Hospital Owner.
* Hospital Administrator.
* Facility Administrator.
* Medical Director.
* Doctor.
* Specialist.
* Clinical Officer.
* Nurse.
* Nursing Officer in Charge.
* Receptionist.
* Triage Nurse.
* Laboratory Technician.
* Laboratory Manager.
* Radiologist.
* Radiology Technician.
* Pharmacist.
* Pharmacy Technician.
* Theatre Nurse.
* Surgeon.
* Anesthetist.
* Ward Clerk.
* Admission Officer.
* Cashier.
* Accountant.
* Insurance Officer.
* Procurement Officer.
* Storekeeper.
* HR Officer.
* Auditor.
* Records Officer.

Allow custom roles.

Implement granular permissions for patients, encounters, clinical notes, orders, laboratory results, pharmacy, wards, theatre, billing, refunds, reports, exports, and administration.

Examples:

patients.view, patients.create, patients.update, patients.merge, patients.export

encounters.view, encounters.create, encounters.update, encounters.sign

clinical_notes.view, clinical_notes.create, clinical_notes.sign, clinical_notes.amend

lab.orders.view, lab.orders.process, lab.results.enter, lab.results.verify, lab.results.release

pharmacy.prescriptions.view, pharmacy.dispense, pharmacy.stock.manage

wards.admissions.create, wards.beds.allocate, wards.transfers.approve

theatre.schedule, theatre.procedures.manage, theatre.records.sign

billing.invoices.view, billing.invoices.create, billing.payments.receive, billing.refunds.approve

reports.view, reports.export

users.manage, roles.manage, permissions.manage

Enforce least privilege.

Separate view, edit, sign, release, approve, and export permissions.

Implement audited emergency break-glass access with a mandatory reason and appropriate alerts.

---

# 25. MEDICAL RECORDS AND DOCUMENT MANAGEMENT

Support patient documents, scanned records, referral letters, consent forms, discharge summaries, prescriptions, laboratory reports, imaging reports, operation notes, insurance documents, and claim attachments.

Use private storage by default.

Use authorized authenticated downloads or time-limited signed URLs.

Do not expose sensitive documents through public URLs.

Provide professionally formatted printable documents:

* Patient cards.
* Appointment slips.
* Queue tickets.
* Prescriptions.
* Laboratory requests and results.
* Imaging requests and reports.
* Invoices and receipts.
* Admission forms.
* Nursing charts.
* Theatre records.
* Discharge summaries.
* Referral letters.
* Insurance claims.

Support tenant-specific branding, document numbering, and versioning.

Audit access to sensitive documents.

---

# 26. REPORTING AND ANALYTICS

Implement role-aware reporting.

Clinical reports:

* Outpatient attendance.
* Diagnoses and disease trends.
* Department workload.
* Doctor consultations.
* Referrals.
* Laboratory turnaround times.
* Critical results.
* Imaging turnaround times.
* Admissions and discharges.
* Bed occupancy.
* Nursing activities.
* Theatre utilization.
* Emergency activity.
* Maternity statistics.
* Clinical outcomes.

Financial reports:

* Revenue by department and facility.
* Revenue by payment method.
* Cashier reconciliation.
* Outstanding balances.
* Insurance receivables.
* SHA claim statuses.
* Refunds and credit notes.
* Pharmacy sales.
* Inventory valuation.
* Procurement expenditure.

Operational dashboards must be role-specific.

Support filtering by date, facility, department, provider, payer, and service.

Implement authorized CSV and Excel exports.

Use queued jobs for large exports and notify the requesting user when complete.

Do not expose unnecessary patient identifiers in aggregate reports.

---

# 27. NOTIFICATIONS AND EXTERNAL COMMUNICATION

Build a configurable notification engine supporting:

* In-app notifications.
* Reverb WebSockets.
* Browser popup dialogs.
* Notification sounds.
* SMS adapters.
* Email adapters.
* WhatsApp Business adapters where appropriately configured.
* Future mobile push notifications.

Use tenant-specific templates and configuration.

Do not send unnecessary sensitive medical information through ordinary SMS or external notifications.

Require appropriate consent and data minimization.

Queue external messages and record delivery status.

External provider failure must not prevent core hospital workflows.

---

# 28. SECURITY, PRIVACY AND COMPLIANCE

Design the system with the Kenya Data Protection Act, 2019, Digital Health Act, 2023, applicable regulations, and relevant healthcare requirements in mind.

Verify specific requirements against official sources before implementing regulatory claims.

Do not claim legal compliance or certification merely because security features exist.

Implement:

* Tenant isolation.
* Authentication and authorization.
* Secure sessions.
* CSRF protection.
* Server-side validation.
* SQL injection prevention.
* Output escaping.
* Rate limiting.
* Secure password hashing.
* Optional two-factor authentication.
* Secure file uploads.
* Private storage.
* HTTPS.
* Appropriate encryption at rest.
* Secrets management.
* Audit logging.
* Access logging.
* Clinical record access auditing.
* Financial transaction auditing.
* Backups.
* Recovery procedures.
* Account deactivation.
* Session expiry.
* Retention and archival configuration.

Audit sensitive operations with user, tenant, facility, action, resource, timestamp, and relevant changes.

Do not log passwords, authentication tokens, or unnecessary clinical data.

Audit logs must not be editable by ordinary users.

Implement controlled workflows for patient record access and correction requests.

Never silently delete signed clinical notes or completed financial transactions.

---

# 29. SYSTEM ADMINISTRATION

Build configurable administration interfaces for:

* Hospital profiles.
* Facilities.
* Departments.
* Wards and beds.
* Appointment settings.
* Queue settings.
* Patient numbering.
* Encounter numbering.
* Invoice and receipt numbering.
* Clinical templates.
* Diagnosis catalogs.
* Laboratory catalogs.
* Imaging catalogs.
* Pharmacy catalogs.
* Service catalogs.
* Tariffs.
* Staff assignments.
* Roles and permissions.
* Notification settings.
* Operating hours.
* Admission and discharge policies.
* Billing rules.
* Insurance settings.
* Document templates.
* Branding.
* Integration settings.
* Audit retention.

Use database-backed tenant and facility configuration.

Do not hardcode rules that hospital administrators should be able to configure.

---

# 30. DATABASE AND APPLICATION ARCHITECTURE

Use a normalized relational database with appropriate foreign keys, indexes, unique constraints, and tenant boundaries.

Do not put the entire system into a few oversized tables.

Use structured clinical and financial models.

Use JSON only for genuinely configurable data, variable metadata, and external payload snapshots, with appropriate validation and versioning.

Suggested core entities include:

Tenant, TenantDomain, Subscription, Facility, Department, Ward, Room, Bed, BedOccupancy, User, Staff, StaffAssignment, Patient, PatientIdentifier, PatientContact, PatientGuardian, PatientMerge, Appointment, AppointmentSlot, Encounter, EncounterDiagnosis, ClinicalNote, ClinicalNoteAmendment, VitalSign, Allergy, MedicalHistory, ClinicalOrder, ClinicalOrderItem, ClinicalOrderStatusHistory, Referral, Admission, WardTransfer, NursingAssessment, NursingTask, MedicationOrder, MedicationAdministration, Prescription, Dispensing, DispensingItem, Drug, DrugBatch, StockMovement, Supplier, PurchaseOrder, GoodsReceivedNote, LaboratoryTest, LaboratoryOrder, Specimen, SpecimenCollection, LaboratoryResult, LaboratoryResultVerification, ImagingOrder, ImagingReport, Theatre, TheatreBooking, SurgicalProcedure, AnesthesiaRecord, SurgicalChecklist, Invoice, InvoiceItem, Payment, PaymentAllocation, Receipt, Refund, CreditNote, Payer, InsuranceMembership, Preauthorization, InsuranceClaim, ClaimItem, ClaimSubmission, Notification, NotificationAcknowledgement, AuditLog, Document, ConsentRecord, and IntegrationLog.

Refine the schema according to actual business relationships.

Do not create unnecessary tables simply to match the list.

Use Laravel migrations for all schema changes.

Use database transactions and concurrency protection for:

* Patient encounter creation.
* Bed allocation.
* Admissions and transfers.
* Laboratory result release.
* Pharmacy dispensing.
* Stock deductions.
* Invoice generation.
* Payment allocation.
* Financial reversals.
* Theatre consumable usage.
* Discharge processing.

Use appropriate decimal types for monetary values.

Use correct date/time handling, storing timestamps consistently and displaying facility-local time.

Create reusable services and actions for complex business operations.

Do not put all business logic inside Livewire components or controllers.

Use after-commit event dispatching for important events.

Use queues for appropriate notifications, exports, and integrations.

---

# 31. EXTERNAL INTEGRATIONS AND API

Prepare integration interfaces for:

* SHA.
* Insurance providers.
* M-Pesa Daraja.
* SMS and email gateways.
* PACS and DICOM systems.
* Laboratory analyzers.
* Medical equipment.
* Patient portals.
* Mobile applications.
* Health information exchange systems.

Use versioned Laravel API routes where needed.

Implement authentication, authorization, rate limiting, validation, idempotency, integration logs, retries, and secure credential management.

Use official specifications for real integrations.

Do not invent API endpoints or claim external connectivity without verification.

---

# 32. TESTING

Automated testing is mandatory.

Write meaningful tests throughout development.

Test:

## Authentication and tenancy

* Authentication.
* Tenant resolution.
* Cross-tenant access.
* Facility authorization.
* Role and permission enforcement.
* Cross-tenant document access.
* Cross-tenant WebSocket access.

## Patient and clinical workflows

* Patient registration.
* Duplicate detection.
* Patient merging.
* Appointment booking.
* Reception check-in.
* Triage.
* Consultation.
* Clinical notes.
* Orders and department transitions.
* Laboratory processing.
* Result verification and release.
* Critical result escalation.
* Radiology reporting.
* Prescription and dispensing.
* Medication administration.
* Admissions and bed allocation.
* Ward transfers.
* Theatre scheduling.
* Discharge.

## Financial operations

* Invoice generation.
* Duplicate charge prevention.
* Partial payments.
* Payment allocation.
* Refunds and credit notes.
* Stock deductions.
* Financial reconciliation.

## Reverb

* Channel authorization.
* Persistent notifications.
* Department notifications.
* Acknowledgement.
* Reconnection recovery.
* Duplicate event handling.
* Critical notification escalation.

## Security

* Unauthorized clinical record access.
* Unauthorized clinical note amendments.
* Unauthorized result release.
* Unauthorized refunds.
* Unauthorized exports.
* Cross-tenant access.

Use synthetic test data only.

Run relevant tests after every major module.

Fix failures before marking a phase complete.

Also run appropriate Laravel checks, migration checks, frontend asset builds, and static analysis where available.

---

# 33. IMPLEMENTATION PHASES

These phases are mandatory milestones, not stopping points.

Do not wait for approval between phases.

## Phase 1 — Inspect and establish foundation

Inspect the repository, Laravel version, installed Livewire version, dependencies, database, routes, migrations, authentication, and existing frontend.

Create DEVELOPMENT_STATUS.md.

Implement the tenant foundation, authentication, Spatie Permission, base Livewire SPA layout, tenant context, facility context, and authorization policies.

## Phase 2 — Administration and patient registry

Implement tenant provisioning, hospital profiles, facilities, departments, staff, roles, permissions, patient registration, patient search, patient numbering, and audit logging.

## Phase 3 — Appointments and reception

Implement booking, calendars, check-in, queue management, reception, triage, encounters, and patient-flow tracking.

Build a complete outpatient workflow.

## Phase 4 — Reverb real-time infrastructure

Configure Reverb and Echo.

Implement private channels, persistent notifications, Livewire-compatible popups, sounds, acknowledgement, reconnect recovery, and department queues.

Connect reception, consultation, laboratory, pharmacy, and nursing.

## Phase 5 — Consultation and EMR

Implement clinical history, doctor workspace, notes, diagnoses, orders, prescriptions, referrals, and patient records.

## Phase 6 — Laboratory and radiology

Implement specimens, barcodes, worklists, results, verification, critical alerts, imaging orders, reports, and turnaround metrics.

## Phase 7 — Pharmacy and inventory

Implement medicine catalogs, dispensing, batches, stock management, procurement, suppliers, and medication administration.

## Phase 8 — Inpatient and nursing

Implement wards, admissions, beds, transfers, nursing assessments, observations, medication administration, ward rounds, and discharge.

## Phase 9 — Theatre and emergency

Implement emergency workflows, theatre scheduling, surgery, anesthesia, safety checklists, recovery, and postoperative transfers.

## Phase 10 — Maternity and other departments

Implement antenatal, labor, delivery, newborn care, and configurable clinical departments.

## Phase 11 — Billing and insurance

Implement tariffs, invoices, cashier, payments, refunds, credit notes, inpatient billing, insurance, preauthorization, claims, and reconciliation.

Build the SHA adapter architecture.

## Phase 12 — Reporting and administration

Implement clinical reports, financial reports, dashboards, documents, staff management, print templates, settings, and integrations.

## Phase 13 — Security, testing and deployment

Review security, authorization, tenancy, clinical and financial consistency, performance, validation, and missing workflows.

Run automated tests.

Fix identified defects.

Create deployment documentation for Laravel 13, MySQL, Nginx, PHP-FPM, Redis, Reverb, queues, Supervisor, SSL, scheduled tasks, and backups.

Document first-time installation, tenant onboarding, and environment configuration.

---

# 34. DEVELOPMENT EXECUTION RULES

For every module:

1. Inspect relevant existing files.
2. Implement migrations and models.
3. Implement authorization and policies.
4. Implement business services and actions.
5. Implement Livewire 4 components and routes.
6. Implement responsive interfaces.
7. Connect real-time events where appropriate.
8. Write automated tests.
9. Run tests and checks.
10. Fix errors and proceed.

Use the installed Livewire 4 APIs and conventions.

Do not use outdated Livewire syntax without verifying compatibility.

Do not generate the entire application as one enormous file.

Build coherent, maintainable increments.

Do not overwrite important configuration without inspecting it.

Do not execute destructive production database commands.

Do not hardcode credentials.

Do not disable authentication, tenant isolation, validation, or authorization to make a feature work.

Do not use fake production data.

Do not create nonfunctional buttons.

If an external integration is blocked by missing credentials, document the blocker, implement the integration interface, and continue with unrelated functionality.

Update DEVELOPMENT_STATUS.md after each meaningful milestone with:

* Current phase.
* Completed functionality.
* Files and migrations created.
* Tests passed and failed.
* Outstanding defects.
* Next implementation tasks.
* Architecture decisions.

When resuming, read DEVELOPMENT_STATUS.md and continue from the last incomplete task.

Do not stop at the end of a phase. Immediately continue into the next phase.

---

# 35. DEFINITION OF DONE

The application must demonstrate a complete operational workflow.

A tenant can be provisioned and configured.

A receptionist can register a patient, book an appointment, check the patient in, and send the patient to triage.

A triage nurse can record vital signs and prioritize the patient.

A doctor can open the encounter, review the patient's history, document a consultation, and issue clinical orders.

Laboratory staff receive a real-time notification, acknowledge the request, collect specimens, process tests, verify results, and release them.

The doctor receives the result notification and reviews the result.

Radiology can process imaging requests and return verified reports.

Pharmacy receives prescriptions, dispenses medicines, and updates stock and prescription status.

Reception receives patient-return requests in real time.

The patient can be billed appropriately without duplicate charges.

A patient can be admitted, allocated a bed, transferred between wards, receive nursing care, undergo theatre procedures, and be discharged.

Emergency patients can use emergency workflows.

Maternity and newborn workflows maintain separate patient records.

Clinical notes, laboratory results, prescriptions, admissions, and financial transactions have appropriate authorization and audit trails.

Hospital administrators can generate authorized reports.

A second tenant cannot access another tenant's protected information.

Real-time requests remain recoverable after WebSocket disconnection.

The system has automated tests and documented deployment procedures.

Do not declare completion until the implemented functionality has been tested and outstanding limitations are documented.

---

# 36. START NOW

Begin by inspecting the existing repository.

Determine the exact Laravel and Livewire versions, installed packages, database configuration, routes, authentication setup, and current application structure.

Create DEVELOPMENT_STATUS.md and a concise implementation checklist.

Then immediately begin Phase 1.

Do not stop after producing the architecture or plan.

Do not wait for my approval to proceed to the next phase.

Build the actual application using Laravel 13, Livewire 4 SPA-style navigation, Spatie Permission, MySQL, and Laravel Reverb.

Continue implementing, testing, correcting, and documenting the application until all feasible requirements have been implemented and only genuinely external or critical blockers remain.

Your final objective is a unified, multi-tenant Hospital Management System that coordinates reception, doctors, laboratory, radiology, pharmacy, nursing, wards, theatre, billing, administration, and management through a shared, secure patient record and real-time hospital workflow.
