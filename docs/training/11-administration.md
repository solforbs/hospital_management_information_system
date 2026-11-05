# Hospital administration

For hospital owners, hospital and facility administrators and HR officers. Administrators set the hospital up and control who can do what.

## Institution profile and branding

Under **Administration → Institution profile**:

- Enter the hospital's legal name, short name, motto, address, county, phone numbers, email and website.
- Upload the **logo**. It appears on the menu, on every printed document, as the browser tab icon, and on **every email** HMIS sends for your hospital. Until you upload one, the HMIS logo is used.
- Choose the **brand colours** and add **signatories** (name, title and signature) for printed documents and the official **stamp**.
- Set **security** rules: an optional **IP allow-list** (only approved networks can sign in) and an **idle timeout**. HMIS refuses to save an allow-list that would lock *you* out.

## Facilities, levels and departments

- **Facilities:** each branch or campus, with its **KEPH level (1–6)**, MFL/registry codes and **KHIS org-unit** ID (needed for DHIS2 exports).
- The level decides which modules are offered. For example, theatre needs level 4 and up, and dialysis level 5 and up. You can **enable or disable modules** per facility.
- **Departments** belong to a facility and are used for check-in, queues and staff assignment.

## Staff and roles

- **Add staff** under **Administration → Staff** with their work email, role, facility and department. HMIS emails them a secure **set-your-password** link. The link is valid 72 hours and works once.
- The staff list shows **Invite pending** until they set a password. Use **Resend invite** if needed.
- **Suspend** leavers on their last day. A suspended person can no longer access your hospital.
- **Roles & permissions:** give each person the role that matches their job. You can adjust a role's permissions. Keep the **least privilege** principle: no one should have access they do not need.
- Make sure every new user completes their **required training** (see **Training records**) before working unsupervised.

## Catalogs and tariffs

- **Service tariffs**, the **Lab test catalog** and the **Imaging catalog** hold prices and the **SHA intervention codes** that claims use. **Medicines** hold prices and stock. Review them whenever SHA tariffs change.
- **Wards & beds** set up wards, beds and each ward's daily (per-diem) rate.

## Integrations

Under **Administration → SHA & HIE integration**:

- **SHA / HIE:** enter the **DHA-issued credentials** and your facility code, then switch from **simulator** to **UAT** (testing) and finally to **production**.
- **SMS & M-Pesa:** enter your Africa's Talking and Safaricom Daraja keys. Use **Send test SMS** to check the SMS connection.
- Credentials are stored **encrypted**. Rotate them if you think they have leaked.

## Go-live checklist

1. Institution profile, logo, signatories and stamp complete
2. Facilities with the correct KEPH level, codes and KHIS org-unit
3. Departments, wards and beds set up
4. Tariffs and catalogs reviewed, with SHA codes
5. Staff invited with the correct roles, and training completed
6. Security rules (allow-list and timeout) set if wanted
7. Integrations tested on the simulator, then UAT, then production
8. First day: a supervisor at each service point
