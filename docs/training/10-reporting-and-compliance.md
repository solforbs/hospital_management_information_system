# Reporting, records and compliance

For medical directors, records (HRIO) officers, auditors and managers. This module explains how HMIS meets Ministry of Health (MOH) reporting and Digital Health Agency (DHA) requirements, and what *your* part is.

## MOH reports from routine data

HMIS produces the MOH summary tools straight from the clinical records. Nothing is re-typed, so a report is only as good as the records behind it.

| Report | What it counts | Comes from |
|---|---|---|
| **MOH 705A** | Outpatient morbidity, **under 5 years** | ICD-11 diagnoses of new outpatient visits |
| **MOH 705B** | Outpatient morbidity, **5 years and over** | ICD-11 diagnoses of new outpatient visits |
| **MOH 717** | Service workload | Visits, admissions, tests, procedures |
| **MOH 405** | Antenatal care | ANC clinic visits |
| **MOH 333** | Maternity | Labour ward deliveries |
| **MOH 406** | Postnatal care | PNC visits |

Open **Management → Reports → MOH reports**, choose the **facility**, **month** and **report**, review it, and **export** it as CSV, or in **DHIS2 format** for upload to **KHIS**.

> Before exporting, check that the month's visits have diagnoses and that births and ANC/PNC visits were recorded. Missing data cannot be fixed by editing the report: fix the source record.

## Interoperability (DHA requirements)

HMIS connects to the national **Health Information Exchange (HIE)** run by the DHA:

- **Client Registry:** patients are identified with their national CR number.
- **Facility Registry and Health Worker Registry:** facility and practitioner identity.
- **SHA:** eligibility, consent (OTP/biometric), pre-authorisation, claims and payment status.
- **Shared Health Record:** each completed visit is shared in **HL7 FHIR R4** format.
- **ICD-11** for diagnoses, and **SHA intervention codes** for services.

These connections become live when the hospital's DHA credentials are entered (Admin → SHA & HIE integration). Until then HMIS runs against a simulator, which lets staff train safely.

## Records and audit

- **Audit log** (Management → Audit log): every sign-in, view and change, with the user, time and network address. Review it regularly, and always after an incident.
- Clinical notes are **signed and versioned**, and corrections are **amendments** with a reason.
- Patient **merges** are audited, and nothing is deleted.
- Printed documents (discharge summaries, results, certificates, birth and death notifications) carry the facility letterhead and the responsible signatory.

## Your compliance duties

DHA certification and the Data Protection Act also require things that **software alone cannot do**. Management must make sure that:

- The hospital is **registered with the ODPC** as a data controller/processor, and a **Data Protection Impact Assessment** has been done.
- There are written **security, privacy and confidentiality** policies, and staff have read them.
- **Backups** are taken and a restore has been **tested** (recovery time and recovery point are documented).
- Every user has completed their **required training modules** (see Admin → Training records) before they get access.
- Access is **reviewed**: leavers are suspended the day they leave, and roles match the jobs people actually do.
