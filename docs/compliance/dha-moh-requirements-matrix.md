# DHA & MOH requirements matrix

How HMIS meets the requirements for a Kenyan facility to run a **DHA-certified HMIS** integrated with **SHA**, and to report to the **Ministry of Health** through **KHIS**. Each row gives the requirement, how HMIS meets it, where in the system to see it, the evidence (automated tests and records), and who is responsible.

**Status key:**
- ✅ Built and tested
- 🔑 Built, but goes live only when the external credentials are entered (works on the simulator until then)
- 🏥 Facility or vendor obligation outside the software. HMIS supports it, but cannot do it for you.
- ⚠️ Known gap

Sources and background: [kenya-sha-dha-compliance.md](../kenya-sha-dha-compliance.md). Confirm current criteria on certification.dha.go.ke before submission.

## 1. DHA HMIS certification criteria

| # | Requirement | How HMIS meets it | Where | Evidence | Status |
|---|---|---|---|---|---|
| 1.1 | Self-attestation and gap analysis (Form HMIS 4) | This matrix and the [system overview](../system-overview.md) supply the answers | — | This document | 🏥 Vendor submits on the DHA Certification Dashboard |
| 1.2 | ODPC registration (data controller/processor) | — | — | — | 🏥 Each facility, and the vendor as processor |
| 1.3 | Data Protection Impact Assessment | Security controls are documented below for the assessor | — | §3 of this document | 🏥 Qualified assessor |
| 1.4 | Security, privacy and confidentiality policy | Technical controls in §3; staff are trained in the *Privacy, confidentiality and security* module (required for all users) | Help & training | `SecurityAndMessagingTest`, `TenantIsolationTest`, training records | 🏥 Policy document; ✅ controls |
| 1.5 | Backup and recovery with a tested RTO/RPO | Additive-only migrations; `deploy.sh` with maintenance mode; MySQL on a managed server | Server | Deployment script | 🏥 Schedule backups and **test a restore**; record the RTO/RPO |
| 1.6 | Interoperability: ICD-11, FHIR, national codes, HIE connection | See §2 | Admin → SHA & HIE integration | `ShaHieIntegrationTest`, `FhirSharedHealthRecordTest`, `IcdCodesPageTest` | ✅ / 🔑 |

## 2. Interoperability and SHA (Digital Health Act 2023, SHA operations)

| # | Requirement | How HMIS meets it | Where | Evidence | Status |
|---|---|---|---|---|---|
| 2.1 | Connect through the national HIE with per-facility identity | OAuth2 client credentials, `X-Facility-Id` header, token caching, request log; simulator → UAT → production | Admin → SHA & HIE integration | `ShaHieIntegrationTest` | 🔑 DHA credentials |
| 2.2 | Client Registry identification | CR lookup at registration pre-fills verified demographics and stores the CR number (encrypted) | Patients → Register | `ShaHieIntegrationTest`, `PatientRegistrationTest` | 🔑 |
| 2.3 | Facility and Health Worker registries | Facility FR/MFL codes; referral facility search; practitioner registration numbers | Facilities, Referrals, Staff | `LevelsReferralsCriticalCareTest` | 🔑 |
| 2.4 | SHA eligibility check | Eligibility and benefits looked up at the visit | SHA visit page | `ShaHieIntegrationTest` | 🔑 |
| 2.5 | Member consent (OTP / biometric) | OTP and fingerprint (biometric) consent captured per visit; discharge OTP at claim submission | SHA visit, Insurance | `ShaHieIntegrationTest` | 🔑 |
| 2.6 | Pre-authorisation | Request, status refresh and insurer callback | SHA visit, Insurance → Pre-auths | `ShaHieIntegrationTest` | 🔑 |
| 2.7 | ICD-11 coded diagnoses | Full WHO ICD-11 MMS (35,664 codes) importable; only coded, diagnosable codes accepted (no free text, no X/V codes alone) | Consultation → Diagnosis; Platform → ICD-11 | `IcdCodesPageTest`, `HospitalWorkflowTest` | ✅ |
| 2.8 | SHA intervention / tariff codes | SHA codes on services, lab tests and imaging flow into claims | Catalogs, Service tariffs | `ShaHieIntegrationTest` | ✅ (medicine codes: ⚠️ not yet mapped) |
| 2.9 | Electronic claims with attachments | Claim built from the visit; documents uploaded and sent as attachments; submit is never auto-retried (no duplicates) | Insurance → Claims | `ShaHieIntegrationTest` | 🔑 |
| 2.10 | Claim status and remittance reconciliation | HMAC-signed status callbacks; remittance import auto-matches and posts payments | Insurance → Remittances | `ShaHieIntegrationTest` | ✅ / 🔑 |
| 2.11 | Shared Health Record exchange (FHIR) | Completed visits pushed as an HL7 FHIR R4 transaction Bundle (Patient, Organization, Encounter, Condition, Observation, MedicationRequest), retried in the background; per-encounter FHIR export | Automatic; `fhir/encounters/{id}` | `FhirSharedHealthRecordTest` | 🔑 |
| 2.12 | Migration off the SHA Provider Portal (deactivated 30 Nov 2026) | All portal functions above are available inside HMIS | — | — | 🔑 Credentials, then UAT sign-off by DHA |

## 3. Data protection and security (Data Protection Act 2019; Digital Health Act 2023)

