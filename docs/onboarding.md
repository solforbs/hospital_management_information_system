# Onboarding a hospital to HMIS

This guide takes a new hospital from signing up to its first live day. Follow the phases in order and tick each item off.

## Phase 0: before you start (hospital management)

These obligations come from the **Digital Health Act 2023**, the **Data Protection Act 2019** and SHA contracting. The hospital must handle them itself:

- [ ] SHA contract in place, and the facility's **Facility Registry (FR) code** and **MFL code** known
- [ ] Registered with the **Office of the Data Protection Commissioner (ODPC)** as a data controller
- [ ] A **Data Protection Impact Assessment (DPIA)** for using an electronic health record
- [ ] Written **security, privacy and confidentiality** and **backup/retention** policies
- [ ] A named **data protection lead** and **system administrator**
- [ ] Practitioners' regulator registration numbers (KMPDC, NCK, PPB, etc.) available
- [ ] Devices and internet at each service point (reception, triage, consultation rooms, lab, pharmacy, cashier, wards)

## Phase 1: create the hospital (platform administrator)

1. Sign in as a platform administrator and open **Platform: tenants → New tenant**.
2. Enter the organisation's name, type, county, plan, its **main facility** (name, code, **KEPH level 1–6**) and the **hospital administrator** (name, email, initial password).
3. HMIS creates the hospital, its default roles, catalogs and main facility, and makes the named person its administrator.
4. Give the administrator the system address. Ask them to sign in, change the password, and turn on **two-factor authentication**.

## Phase 2: set up the hospital (hospital administrator)

Training module: [Hospital administration](training/11-administration.md).

- [ ] **Institution profile:** legal name, short name, motto, address, contacts, **logo**, brand colours, **signatories** and **stamp**. The logo then appears in the app, on printed documents and on every email.
- [ ] **Facilities:** each campus with its **KEPH level**, **MFL code**, **FR code** and **KHIS org-unit ID**. Review the modules switched on for each facility.
- [ ] **Departments** for each facility.
- [ ] **Wards & beds**, with each ward's daily rate.
- [ ] **Service tariffs**, **Lab test catalog** and **Imaging catalog**, with prices and **SHA intervention codes**. **Medicines** need prices and opening stock.
- [ ] **Security:** idle timeout, and an IP allow-list if the hospital wants sign-in only on site.

## Phase 3: staff and training

- [ ] Add every user under **Administration → Staff** with the **right role**, facility and department. Each person receives a secure email link to set their own password (valid 72 hours).
- [ ] Review **Roles & permissions** and apply least privilege.
- [ ] Every user completes the modules **required for their role** under **Help & training**, each ending in a quiz with an 80% pass mark.
- [ ] Track completion under **Administration → Training records**. Export the CSV and keep it as training evidence for DHA and ODPC audits.
- [ ] Hold a supervised **practice session** on the simulator, with test patients, a full visit, a lab order, dispensing, payment and an SHA claim.

## Phase 4: integrations

Under **Administration → SHA & HIE integration**:

| Integration | What you need | Steps |
|---|---|---|
| **DHA HIE / SHA** | DHA-issued client ID and secret, facility code (after DHA certification and onboarding) | Simulator → **UAT** (test claims with DHA) → **production** |
| **M-Pesa (Daraja)** | Consumer key and secret, shortcode, passkey from Safaricom | Simulator → sandbox → production; test a small STK push |
| **SMS (Africa's Talking)** | Username, API key, sender ID | **Send test SMS**, then switch on reminders |
| **KHIS (DHIS2)** | Facility org-unit UID, and data element UIDs from the county HRIO | Export one month of MOH reports and validate them with the HRIO |

Until real credentials are entered, every integration runs on a **simulator**, so training and testing never touch live systems.

## Phase 5: go-live

- [ ] Agree a go-live date and a cut-over plan for patients who are mid-visit.
- [ ] On the first day, have a supervisor ("super-user") at each service point.
- [ ] Check every hour that queues move: reception → triage → consultation → lab/pharmacy → cashier.
- [ ] At day end, reconcile cash and M-Pesa against **Cashier**, and review the **Audit log**.
- [ ] After the first week, review SHA claim outcomes, data quality (missing diagnoses, duplicate patients) and the training records.
- [ ] At month end, generate the **MOH reports**, validate them with the HRIO, and export to KHIS.

## Support and escalation

1. **User question:** the hospital's super-user, or the relevant training module.
2. **Access or role problem:** the hospital administrator.
3. **System fault:** the administrator contacts HMIS support. If support needs to see data, it uses **time-limited, read-only, audited break-glass access**, and the hospital's administrators are notified.
4. **Data breach:** the data protection lead. The ODPC must be notified within **72 hours** where required.
