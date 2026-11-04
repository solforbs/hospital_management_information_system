# Kenya compliance: DHA HMIS certification and SHA integration

Research date: 2026-11-04. Re-check every date and endpoint against official
DHA/SHA sources before relying on it. Several official pages could not be
reached from here (afyalink.dha.go.ke DNS failure; Kenya Law pages returned
empty; certification.dha.go.ke is JS-rendered).

## 1. Current directive (Ministry of Health, CS Aden Duale)

- Facilities must move from the SHA **Provider Portal** to a **DHA-certified
  HMIS** integrated with SHA through the national HIE (AfyaLink / AfyaConnect).
- Deadline history:
  - Original cutoff: 1 Sept 2026, per one vendor source; this is unverified.
  - Then 30 Oct 2026, for public Level 5/6, faith-based and private facilities.
  - Now **30 Nov 2026**.
- On 30 Nov 2026 the **Provider Portal is deactivated**. Facilities that
  haven't migrated risk disruption to verification, pre-authorisation and
  claims.
- Claims already submitted through the portal continue to be processed.
- The CS has said no patient will be denied care because a facility hasn't
  migrated.
- Progress so far:
  - All 6,427 public Level 2–4 facilities have migrated.
  - 371 private facilities had migrated when the extension was announced.
  - About 28–37 HMIS platforms are DHA-certified; see the registry at
    https://certification.dha.go.ke/registry.
- DHA runs onboarding support: Rapid Results Initiatives, integration help
  and vendor coordination.
- DHA has warned about **counterfeit certificates**. Always verify a
  certificate on the official registry.

## 2. Legal basis

- **Digital Health Act, 2023 (No. 15 of 2023)**: creates the DHA, which
  certifies digital health solutions.
- **Digital Health (Health Information Management Procedures) Regulations,
  2025 (LN 76/2025)**: the certification process. The solution provider
  completes a **self-attestation** and then applies on **Form HMIS 4**.
- **Digital Health (Data Exchange Component) Regulations, 2025 (LN 77/2025)**:
  only certified solutions may exchange data through the national exchange
  (ESB/HIE), and they must meet its reporting obligations.
- **Data Protection Act (Cap 411C)**: requires registration with the ODPC as
  data controller and processor, plus a DPIA.

## 3. DHA certification: what the vendor (us) must produce

These six criteria come from a vendor source. Confirm them on
certification.dha.go.ke/process.

1. Self-attestation report and gap analysis (Form HMIS 4) in the DHA
   Certification Dashboard. This needs a DHA portal "Business Account".
2. ODPC registration as data controller and data processor.
3. Data Protection Impact Assessment by a qualified assessor.
4. Security, privacy and confidentiality policy.
5. Backup and recovery policy, with a **tested** RTO/RPO.
6. Interoperability and standards: ICD-11 coding, FHIR-based exchange,
   alignment with national product/catalogue codes, and connection to the HIE
   (ESB).

The process after that:
- Submit the application with the fee (amount unknown).
- DHA reviews it technically and may lab-test the system.
- An outcome is due within 30 days; appeals go to the DHA Complaints
  Committee.
- The certificate is listed on the registry and must be renewed
  periodically.

Certification and SHA integration are **separate steps**. After certification
we still have to integrate technically and test in UAT.

## 4. HIE / SHA technical integration (hie-docs.dha.go.ke)

UAT base URL: `https://ilm-dev.dha.go.ke/uat-middleware/api/v1`. DHA supplies
the production base URL at onboarding; an unofficial SDK uses
`https://ilm-hie.dha.go.ke/middleware`.

### Authentication
- OAuth 2.0 with client credentials issued at DHA onboarding. The token is a
  JWT.
- The token endpoint and grant type are not in the public pages read; get
  them at onboarding.
- **Facility identification**: a facility-scoped token carries
  `facility_id` / `facility_id_type` claims.
- **A multi-tenant HMIS like ours** uses a service-account token and must send
  `X-Facility-Id` (e.g. `FID-47-115307-8`) **and** `X-Facility-Id-Type: fr-code`
  on every request.