| # | Requirement | How HMIS meets it | Where | Evidence | Status |
|---|---|---|---|---|---|
| 3.1 | Confidentiality and access only for a purpose | Role-based permissions per hospital (least privilege); modules gated by role and facility level | Roles & permissions | `RolePermissionTest` | ✅ |
| 3.2 | Separation between organisations | Every record scoped to its hospital; foreign IDs return 404 | Automatic | `TenantIsolationTest` | ✅ |
| 3.3 | Security safeguards: encryption | HTTPS (TLS); national ID, CR and SHA numbers encrypted at rest with a searchable blind index; integration credentials encrypted | Automatic | `SecurityAndMessagingTest` | ✅ |
| 3.4 | Authentication | Strong passwords in production, optional two-factor authentication and passkeys, single-use 72-hour invitation links, password reset | Settings → Security; Staff | `TwoFactorChallengeTest`, `StaffInvitationTest`, `PasswordResetTest` | ✅ |
| 3.5 | Session and network controls | Per-hospital idle timeout and IP allow-list (with lock-out protection) | Institution → Security | `SecurityAndMessagingTest` | ✅ |
| 3.6 | Accountability and audit trail | Sign-ins, views and changes logged with user, time and IP; signed notes are versioned and amendments need a reason; merges are audited | Audit log | `HospitalWorkflowTest`, `OperationsTest` | ✅ |
| 3.7 | Controlled third-party (vendor) access | Break-glass support access is reasoned, time-boxed, read-only, audited, and hospital admins are notified | Platform: tenants | `SecurityAndMessagingTest` | ✅ |
| 3.8 | Data accuracy | Duplicate detection at registration, patient merge, ICD-11 validation, independent lab verification | Patients, Lab | `PatientRegistrationTest`, `OperationsTest` | ✅ |
| 3.9 | No public exposure of health data | Signed-in pages and token links are `noindex`; robots.txt allows only the login page | Automatic | `SeoTest` | ✅ |
| 3.10 | Breach notification within 72 hours | Staff are trained to report immediately; the audit log supports the investigation | Help & training | Training records | 🏥 Notify the ODPC |
| 3.11 | Data subject rights (access, correction) | Records can be printed; corrections are amendments that keep history | Patient record | — | 🏥 Process for handling requests |

## 4. Ministry of Health reporting and service delivery

| # | Requirement | How HMIS meets it | Where | Evidence | Status |
|---|---|---|---|---|---|
| 4.1 | MOH 705A/705B outpatient morbidity | Generated from ICD-11 diagnoses of new outpatient visits, under 5 / 5 and over, per day | Reports → MOH reports | `MohReportsTest` | ✅ |
| 4.2 | MOH 717 service workload | Visits, admissions, tests and procedures | Reports → MOH reports | `MohReportsTest` | ✅ |
| 4.3 | MOH 405 ANC, MOH 333 maternity, MOH 406 PNC | From the ANC/PNC clinic and labour ward records | Reports → MOH reports | `MohReportsTest`, `MaternityTest` | ✅ |
| 4.4 | Submission to KHIS (DHIS2) | CSV and DHIS2 data value set export per facility org-unit | Reports → MOH reports | `MohReportsTest` | 🔑 KHIS UIDs from the county HRIO |
| 4.5 | KEPH levels 1–6 and the referral system | Facility level gates modules; referrals with a level check and registry search | Facilities, Referrals | `LevelsReferralsCriticalCareTest` | ✅ |
| 4.6 | Immunisation (KEPI) and HEI follow-up | Automatic CWC enrolment at birth; KEPI schedule with due and overdue doses; growth monitoring; HEI tests and outcome | Child welfare clinic | `DocumentsAndDepartmentsTest`, `MaternityTest` | ✅ |
| 4.7 | Birth and death notification | Printable birth and death notifications from delivery and mortuary records | Labour ward, Mortuary | `DocumentsAndDepartmentsTest` | ✅ |
| 4.8 | Clinical documents | Discharge summary, lab and radiology reports, medical certificates on the facility letterhead with signatories | Print | `DocumentsAndDepartmentsTest` | ✅ |
| 4.9 | Other MOH tools (e.g. 711, 710, 731) and IDSR notifiable-disease reporting | Not yet generated | — | — | ⚠️ Gap: next increment |

## 5. People: training and change management

| # | Requirement | How HMIS meets it | Where | Evidence | Status |
|---|---|---|---|---|---|
| 5.1 | Users trained before use | 11 role-based modules with quizzes (80% pass mark, shuffled answers, unlimited retakes); required modules assigned by role | Help & training | `TrainingTest` | ✅ |
| 5.2 | Evidence of training | Per-user attempt history; hospital training records with completion status; CSV export | Administration → Training records | `TrainingTest` | ✅ |
| 5.3 | Documented onboarding and go-live | [Onboarding guide](../onboarding.md) with phased checklists | docs | — | ✅ |
| 5.4 | User documentation | [Training manual](../training/), shown in the app and published in the repository | Help & training | — | ✅ |

## Remaining before certification and go-live

1. **DHA credentials** (UAT, then production) and DHA's UAT sign-off of the claims and Shared Health Record flows.
2. **Facility obligations:** ODPC registration, DPIA, written policies, and a tested backup/restore with documented RTO/RPO.
3. **Other credentials:** Safaricom Daraja, Africa's Talking, and the KHIS org-unit and data element UIDs.
4. **Gaps for a later increment:** MOH 711/710/731 and IDSR reporting, and SHA codes for medicines.
