<?php

use App\Support\RoleCatalog as R;

/*
| Training catalog. Lessons live in docs/training/*.md (one "## " heading per
| lesson) so the same text is the published user manual. Each module lists
| the roles for which it is REQUIRED ('*' = every user) and a quiz.
|
| Quiz answers are graded on the server; 'answer' is the 0-based index of
| the correct option and is never sent to the browser before submission.
*/

$clinicians = [R::DOCTOR, R::CLINICAL_OFFICER, R::SPECIALIST, R::MEDICAL_DIRECTOR, R::SURGEON, R::ANESTHETIST];
$nurses = [R::NURSE, R::TRIAGE_NURSE, R::NURSING_OFFICER_IN_CHARGE, R::MIDWIFE, R::THEATRE_NURSE];
$managers = [R::HOSPITAL_OWNER, R::HOSPITAL_ADMINISTRATOR, R::FACILITY_ADMINISTRATOR, R::MEDICAL_DIRECTOR];

return [
    'getting-started' => [
        'file' => '01-getting-started.md',
        'title' => 'Getting started with HMIS',
        'summary' => 'Your account, signing in safely, finding your way and how a patient moves through the system.',
        'icon' => 'rocket-launch',
        'minutes' => 10,
        'required_for' => ['*'],
        'questions' => [
            ['q' => 'How do you get your HMIS account?', 'options' => ['You register yourself on the login page', 'Your hospital administrator adds you and you receive an email to set your password', 'A colleague shares their login with you', 'The HMIS support team creates it by phone'], 'answer' => 1, 'why' => 'Administrators add staff under Admin → Staff; you then set your own password from the invitation email.'],
            ['q' => 'Your invitation link says it has expired. What should you do?', 'options' => ['Ask a colleague to log in for you', 'Ask your administrator to resend the invite, or use "Forgot your password?"', 'Create a new account with another email', 'Wait — it will start working again tomorrow'], 'answer' => 1, 'why' => 'Invitation links work once and expire after 72 hours; a resend or password reset gives you a new link.'],
            ['q' => 'Which setting best protects your account if someone learns your password?', 'options' => ['A longer display name', 'Two-factor authentication or a passkey', 'Dark mode', 'Logging in only in the morning'], 'answer' => 1, 'why' => 'Two-factor authentication or a passkey (Settings → Security) means a password alone is not enough to sign in.'],
            ['q' => 'Why might a module (for example Theatre) be missing from your menu?', 'options' => ['The system is broken', 'Your role does not include it, or your facility\'s KEPH level does not offer it', 'You have not paid for it personally', 'It only appears on weekdays'], 'answer' => 1, 'why' => 'The menu shows only what your role allows and what your facility\'s level offers.'],
            ['q' => 'After reception checks a patient in, at which stage does the visit appear?', 'options' => ['Waiting for doctor', 'Waiting triage', 'Waiting billing', 'Completed'], 'answer' => 1, 'why' => 'Check-in places the visit in the triage queue as "Waiting triage".'],
            ['q' => 'A colleague moves a patient to your queue. What do you need to do to see it?', 'options' => ['Log out and back in', 'Nothing — queues update by themselves within a few seconds', 'Ask them to phone you', 'Clear your browser history'], 'answer' => 1, 'why' => 'Worklists and queues refresh automatically when colleagues make changes.'],
        ],
    ],

    'privacy-security' => [
        'file' => '02-privacy-and-security.md',
        'title' => 'Privacy, confidentiality and security',
        'summary' => 'Your duties under the Data Protection Act 2019, the audit trail, support access and reporting incidents.',
        'icon' => 'shield-check',
        'minutes' => 10,
        'required_for' => ['*'],
        'questions' => [
            ['q' => 'When may you open a patient\'s record?', 'options' => ['Whenever you are curious', 'Only when you are involved in their care or have a work reason', 'Only if they are a relative', 'At any time if you do not tell anyone'], 'answer' => 1, 'why' => 'Access must have a care or work purpose; looking out of curiosity is a breach even if nothing is shared.'],
            ['q' => 'A colleague asks to use your login because theirs is locked. What do you do?', 'options' => ['Share it just this once', 'Refuse and direct them to the administrator', 'Log in for them and walk away', 'Write it on a sticky note for them'], 'answer' => 1, 'why' => 'Every action is recorded against the signed-in user; sharing accounts breaks accountability.'],
            ['q' => 'How do you correct a clinical note that has already been signed?', 'options' => ['Delete it and write a new one', 'Amend it with a reason; the original version is kept', 'Ask IT to edit the database', 'It cannot be corrected at all'], 'answer' => 1, 'why' => 'Signed notes are versioned: an amendment with a reason is added and the original stays on record.'],
            ['q' => 'What is true of HMIS support ("break-glass") access to your hospital\'s data?', 'options' => ['Support can change any record at any time', 'It is time-limited, read-only, needs a reason, is audited and your admins are notified', 'It is invisible to the hospital', 'It is permanent once granted'], 'answer' => 1, 'why' => 'Break-glass access is tightly controlled: reasoned, time-boxed, read-only, logged and notified.'],
            ['q' => 'You sent a lab result to the wrong patient\'s phone. What should you do?', 'options' => ['Nothing — it was an accident', 'Report it to your administrator or data protection lead immediately', 'Delete the message and forget it', 'Wait to see if anyone complains'], 'answer' => 1, 'why' => 'Breaches must be reported at once; some must reach the ODPC within 72 hours.'],
            ['q' => 'Which identifiers does HMIS store encrypted in the database?', 'options' => ['Patient first names only', 'National ID, Client Registry and SHA numbers', 'Ward names', 'Nothing is encrypted'], 'answer' => 1, 'why' => 'National ID, CR and SHA numbers are encrypted at rest and remain searchable.'],
        ],
    ],

    'front-office' => [
        'file' => '03-front-office.md',
        'title' => 'Front office',
        'summary' => 'Registration without duplicates, check-in, SHA eligibility and consent, appointments and referrals.',
        'icon' => 'queue-list',
        'minutes' => 15,
        'required_for' => [R::RECEPTIONIST, R::RECORDS_OFFICER, R::ADMISSION_OFFICER, R::WARD_CLERK],
        'questions' => [
            ['q' => 'What must you do before registering a new patient?', 'options' => ['Nothing — always register', 'Search by name, phone, number or ID to make sure they are not already registered', 'Ask the doctor', 'Print a form'], 'answer' => 1, 'why' => 'Searching first prevents duplicate records, which split history and cause claim rejections.'],
            ['q' => 'HMIS warns a new registration may be a duplicate. When should you choose "Register anyway"?', 'options' => ['Always, to save time', 'Only when you have checked the matches and are sure it is a different person', 'When the queue is long', 'Never under any circumstances'], 'answer' => 1, 'why' => 'Check the suggested matches first; register only a genuinely different person.'],
            ['q' => 'You find two records for the same person. What happens next?', 'options' => ['Delete the newer one', 'Report it so an authorised user can merge them (Patients → Merge)', 'Keep using both', 'Change one person\'s name'], 'answer' => 1, 'why' => 'A merge keeps all visits, results and bills on one record; nothing is deleted.'],
            ['q' => 'For an SHA-paid visit, what must happen before services for the claim to succeed?', 'options' => ['Nothing', 'Check eligibility and capture the member\'s consent by OTP or fingerprint', 'Collect cash as a deposit', 'Print the SHA card'], 'answer' => 1, 'why' => 'Eligibility plus OTP/biometric consent on the SHA visit page are required for a valid claim.'],
            ['q' => 'Why is an accurate phone number important?', 'options' => ['It is not important', 'Patients receive appointment reminders and M-Pesa payment prompts on it', 'It replaces the patient number', 'It is printed on the wristband'], 'answer' => 1, 'why' => 'SMS reminders and M-Pesa STK prompts go to the recorded phone number.'],
            ['q' => 'Where are patients normally referred when the facility cannot offer a service?', 'options' => ['To a lower KEPH level', 'To a facility at a higher KEPH level that offers the service', 'Anywhere, levels do not matter', 'Back home without a referral'], 'answer' => 1, 'why' => 'Referrals usually go up the KEPH levels; HMIS warns when the receiving level does not fit.'],
        ],
    ],

    'triage-nursing' => [
        'file' => '04-triage-and-nursing.md',
        'title' => 'Triage and nursing',
        'summary' => 'Vital signs, triage categories, ward nursing and maternal-child health recording.',
        'icon' => 'heart',
        'minutes' => 12,
        'required_for' => $nurses,
        'questions' => [
            ['q' => 'A patient is triaged orange (very urgent). What happens to their visit?', 'options' => ['Nothing changes', 'It becomes an Emergency and is shown first in the queues', 'It is sent to billing', 'It is cancelled'], 'answer' => 1, 'why' => 'Red and orange make the visit an Emergency; yellow makes it Urgent. They are shown first.'],
            ['q' => 'Which triage colour means "Immediate — life-threatening"?', 'options' => ['Blue', 'Green', 'Red', 'Yellow'], 'answer' => 2, 'why' => 'Red is Immediate; orange very urgent; yellow urgent; green standard; blue non-urgent.'],
            ['q' => 'A vital sign looks very unusual. What should you do?', 'options' => ['Save it anyway', 'Re-check it before saving; leave it empty rather than guess', 'Change it to a normal value', 'Skip triage'], 'answer' => 1, 'why' => 'Mistyped values mislead clinicians; re-check, and never guess.'],
            ['q' => 'When should ward observations and medication given be recorded?', 'options' => ['At the end of the shift', 'At the time you do them', 'Weekly', 'Only if something goes wrong'], 'answer' => 1, 'why' => 'Contemporaneous recording is accurate and is legal evidence of care.'],
            ['q' => 'What happens automatically when a live birth is recorded?', 'options' => ['Nothing', 'The baby is enrolled in the Child welfare clinic with the KEPI schedule', 'The mother is discharged', 'An invoice is paid'], 'answer' => 1, 'why' => 'Live births auto-enrol in CWC with the immunisation schedule and produce the birth notification.'],
            ['q' => 'A critically ill patient arrives. Should they wait in the triage queue?', 'options' => ['Yes, in arrival order', 'No — take them to Emergency and record details once they are safe', 'Yes, but at the end', 'Send them home'], 'answer' => 1, 'why' => 'Emergencies are treated first; documentation follows as soon as the patient is safe.'],
        ],
    ],

    'clinical' => [
        'file' => '05-clinical-consultation.md',
        'title' => 'Clinical consultation and documentation',
        'summary' => 'Worklist, signed notes and amendments, ICD-11 diagnosis, orders, prescriptions and completing the visit.',
        'icon' => 'clipboard-document-list',
        'minutes' => 15,
        'required_for' => $clinicians,
        'questions' => [
            ['q' => 'Which coding system must diagnoses use?', 'options' => ['ICD-10', 'Free text', 'ICD-11', 'Any code the doctor prefers'], 'answer' => 2, 'why' => 'Kenya uses ICD-11; HMIS accepts only coded ICD-11 diagnoses.'],
            ['q' => 'Can an ICD-11 extension code (chapter X, e.g. "Mild") be recorded as a diagnosis on its own?', 'options' => ['Yes', 'No — it only qualifies a diagnosis', 'Only on weekends', 'Only for children'], 'answer' => 1, 'why' => 'Extension (X) and functioning (V) codes cannot stand alone as a diagnosis.'],
            ['q' => 'HMIS refuses to complete a visit. What are the two likely reasons?', 'options' => ['The patient has not paid and is over 60', 'There is no diagnosis, or there are unsigned draft notes', 'The printer is offline', 'Lab is closed'], 'answer' => 1, 'why' => 'A visit needs at least one diagnosis and no draft notes before it can be completed.'],
            ['q' => 'You need to correct a signed note. What does HMIS require?', 'options' => ['Nothing — just edit it', 'An amendment with a reason; the original stays in the history', 'Deleting the note', 'Administrator approval'], 'answer' => 1, 'why' => 'Amendments require a reason and never overwrite the signed original.'],
            ['q' => 'What happens to a lab order after you place it?', 'options' => ['You must phone the lab', 'It goes to the lab worklist and is charged to the bill or claim automatically', 'It is printed only', 'It waits until the visit is completed'], 'answer' => 1, 'why' => 'Orders flow straight to the department and the patient\'s account.'],
            ['q' => 'With the HIE connection on, what happens when you complete a visit?', 'options' => ['Nothing', 'It is sent to the national Shared Health Record in FHIR format', 'It is deleted after 30 days', 'It is emailed to the patient'], 'answer' => 1, 'why' => 'Completed visits are pushed to the Shared Health Record (HL7 FHIR R4).'],
        ],
    ],

    'diagnostics' => [
        'file' => '06-laboratory-and-radiology.md',
        'title' => 'Laboratory and radiology',
        'summary' => 'Collect, result, independent verification and release; imaging requests, reports and printing.',
        'icon' => 'beaker',
        'minutes' => 10,
        'required_for' => [R::LABORATORY_TECHNICIAN, R::LABORATORY_MANAGER, R::RADIOLOGIST, R::RADIOLOGY_TECHNICIAN],
        'questions' => [
            ['q' => 'What is the correct order for a lab test?', 'options' => ['Release → result → collect', 'Collect → result → verify → release', 'Verify → collect → release', 'Result → release → collect'], 'answer' => 1, 'why' => 'Collect the sample, enter the result, verify independently, then release.'],
            ['q' => 'You entered a result. Can you verify it yourself?', 'options' => ['Yes, always', 'No — by default a different person must verify it', 'Only at night', 'Only for urgent tests'], 'answer' => 1, 'why' => 'HMIS enforces independent verification by default.'],
            ['q' => 'What must you give when cancelling a test?', 'options' => ['Nothing', 'A reason, which is kept on the record', 'The patient\'s signature', 'A new order'], 'answer' => 1, 'why' => 'Cancellations require a reason for the audit trail.'],
            ['q' => 'How should you identify a patient when collecting a sample?', 'options' => ['By bed number only', 'With two identifiers, such as name and patient number or date of birth', 'By asking "are you the patient?"', 'From the queue order'], 'answer' => 1, 'why' => 'Two identifiers prevent wrong-patient samples.'],
            ['q' => 'What does a test catalog entry carry that SHA claims depend on?', 'options' => ['The technician\'s name', 'The SHA intervention code (and price)', 'The sample colour', 'Nothing'], 'answer' => 1, 'why' => 'Catalog SHA intervention codes flow into claims.'],
            ['q' => 'In radiology, when does the clinician see the report?', 'options' => ['As soon as it is scheduled', 'After the report is written and verified', 'Never', 'Only when printed'], 'answer' => 1, 'why' => 'Reports become visible to the clinician once verified.'],
        ],
    ],

    'pharmacy-stores' => [
        'file' => '07-pharmacy-and-stores.md',
        'title' => 'Pharmacy, stores and purchasing',
        'summary' => 'Safe dispensing, batches and expiry, purchase orders, goods received and issues.',
        'icon' => 'shopping-bag',
        'minutes' => 10,
        'required_for' => [R::PHARMACIST, R::PHARMACY_TECHNICIAN, R::STOREKEEPER, R::PROCUREMENT_OFFICER],
        'questions' => [
            ['q' => 'A prescription dose looks unsafe. What do you do?', 'options' => ['Dispense what is written', 'Contact the prescriber before dispensing', 'Halve the dose yourself', 'Cancel it without telling anyone'], 'answer' => 1, 'why' => 'Never guess: clarify with the prescriber first.'],
            ['q' => 'What happens when you dispense?', 'options' => ['Nothing else', 'Stock is deducted and the item is added to the bill or claim', 'The prescription is deleted', 'The doctor is charged'], 'answer' => 1, 'why' => 'Dispensing updates stock and the patient\'s account in one step.'],
            ['q' => 'Which batch is issued first?', 'options' => ['The newest', 'The one that expires first', 'The largest', 'Any batch'], 'answer' => 1, 'why' => 'First-expiry, first-out reduces waste; expired batches are never dispensed.'],
            ['q' => 'When goods arrive from a supplier, what goes into stock?', 'options' => ['Whatever the purchase order said', 'Only what was actually delivered, received against the purchase order', 'Double the order for safety', 'Nothing until month end'], 'answer' => 1, 'why' => 'Receive the actual delivery (goods received note) against the PO.'],
            ['q' => 'The stock count does not match the system. What is the right action?', 'options' => ['Adjust silently to make numbers fit', 'Record the adjustment with the real reason', 'Ignore it', 'Delete the item'], 'answer' => 1, 'why' => 'Honest, reasoned adjustments keep the audit trail true.'],
            ['q' => 'Why record batch numbers and expiry dates?', 'options' => ['They are optional', 'They make recalls, audits and expiry control possible', 'Only for antibiotics', 'For marketing'], 'answer' => 1, 'why' => 'Batch and expiry data support recalls, audits and safe dispensing.'],
        ],
    ],

    'billing-sha' => [
        'file' => '08-billing-sha-and-payments.md',
        'title' => 'Billing, SHA claims and payments',
        'summary' => 'Automatic charges, payments and M-Pesa, discounts and refunds, SHA claims and remittances.',
        'icon' => 'banknotes',
        'minutes' => 15,
        'required_for' => [R::CASHIER, R::ACCOUNTANT, R::INSURANCE_OFFICER],
        'questions' => [
            ['q' => 'How do lab tests and medicines get onto the bill?', 'options' => ['The cashier types them from paper', 'Automatically when they are ordered or dispensed', 'The patient adds them', 'At month end'], 'answer' => 1, 'why' => 'Charges flow automatically from orders, dispensing and admissions.'],
            ['q' => 'During an M-Pesa STK prompt, who enters the M-Pesa PIN?', 'options' => ['The cashier', 'The patient, on their own phone', 'The doctor', 'Nobody'], 'answer' => 1, 'why' => 'Never ask for or type a PIN; the payment posts automatically on Safaricom\'s confirmation.'],
            ['q' => 'What is required to issue a refund?', 'options' => ['Nothing', 'Refund approval permission and a reason; a refund voucher is issued', 'Only the patient\'s request', 'Voiding the invoice'], 'answer' => 1, 'why' => 'Refunds need permission, a reason and produce a voucher, all audited.'],
            ['q' => 'What is needed to submit a claim to SHA from HMIS?', 'options' => ['Only the invoice', 'Eligibility and consent captured, coded diagnoses, supporting documents, and the discharge OTP', 'A phone call to SHA', 'Cash payment first'], 'answer' => 1, 'why' => 'A complete, consented, coded and documented claim is submitted with the discharge OTP.'],
            ['q' => 'How are SHA payments matched to claims?', 'options' => ['Manually one by one only', 'Import the remittance file; HMIS matches lines to claims and records payment', 'They are not recorded', 'By the doctor'], 'answer' => 1, 'why' => 'Remittance import auto-matches lines; review only the unmatched ones.'],
            ['q' => 'When may an invoice be voided?', 'options' => ['To hide a payment', 'Only when it was raised in error', 'At the patient\'s request to avoid paying', 'Every day at closing'], 'answer' => 1, 'why' => 'Voiding is for invoices raised in error and is audited.'],
        ],
    ],

    'inpatient-maternity' => [
        'file' => '09-inpatient-maternity-and-specialist.md',
        'title' => 'Inpatient, maternity and specialist services',
        'summary' => 'Admission to discharge, theatre and ICU, partograph and newborns, child welfare, blood bank and mortuary.',
        'icon' => 'home-modern',
        'minutes' => 15,
        'required_for' => array_merge($nurses, [R::ADMISSION_OFFICER, R::WARD_CLERK, R::SURGEON, R::ANESTHETIST]),
        'questions' => [
            ['q' => 'Who allocates a bed after a clinician requests admission?', 'options' => ['The patient', 'The admissions office, choosing from clean available beds', 'The cashier', 'Nobody — beds are random'], 'answer' => 1, 'why' => 'Admission requests appear under Inpatient → Admissions for bed allocation.'],
            ['q' => 'What happens to the bed when a patient is discharged?', 'options' => ['It is immediately available', 'It goes to cleaning, then is set available when ready', 'It is deleted', 'It stays occupied'], 'answer' => 1, 'why' => 'Beds cycle available → occupied → cleaning → available.'],
            ['q' => 'A patient is transferred from ICU to a general ward. How are bed-days billed?', 'options' => ['All at the ICU rate', 'Each ward\'s own daily rate for the days spent there', 'Not billed', 'At the general ward rate only'], 'answer' => 1, 'why' => 'Bed history keeps every move and bills each segment at its ward rate.'],
            ['q' => 'What must be done before starting a surgical procedure in HMIS?', 'options' => ['Nothing', 'Complete the safety checklist', 'Discharge the patient', 'Print the invoice'], 'answer' => 1, 'why' => 'Theatre requires the safety checklist before start.'],
            ['q' => 'What does the partograph show when labour progress crosses the alert or action line?', 'options' => ['Nothing', 'An alert', 'The invoice', 'The discharge summary'], 'answer' => 1, 'why' => 'HMIS raises alerts when progress crosses the alert/action lines.'],
            ['q' => 'How is an HIV-exposed infant\'s follow-up finished?', 'options' => ['It is deleted', 'Close the HEI follow-up with the final outcome after recording tests', 'It closes automatically at birth', 'By the cashier'], 'answer' => 1, 'why' => 'Record each PCR/antibody test, then close HEI follow-up with the outcome.'],
        ],
    ],

    'reporting-compliance' => [
        'file' => '10-reporting-and-compliance.md',
        'title' => 'Reporting, records and compliance',
        'summary' => 'MOH 705/717/405/333/406 and KHIS export, DHA interoperability, audit, and management duties.',
        'icon' => 'chart-bar',
        'minutes' => 12,
        'required_for' => array_merge($managers, [R::RECORDS_OFFICER, R::AUDITOR]),
        'questions' => [
            ['q' => 'MOH 705A counts outpatient morbidity for which group?', 'options' => ['Adults over 60', 'Children under 5 years', 'Inpatients only', 'Staff'], 'answer' => 1, 'why' => '705A is under 5; 705B is 5 years and over.'],
            ['q' => 'Where does MOH 705 data come from?', 'options' => ['A separate tally sheet', 'The ICD-11 diagnoses recorded in outpatient visits', 'Billing totals', 'Guesswork'], 'answer' => 1, 'why' => 'Reports are generated from routine clinical records; nothing is re-typed.'],
            ['q' => 'A month\'s report looks wrong because diagnoses are missing. How do you fix it?', 'options' => ['Edit the report numbers', 'Fix the source records, then re-run the report', 'Ignore it', 'Export it anyway'], 'answer' => 1, 'why' => 'Reports are only as good as the records; correct the source data.'],
            ['q' => 'In which format does HMIS share completed visits with the national Shared Health Record?', 'options' => ['PDF', 'HL7 FHIR R4', 'Excel', 'Fax'], 'answer' => 1, 'why' => 'The Shared Health Record exchange uses HL7 FHIR R4.'],
            ['q' => 'Which of these can software NOT do for you for DHA/ODPC compliance?', 'options' => ['Keep an audit log', 'Register the hospital with the ODPC and complete a DPIA', 'Encrypt identifiers', 'Code diagnoses in ICD-11'], 'answer' => 1, 'why' => 'ODPC registration, the DPIA, policies and tested backups are management duties.'],
            ['q' => 'How should you export the MOH data for KHIS?', 'options' => ['Screenshot the page', 'Export in DHIS2 format from the MOH reports page', 'Type it into an email', 'Print and scan it'], 'answer' => 1, 'why' => 'The MOH reports page exports CSV and DHIS2 data value sets for KHIS.'],
        ],
    ],

    'administration' => [
        'file' => '11-administration.md',
        'title' => 'Hospital administration',
        'summary' => 'Institution profile and branding, facilities and levels, staff and roles, catalogs, integrations and go-live.',
        'icon' => 'building-library',
        'minutes' => 15,
        'required_for' => array_merge($managers, [R::HR_OFFICER]),
        'questions' => [
            ['q' => 'Where does the hospital logo appear after you upload it?', 'options' => ['Nowhere', 'Menu, printed documents, browser tab and every email for your hospital', 'Only on invoices', 'Only on the login page'], 'answer' => 1, 'why' => 'The institution logo brands the app, documents, tab icon and emails (HMIS logo until uploaded).'],
            ['q' => 'What decides which modules a facility is offered?', 'options' => ['The weather', 'Its KEPH level (1–6), with per-facility module switches', 'The number of staff', 'The colour scheme'], 'answer' => 1, 'why' => 'Level sets the default modules; admins can enable or disable per facility.'],
            ['q' => 'A staff member leaves today. What should you do?', 'options' => ['Nothing', 'Suspend their access on their last day', 'Give their login to the replacement', 'Delete all their records'], 'answer' => 1, 'why' => 'Suspend leavers immediately; never reuse accounts.'],
            ['q' => 'Which principle should guide role assignment?', 'options' => ['Give everyone full access', 'Least privilege — only the access their job needs', 'Random roles', 'Copy the administrator role'], 'answer' => 1, 'why' => 'Least privilege limits damage from mistakes and misuse.'],
            ['q' => 'In which order should the SHA/HIE integration be switched on?', 'options' => ['Production immediately', 'Simulator → UAT (testing) → production', 'UAT only, forever', 'It switches itself'], 'answer' => 1, 'why' => 'Test on the simulator, then DHA UAT, then go to production.'],
            ['q' => 'You set an IP allow-list that excludes your own network. What happens?', 'options' => ['You are locked out', 'HMIS refuses to save it', 'Everyone is locked out', 'Nothing'], 'answer' => 1, 'why' => 'HMIS protects you from locking yourself out.'],
        ],
    ],
];