### Registries
- **Client Registry**: `GET /patients?identification_number=&identification_type=`.
  - Types include National ID, Alien ID, Refugee ID, Birth Certificate, Birth
    Notification, Mandate Number and ClientRegistry ID. The guides spell
    these differently (e.g. "CR ID", "SHA NUMBER"), so confirm the exact
    strings in UAT.
  - The response returns the **CR number** (e.g. `CR7678914660684-5`),
    demographics, dependants and phone.
- **Facility Registry**: `GET /facilities/search?identifier=&identifier-type=`.
  - `identifier-type` is one of mfl, license-number, fr-code,
    registration-number or fid.
  - The response includes `kephLevel`, `fidCode`/`frCode`, `shaContractStatus`,
    `shaContractedServices` and `SHAOperationStatus` (suspension).
- **Health Worker Registry**:
  `GET /professionals?identification_number=&identification_type=registration_number&regulator=KMPDC|COC|NCK|PPB`.
  - The response includes `licenses[]` (start/end) and `membership.is_active`.

### Eligibility, consent and claims

These come from the unofficial `sha-claim` SDK, which says it was generated
from the DHA eClaims docs. Verify each endpoint in UAT.

1. **Eligibility**: `GET /patients/eligibility?identification_number=&identification_type=`.
   Status `10` means a member was found.
2. **Benefits**: `GET /patients/benefits`, `/patients/sub-benefits` and
   `/patients/benefits/interventions`. Intervention codes look like
   `SHA-12-001`.
3. **Consent / visit**, by one of two routes:
   - OTP: `POST /claims/otp` sends an SMS to the registered phone, then
     `POST /claims/visit` with the `otp` returns a `consent_token`.
   - Biometric: `POST /claims/authorize`, then `POST /claims/visit` with
     `auth_guid`. A pending biometric request blocks the OTP route.
   - `service_type` is one of CAPITATION, OUTPATIENT, INPATIENT or EMERGENCY.
4. **Pre-authorisation** for elective care (`/preauths`): the doctor signs off,
   then the payer approves.
5. **Build the claim**:
   - `POST /claims/interventions`.
   - `POST /claims/diagnoses` with an ICD-11 code (ICD-10 stems are also
     seen).
   - `POST /claims/lines` (multipart). Each line carries unit_price, quantity,
     scheme_code, charge_date, diagnoses, and the practitioner's ID and
     regulator.
   - `POST /claims/attachments` (up to 10 MB each).
   - `POST /claims/doctors`, which is checked against the Health Worker
     Registry.
6. **Preview**: `POST /claims/preview` returns any blockers: NO_BILLING_LINES,
   NO_DIAGNOSIS, PREAUTH_OUTSTANDING, MISSING_DOCUMENTS and so on.
7. **Submit**: `POST /claims/submit` with `invoice_number`, a
   `discharge_reason` (RECOVERED / REFERRED / DECEASED / ABSCONDED / OTHER) and
   a **discharge OTP**. UAT requires the discharge OTP even for outpatient
   claims.
   - Never retry a submit automatically. If the outcome is unknown, re-check
     with `preview`.
8. **Close or cancel**: `POST /claims/close`, giving a reason code.
9. **Status**: `GET /claims/preview/payer?guid=&provider_claim_no=`.
   Remittance and status callbacks are also documented on hie-docs.
10. **Per-diem** benefits (ICU, HDU, NICU; `SHA-03-*`) are paid per day at the
    facility's KEPH-level rate.

## 5. Gap analysis against this system

