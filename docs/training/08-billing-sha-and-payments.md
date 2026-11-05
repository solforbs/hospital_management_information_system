# Billing, SHA claims and payments

For cashiers, accountants and insurance (claims) officers. Accurate billing keeps the hospital running, and SHA claims are a major source of income.

## How charges reach the bill

You rarely type charges by hand. HMIS adds them automatically:

- **Consultation and registration fees** at check-in
- **Laboratory and imaging** when they are ordered
- **Medicines** when they are dispensed
- **Bed-days** for admitted patients, at each ward's daily rate, when they are discharged

Each charge uses the price in **Service tariffs** (or the lab, imaging and medicine catalogs), so keep those up to date. Extra services can be added to an invoice with **Add service**.

## Taking payment

1. Open **Finance → Cashier** and select the patient's invoice.
2. Check the items with the patient.
3. **Receive payment** by **cash**, **M-Pesa**, **card** or **bank transfer**, with the reference number for non-cash methods. A receipt is produced for every payment.
4. **M-Pesa prompt (STK push):** enter the patient's phone and send the prompt. The patient enters their M-Pesa PIN on their own phone, and the payment posts to the invoice **automatically** when Safaricom confirms it. Never ask for or type the patient's PIN.

The invoice shows the **balance** at all times. If your hospital requires it, cash patients must settle their balance **before discharge**.

## Discounts, voids and refunds

These actions need special permissions, and every one is recorded in the audit log:

- **Discounts** need *discount approval* permission. Record why the discount was given.
- **Voiding** an invoice cancels it. Use it only for invoices raised in error, never to hide a payment.
- **Refunds** need *refund approval* permission. Enter the amount, the method, the **reason (required)** and a reference. HMIS issues a **refund voucher** and reduces the amount paid.

## SHA claims, step by step

For a visit to be claimable from SHA:

1. **At the start of the visit:** the member's **eligibility** was checked and **consent** captured (OTP or fingerprint), on the SHA visit page.
2. **Pre-authorisation** was obtained for services that need it.
3. **The clinical record is complete:** ICD-11 diagnoses, the services given, and the treating clinicians.
4. **Create the claim** from the invoice (or **Finance → Insurance → Unclaimed**).
5. **Attach supporting documents** (for example the discharge summary, lab reports and consent) under the claim's documents.
6. **Submit to SHA** with the patient's **discharge OTP**. HMIS sends the claim through the national HIE.
7. Use **Sync status** to follow the claim. SHA also sends status updates to HMIS automatically.

Claims can be rejected for missing consent, codes or documents, or because the member is not eligible. Fix the cause, then resubmit.

## Remittances and reconciliation

When SHA or an insurer pays, they send a **remittance**, a list of the claims paid in that batch.

- Under **Finance → Insurance → Remittances**, **import** the remittance file (CSV).
- HMIS **matches each line to its claim** and records the payment against the invoice automatically.
- Review unmatched lines by hand. These are usually claims with a different reference number.

## Daily cashier routine

- Start and end each shift by checking that the cash in hand matches the payments recorded in HMIS.
- Never take payment without recording it, and never record a payment you have not received.
- Keep M-Pesa and bank references exactly as given, because they are what reconciliation depends on.
