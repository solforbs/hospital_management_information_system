# HMIS documentation

Documentation for **HMIS**, a multi-tenant Hospital Management Information System for Kenyan health facilities (KEPH levels 1–6).

| Document | For | What it covers |
|---|---|---|
| [System overview](system-overview.md) | Everyone, DHA reviewers | What HMIS does, how a patient moves through it, how data flows to SHA, the HIE and KHIS, and how it is secured |
| [Onboarding guide](onboarding.md) | Platform and hospital administrators | Bringing a new hospital live: set-up, staff, integrations, training sign-off and go-live |
| [User training manual](training/) | All staff | Role-based lessons. The same text is shown in the app under **Help & training**, with a quiz at the end of each module |
| [DHA & MOH requirements matrix](compliance/dha-moh-requirements-matrix.md) | Management, DHA certification | Each regulatory requirement, how HMIS meets it, where, the evidence, and the facility's own obligations |
| [Kenya SHA/DHA compliance research](kenya-sha-dha-compliance.md) | Technical team | The directive, legal basis, HIE integration details and implementation status |

## Training modules

| # | Module | Required for |
|---|---|---|
| 1 | [Getting started](training/01-getting-started.md) | Everyone |
| 2 | [Privacy, confidentiality and security](training/02-privacy-and-security.md) | Everyone |
| 3 | [Front office](training/03-front-office.md) | Receptionists, records, admission officers, ward clerks |
| 4 | [Triage and nursing](training/04-triage-and-nursing.md) | Nurses, triage nurses, midwives, theatre nurses |
| 5 | [Clinical consultation](training/05-clinical-consultation.md) | Doctors, clinical officers, specialists, surgeons, anaesthetists |
| 6 | [Laboratory and radiology](training/06-laboratory-and-radiology.md) | Lab and radiology staff |
| 7 | [Pharmacy, stores and purchasing](training/07-pharmacy-and-stores.md) | Pharmacy, stores and procurement |
| 8 | [Billing, SHA claims and payments](training/08-billing-sha-and-payments.md) | Cashiers, accountants, insurance officers |
| 9 | [Inpatient, maternity and specialist services](training/09-inpatient-maternity-and-specialist.md) | Ward, maternity and theatre teams |
| 10 | [Reporting, records and compliance](training/10-reporting-and-compliance.md) | Management, records officers, auditors |
| 11 | [Hospital administration](training/11-administration.md) | Owners, administrators, HR |

Each module ends with a quiz in the app (pass mark **80%**, unlimited retakes, answer order shuffled each attempt). Results are recorded per user. Administrators see them under **Administration → Training records**, which can be exported as CSV for audits.

## Maintaining this documentation

- Lessons are the `## ` sections of each file in `docs/training/`. Edit the Markdown and redeploy: the app shows the new text immediately.
- Quiz questions, and the roles each module is required for, live in `resources/training/catalog.php`. Every question must be answerable from its module's lessons.
- When a workflow changes in the code, update the matching lesson in the same change.