| Requirement | Current state | Work needed |
|---|---|---|
| Facility HIE identity | `facilities.code` is internal only | Add `fr_code`/`fid_code`, `mfl_code`, `keph_level` and SHA contract status; add a lookup via the Facility Registry |
| Client Registry lookup | `patients.national_id` only | Add `cr_number`, `sha_number`, identification type and other identifiers; add a lookup at registration that prefills demographics |
| Practitioner verification | Staff have no regulator data | Add `regulator` and `registration_number` to staff; verify against the Health Worker Registry and block expired licences on claims |
| Eligibility at check-in | Payer type is checked against local insurance cover only | Call SHA eligibility and benefits; store the snapshot on the encounter |
| Consent (OTP / biometric) | None | Add an OTP capture step at check-in (visit → `consent_token` on the encounter); add discharge OTP at close-out |
| Pre-authorisation | None | Add a preauth workflow on theatre and admission requests |
| SHA intervention codes | Service catalog uses internal codes | Map `service_items` to SHA intervention codes and scheme codes; add a benefit-to-intervention mapping |
| ICD-11 diagnoses | Free-text code field | Add ICD-11 lookup (DHA terminology service or the WHO ICD API) and require a coded diagnosis on SHA claims |
| Claim build and submit | `ClaimGateway` contract plus `ManualClaimGateway` | Implement `ShaClaimGateway` (lines, diagnoses, doctors, attachments, preview, submit, close, status) with idempotency and never-retry submit |
| Claim documents | No uploads | Add document uploads (discharge summary, lab reports, consent) as claim attachments |
| Remittance / reconciliation | Claim marked paid by hand | Add a remittance and status-callback webhook that posts an insurance payment automatically |
| Multi-tenant auth | — | Store per-tenant OAuth credentials encrypted, send `X-Facility-Id` headers per request, cache tokens |
| FHIR exchange | None | Add FHIR R4 mapping for Patient, Encounter, Condition, Observation and MedicationRequest for the Shared Health Record (confirm DHA profiles) |
| Data protection | Audit log exists | DPIA, ODPC registration, encryption of identifiers at rest, retention policy, consent records, access policies |
| Backup and recovery | Not defined | Documented and tested backup/restore with RTO/RPO evidence |
| MoH reporting (KHIS/DHIS2) | Internal reports only | Add MOH 705/717 and similar aggregate exports (needs confirmation) |

## 6. Before we can integrate

- Register as a solution provider on the DHA portal and start the
  self-attestation.
- Request **UAT credentials** (client ID and secret) from DHA. They're issued
  at onboarding.
- Get a pilot facility's FR code, its SHA contract, and its practitioners'
  registration numbers.
- Get DHA's eClaims API reference (`hie-docs.dha.go.ke/eclaims`) and the SHA
  benefit/tariff and combination-rules lists.

## 7. Implementation status (2026-11-04)

Built (migration `2026_11_04_200001_add_sha_hie_integration`):

- **`App\Services\Hie\HieClient`**: the connection to the national exchange.
  - Logs in once per tenant with OAuth2 client credentials (encrypted at
    rest) and caches the token, refreshing it once if it expires.
  - Sends the `X-Facility-Id` / `X-Facility-Id-Type: fr-code` headers.
  - Retries read requests automatically but never retries a write.
  - Logs every request to `hie_request_logs` with OTPs, tokens and secrets
    removed.
- **`HieSimulator`**: a local stand-in for DHA that answers in the same shape
  as the UAT test environment.
  - OTP `123456` is always accepted.
  - ID `00000000` returns "not found".
  - IDs ending in `9` return "not eligible".
- **`RegistryService`**: lookups in the three national registries.
  - Client Registry: patient search plus linking a patient to their CR and
    SHA numbers.
  - Facility Registry: checks the facility, its KEPH level and its SHA
    contract.
  - Health Worker Registry: checks the licence and its expiry.
- **`ShaService`**: eligibility, benefits/interventions, the visit OTP that
  produces the consent token (encrypted on the encounter), and the
  discharge OTP.
- **`ShaClaimGateway`**: builds and sends the claim.
  - Steps: interventions → ICD diagnoses → doctor → lines → preview (shows
    blockers) → submit.
  - Each step is checkpointed so a retry doesn't post duplicates.
  - Submit is sent exactly once. If SHA doesn't confirm it, the claim is
    marked `SUBMIT_UNCONFIRMED` until it is reconciled.
  - `ClaimGatewayResolver` sends SHA claims through this gateway when the
    integration is on, and all others through the manual gateway.
