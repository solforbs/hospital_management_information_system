# Front office: registration, check-in, appointments and referrals

For receptionists, records officers, admission officers and ward clerks. The front office sets up every visit, so mistakes here follow the patient through the whole system.

## Always search before you register

Duplicate records split a patient's history and cause clinical risk and claim rejections.

1. Open **Front office → Patients** (or **Reception**) and **search** by name, phone number, patient number or national ID.
2. Only if the patient is not found, choose **Register patient**.
3. When the national **Client Registry** is connected, use **Look up** with the patient's ID to fetch their details and CR number from the national registry. This fills the form with verified details.
4. If HMIS warns that the patient **may already exist**, open the matches and check them. Use **Register anyway** only when you are sure the patient is a different person.

If you discover two records for the same person, report it. An authorised user can **merge** them under **Patients → Merge**, which keeps all visits, results and bills on one record.

## Recording identity correctly

- Enter names exactly as on the ID document: first, middle and last name.
- Record **date of birth** accurately. Age decides the under-5 and 5-and-over MOH 705 reports and paediatric dosing.
- Record a working **phone number** in the format 07XX XXX XXX or +254… The patient will receive appointment reminders and M-Pesa payment prompts on it.
- National ID, CR and SHA numbers are stored **encrypted**, and you can still search by them.
- Record **allergies** and next of kin when the patient gives them.

## Checking a patient in

1. In **Reception**, select the patient.
2. Choose the **department** and, if needed, the **doctor**.
3. Choose how the visit will be paid: **cash**, **insurance** (SHA and private insurers are both set up as insurance providers), **corporate**, or **waiver**.
4. Set the **priority** (normal, urgent or emergency) if the patient is clearly unwell; triage can raise it later.
5. Press **Check in**. The visit appears in the triage queue as *Waiting triage*.

For patients with an appointment, use **Check in** next to their appointment in today's list. Use **Cancel visit** if a patient leaves before being seen, so they do not stay in the queues.

## SHA patients: eligibility and consent

For visits paid by SHA, open the patient's **SHA visit** page:

1. **Check eligibility.** HMIS asks SHA whether the member is active and shows their benefits.
2. **Capture consent.** Send a one-time PIN (**OTP**) to the member's phone and enter it, or use **biometric (fingerprint) verification**.
3. Some services need **pre-authorisation** before they are given. Request it here, and check its status before the service.

Without verified eligibility and consent, SHA will reject the claim.

## Appointments and reminders

- Book appointments under **Front office → Appointments**.
- HMIS sends an **SMS reminder** the day before (at 08:00) to the patient's phone number.
- On the day, check the patient in from the appointment so the visit links to it.

## Referrals

Kenya's health system is organised in **KEPH levels 1–6** (community, dispensary, health centre, sub-county, county referral and national referral hospitals).

- To refer a patient, open **Front office → Referrals → New referral**, choose the patient, and search for the receiving facility. With the national Facility Registry connected, the search uses official facility codes.
- HMIS warns you if the receiving facility's level does not fit the referral. Patients are normally referred to a **higher level** for services the current facility cannot offer.
- Record the reason and the clinical summary. Incoming referrals appear in the same list for follow-up.
