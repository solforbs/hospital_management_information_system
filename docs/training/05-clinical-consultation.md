# Clinical consultation and documentation

For doctors, clinical officers, specialists and surgeons. The consultation record is a legal document, the basis of SHA claims, and the source of MOH morbidity reports.

## Your worklist

- Open **Clinical → Doctor worklist**. **Emergency** patients (triaged red or orange) come first, then **urgent** (yellow), then everyone else in arrival order. Each row shows the triage colour.
- Open a patient and press **Start consultation**. The visit moves to *In consultation*, so colleagues know the patient is with you.
- The tabs are **Notes, Diagnosis, Orders, Prescriptions, Disposition** and **Timeline**. The timeline shows the patient's earlier visits, results and admissions.

## Clinical notes: draft, sign, amend

1. Write the history, examination and plan in **Notes**. Your draft is saved as you work.
2. When the note is complete, **Sign** it. A signed note is final, and is attributed to you with the date and time.
3. To correct a signed note, use **Amend** and give a **reason** (required). The amendment is saved as a new version, and the original stays visible in the history. Notes are never silently overwritten.
4. A draft you no longer need can be **discarded**. Signed notes cannot be deleted.
5. Record **allergies** on the patient so every department sees them.

## Diagnosis with ICD-11

Kenya uses **ICD-11** for diagnosis coding, and SHA claims and MOH 705 reports depend on it.

- In **Diagnosis**, type a condition or code (for example *malaria* or *1F40*) and **pick** the matching ICD-11 entry.
- Free-text diagnoses are not accepted. Neither are ICD-11 **extension codes** (chapter X, e.g. severity or laterality) or **functioning codes** (chapter V) on their own.
- Mark the **primary** diagnosis. Add secondary diagnoses as needed.
- Choose the most specific code that you can support with your documentation. Use "unspecified" codes only when the detail is genuinely unknown.

## Orders and prescriptions

- **Orders → Laboratory / Radiology:** choose tests from the catalog. Each order goes straight to that department's worklist, and is charged to the patient's bill or claim automatically.
- **Prescriptions:** search the medicine, then enter **dose, frequency, duration and route**. The pharmacist sees exactly what you write, so be complete and legible. Check the patient's allergies first.
- While results are pending, send the patient to **Awaiting results**. When results are **released**, they appear in the patient's record and you are notified.

## Disposition and completing the visit

In **Disposition**, decide what happens next:

- Send to **Waiting pharmacy** or **Waiting billing**.
- **Request admission.** Choose the ward; the admissions office allocates a bed.
- **Refer** the patient to another facility (see the referral lesson in Front office).
- Issue a **medical certificate** (sick leave or fitness). It prints on the hospital letterhead with your signature.

Finally, **Complete visit**. HMIS refuses to close a visit that has **no diagnosis**, or that still has **draft (unsigned) notes**: sign or discard them first. Once your hospital's national HIE connection is switched on, every completed visit is sent automatically to the national **Shared Health Record** in FHIR format, so other facilities can see it with the patient's consent.

## Good documentation is good care

- Document **at the time of care**.
- Use clear language; avoid abbreviations that others may not know.
- Every test ordered and medicine prescribed should match a documented reason.
- An incomplete record is a common reason for **SHA claim rejection**. Completeness protects the patient, you and the hospital.