- **UI**:
  - Administration → SHA & HIE integration: connection settings, test,
    facility FR codes, practitioner licences and the request log.
  - Register patient: Client Registry lookup that prefills the form.
  - Reception: "SHA verify" button on insured visits, which opens a page for
    eligibility, interventions and OTP consent.
  - Insurance: submission with discharge OTP and reason, plus "Check SHA
    status".
  - Service tariffs: SHA intervention code per service.
- **Permissions**:
  - `integrations.manage` for administrators.
  - `sha.verify` for receptionists and insurance officers.
  - Run `php artisan hms:sync-roles` on existing databases.
- **Tests**: `tests/Feature/Integration/ShaHieIntegrationTest.php`, 9 tests.

Still to do:

- SHA intervention codes on the lab and imaging catalog pages; the columns
  exist, but the forms don't show them yet.
- ICD-11 lookup (DHA terminology service) instead of free-text codes.
- Pre-authorisation workflow (`/preauths`) for admissions and theatre.
- Biometric consent (`/claims/authorize`).
- Claim document attachments.
- Remittance and status callbacks.
- FHIR Shared Health Record exchange.
- Confirm every endpoint and field name in DHA UAT once credentials are
  issued.

## 8. KEPH facility levels, referrals and critical care (2026-11-04)

Migration: `2026_11_04_300001_add_facility_levels_referrals_critical_care`.

- **Levels 1–6**: stored in `facilities.level`.
  - Set on the platform tenant form, on Facilities → "Level & services",
    or by Facility Registry verification (the register is authoritative).
  - `App\Support\FacilityLevel` holds the labels and the parsing logic.
- **Modules by level**: `ModuleRegistry` gives each module a `min_level`.
  - Level 1: reception, patients, referrals, billing.
  - Level 2: adds consultation, triage, lab and pharmacy.
  - Level 3: adds emergency, wards and admissions.
  - Level 4: adds radiology, theatre and critical care (HDU).
  - Levels 5 and 6: everything.
  - Admins can switch modules on or off per facility; only the differences
    from the level's defaults are stored, in `facilities.enabled_modules`.
  - `EnsureModuleEnabled` enforces this on routes and on Livewire actions,
    so a hidden module cannot be reached by URL.
- **Referrals** (`referrals.*`, `ReferralService`):
  - Outgoing referrals start from the consultation page or inpatient chart
    (the "Refer" button), with a Facility Registry search for the
    destination.
  - Sending moves an outpatient visit to billing.
  - Incoming referrals are registered, received (which checks the patient
    in with priority set by urgency), then closed with a back-referral.
  - Each referral has a printable letter.
  - An SHA claim on a referred visit defaults its discharge reason to
    REFERRED.
- **Critical care** (`critical-care.*`, `CriticalCareService`):
  - Board of ICU, HDU and NICU patients.
  - Flow chart: haemodynamics with MAP calculated automatically,
    respiratory support, GCS, RASS and glucose.
  - 24-hour fluid balance.
  - Abnormal values alert the admitting doctor.
- **Billing**: bed-days are now split across ward segments (for example,
  ICU and then the general ward), each at its own ward rate.
  - Each ward can carry an SHA per-diem intervention code, which the SHA
    claim uses.
  - ICU/HDU/NICU wards are rejected at facilities below their expected
    level.
- **Not yet built**:
  - Maternity
  - Dialysis, blood bank, mortuary and specialist clinics
  - Oncology
  - MOH 705/717 reports by level

## 9. Maternity (2026-11-04)

Migration: `2026_11_04_400001_create_maternity_tables`. Service:
`App\Services\Maternity\MaternityService`.

- **Modules by level**:
  - "ANC / PNC clinic" (`maternity.*`) from Level 2.
  - "Labour ward" (`labour.*`) from Level 3.
  - Caesarean sections need a facility that offers theatre (Level 4+).
  - New **Midwife** role, which lands on the labour ward.
  - Permissions: `maternity.view`, `maternity.manage`, `maternity.deliver`.
