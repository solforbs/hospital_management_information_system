# Laboratory and radiology

For laboratory technicians and managers, radiographers and radiologists.

## The laboratory worklist

Open **Diagnostics → Laboratory**. Each ordered test moves through these steps:

1. **Collect.** Confirm the patient's identity with two identifiers (name and patient number or date of birth), collect the sample and mark it **collected**. This records the time.
2. **Result.** Enter the result. For numeric tests, HMIS shows the reference range and flags abnormal values.
3. **Verify.** A second, qualified person checks the result and **verifies** it. By default HMIS **does not let the person who entered a result verify it**: verification must be independent.
4. **Release.** The verified result is released to the requesting clinician, who is notified.

Use **Cancel** only for tests that will not be done (for example, the patient left or the sample was rejected). A **reason is required**, and it is kept on the record.

## Quality and safety in the lab

- Never release a result you have doubts about. Repeat the test or collect a new sample.
- Phone the clinician about **critical values** as well as releasing them in HMIS, and note that you did.
- Keep the **Lab test catalog** accurate (managers). Each test carries its price and its **SHA intervention code**, which claims depend on.

## Results from analysers

If your lab's analysers are connected to HMIS (Diagnostics → Lab analysers):

1. After **Collect**, press **Label** to print a barcode label with the specimen number, and stick it on the tube.
2. Run the tube on the analyser. The analyser reads the barcode and sends its results to HMIS automatically.
3. The test moves to **Awaiting verification**, marked with the **analyser's name** instead of a person's. Panels such as a full blood count show every parameter (WBC, HGB, platelets…), with **H**/**L** flags.
4. **Check before you verify:** that the values are plausible, that the analyser's flags and comments are addressed, and that the sample was suitable. An analyser result is never released without a person verifying it.
5. If you change an instrument value by typing over it, the result becomes **yours**, and someone else must verify it.

If a result does not arrive, the lab manager checks **Lab analysers → Recent messages**. An *unmatched* message usually means the barcode was not an HMIS specimen number, or a test code is not mapped yet.

## Radiology

1. Open **Diagnostics → Radiology**. Requests appear with the clinical question.
2. **Schedule** the examination, then mark it **performed** when it is done.
3. The radiologist writes the **report**, with findings and impression.
4. The report is **verified**, and is then visible to the clinician. It can be **printed** on the hospital letterhead.

Use **Cancel** with a reason for examinations that will not be done.

The **Imaging catalog** holds each study's price and SHA intervention code.

## Printed reports

Laboratory and radiology reports print with the hospital letterhead, the patient's details, who performed and verified the work, and the release time. Printed reports are confidential; hand them only to the patient or the care team.