- **ANC booking**:
  - EDD from the LMP (+280 days) or an ultrasound EDD; G/P+A validation.
  - Profile: blood group/Rh, HIV, syphilis, hepatitis B and height.
  - Risk screening covers: under 18, 35 or older, para 5+, previous C/S,
    recurrent loss, HIV, syphilis, hepatitis B, Rh negative, and height
    under 150 cm.
- **ANC visits**:
  - Measurements: gestational age, weight, BP, fundal height, FHR,
    presentation, urine protein and Hb.
  - Treatments: IPTp, Td, IFAS, deworming and LLIN.
  - Danger signs are recorded, and the next visit date is set.
  - The pregnancy is escalated to high risk automatically for:
    pre-eclampsia features, hypertension, anaemia, abnormal FHR,
    malpresentation at term, and danger signs.
  - ANC-VISIT is charged when the mother has an open visit.
- **Labour**:
  - Admitting to the labour ward opens or links the mother's visit as
    ADMITTED.
  - WHO partograph entries: FHR, liquor, moulding, cervix, descent,
    contractions, oxytocin, maternal observations and urine.
  - A cervicograph shows the alert and action lines. Crossing the action
    line, or critical values (FHR, meconium, moulding +++, severe BP, fever,
    tachycardia), alerts the staff who can deliver.
  - Dilatation is not allowed to go backwards.
- **Delivery**:
  - Records mode, AMTSL, uterotonic, placenta, blood loss (≥500 ml is
    flagged as PPH automatically), perineum and the mother's condition.
  - Multiple babies are supported.
  - Each live-born baby is registered as a patient (category newborn) with
    Apgars, weight (low birth weight is flagged), resuscitation, vitamin K,
    TEO, BCG/OPV0, chlorhexidine and early breastfeeding. Stillbirths are
    recorded without a patient record.
  - Charges DELIVERY-SVD or DELIVERY-CS.
  - "Discharge mother" sends the visit to billing.
  - "Refer out" hands over to the referral form.
- **PNC**: visits at 48 h, 1–2 weeks, 4–6 weeks and 6 months, covering
  mother and baby, family planning and danger signs.
- **Not yet built**:
  - MOH 405 (ANC), 333 (maternity) and 406 (PNC) register exports.
  - PMTCT/HEI follow-up.
  - Child welfare clinic / immunisation schedule.
  - SHA Linda Mama intervention mapping (add the codes in Service tariffs).

## Sources

- https://citizen.digital/article/cs-duale-extends-deadline-for-health-facilities-to-switch-to-shas-digital-system-n391243
- https://citizen.digital/article/govt-gives-hospitals-one-more-month-to-migrate-to-new-health-system-n393066
- https://www.kbc.co.ke/health-ministry-extends-sha-digital-migration-deadline-to-november-30/
- https://www.kenyanews.go.ke/government-extends-hmis-migration-deadline-to-november-30/
- https://thekenyatimes.com/health/sha-extends-system-transition-deadline-with-over-300-hospitals-yet-to-comply/
- https://certification.dha.go.ke/registry
- https://hie-docs.dha.go.ke/docs/registries/gettingStarted/introduction
- https://hie-docs.dha.go.ke/docs/authentication/process/facility-identification
- https://hie-docs.dha.go.ke/registries/client-registry
- https://hie-docs.dha.go.ke/registries/facility-registry
- https://hie-docs.dha.go.ke/registries/health-worker-registry
- https://hie-docs.dha.go.ke/docs/claims/getting-started/introduction
- https://new.kenyalaw.org/akn/ke/act/ln/2025/76/eng@2025-04-11
- https://new.kenyalaw.org/akn/ke/act/ln/2025/77/eng@2025-04-11
- https://pypi.org/project/sha-claim/ (unofficial SDK)
- https://www.hanmak.co.ke/dha-hmis-certification-sha-claims-integration/ (vendor)
