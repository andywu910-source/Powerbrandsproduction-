# Compliance hub: log field map (DRAFT)

Built Nov 18, 2026 from Andy's Google Drive (read only, nothing in Drive was edited), because `compliance_log_map.md`
from the Powerbrands Productions bot had not arrived. When that file arrives, diff it against this one.

The source of truth in code is `config/pbp_logs.php`; the migration `2026_11_18_002000_create_compliance_hub_logs.php`
seeds it into `log_definitions` (re-run `App\Support\LogHub::syncDefinitions()` after editing the config).

Header text is copied exactly from each sheet's header row; a trailing ":" on form-style labels (e.g. "Supplier Name:")
is dropped in the form. Sheet title rows, instruction rows and the free-text "Column 1" in 4-A are not fields.

## Logs whose fields are NOT confirmed (no Drive sheet found; placeholder fields)

| Code | Log | Why |
|---|---|---|
| LBL | Label Print Log | No print-log sheet in Drive. The app already keeps label prints in `label_prints` (Labels tab); fields here are placeholders. |
| EQ-CM | Calibration & Maintenance | "Equipment Inventory & Maintenance Record - Template" only has register columns; no maintenance-event sheet found. |
| SEC | Security Equipment Test | No sheet found (only "Security Plan - CannaCure NY.docx"). |
| CAPA | CAPA Log | New log; no sheet. |
| CMP | Complaint Log | New log; no sheet. |
| RCL | Mock Recall | New log; no sheet ("Quarantine and Recall Plan" docx exists but has no log table). |

Partially mapped: **FR Formulation Record** (form-style sheet, only the header block read), **CHEM Chemical Inventory**
("Simple View" tab only; the "Inventory" tab has more SDS columns), **9-A BPR** (first 16 columns, A-P).

Built from Google Docs rather than sheets (form/table in a doc): 3-B (container label docx), ONB onboarding checklist,
ROT daily rotation table (in SOP-F13-01), 13-B hazard analysis, 12-B signage photo log.

## Choices to confirm

- **6-D merge**: the two 6-D tabs (" Apollo 6-D Supplier Attestation" and "Bodega Boyz 6-D Supplier Attestation" in Finding 6)
  are one log with a new **Brand** field. A separate blank "Supplier Ingredient Attestation Log" sheet also exists with
  different columns; it is not used here. Confirm which one is the real 6-D.
- **1-A**: "Package Tag" renamed **Metrc tag**; added Unit, Method, Performed by, Witness, Performed by signature,
  Witness signature (must be a different person), Photo.
- **Balance Check**: Drive has one tab per scale (e.g. "PB004 Ohaus Scout"); here **Scale** is a field. The sheet says
  "once per week prior to use", so it is weekly.
- **T-1**: one log for SOP sign-offs (tabs "T-1 SOP-INV-02", "T-1 SOP-REC-01") and one for training sign-ins
  (sign-in tabs in the Finding 9 workbook); session header fields are repeated on every row.
- **Signage**: the monthly signage inspection is coded **12-A** and the photo log **12-B**; confirm against the OCM appendix numbering.
- **Deviation** (group 7) uses the Finding 9 "9-A Deviation Log" columns. The app's existing Deviations tab is separate.
- **Frequencies** assumed: Cleaning, Temp & Humidity and Daily Rotation daily (cleaning sheet says "each time"); Pest, 3-C, Equipment Register, Chemical inventory,
  Roster, Signage inspection, 9-A audit, 10-B, Security test monthly; 1-D, 2-D, 4-B, Balance check weekly; 6-E quarterly;
  13-B and Mock Recall annual; FR, 9-A BPR, 5-A, Label print per batch; everything else per event.

## Receiving & Inventory (Metrc)

### 1-C · Receiving Checklist

- Finding: 1 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1qkyeVTc_WueWK5sj3uCFaSAPOQteEMS25voO7gh_hbw/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | A Metrc transfer manifest exists for this delivery/return. | bool |  |  |
| 2 | Each package's unique identifier (UID) has been verified against the manifest. | bool |  |  |
| 3 | The package has been accepted or rejected in Metrc before being moved into any room. | bool |  |  |
| 4 | If no manifest exists, the product has been rejected and NOT accepted into inventory. | bool |  |  |
| 5 | Date | date |  | required |
| 6 | Transfer Manifest # | text |  |  |
| 7 | Source Licensee | text |  |  |
| 8 | Package UID | metrc | metrc_uid | required; exactly 24 letters/numbers |
| 9 | Qty / Weight | text |  |  |
| 10 | Manifest Verified | bool |  |  |
| 11 | Accepted in Metrc | bool |  |  |
| 12 | Received By | employee | employee |  |
| 13 | Item / Strain (per manifest) | text |  |  |

### 1-D · Manifest Review Log

- Finding: 1 · Frequency: Weekly · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1qkyeVTc_WueWK5sj3uCFaSAPOQteEMS25voO7gh_hbw/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Manifest # | text |  | required |
| 2 | Date Reviewed | date |  | required |
| 3 | Discrepancy Found | select |  |  |
| 4 | Package Tag | metrc | metrc_uid | exactly 24 letters/numbers |
| 5 | Description | textarea |  |  |
| 6 | Resolution | textarea |  |  |
| 7 | Reviewed By (Compliance Lead) | employee | employee |  |

### 3-B · Container Label

- Finding: 3 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/document/d/1lpHEtTcCD2bVejzLumwMRwlrriBjAe_b/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Metrc Package UID | metrc | metrc_uid | required; exactly 24 letters/numbers |
| 2 | Strain / Product Name | text |  | required |
| 3 | Tare Weight | text |  |  |
| 4 | Net Weight | text |  |  |

### 2-B · Package Split Record

- Finding: 2 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1vIynb0OwH3lLBwpRECYXlBwWrcffYnRZF0HsNXdbGxA/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Original Package UID | metrc | metrc_uid | required; exactly 24 letters/numbers |
| 3 | Original Weight | text |  |  |
| 4 | New Package UID(s) Created | metrc | metrc_uid | required; exactly 24 letters/numbers |
| 5 | Weight Each "g" | number |  |  |
| 6 | Reason for Split | text |  |  |
| 7 | Entered By | employee | employee |  |

### 2-E · Tag Replacement

- Finding: 2 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1vIynb0OwH3lLBwpRECYXlBwWrcffYnRZF0HsNXdbGxA/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Item | text |  | required |
| 3 | Lost / Damaged Tag (Old UID) | metrc | metrc_uid | required; exactly 24 letters/numbers |
| 4 | Reason | text |  |  |
| 5 | New Tag UID (Metrc) | metrc | metrc_uid | required; exactly 24 letters/numbers |
| 6 | Entered by | employee | employee | NEW (not in sheet) |

### 2-D · Weekly Tag & Container Audit Log

- Finding: 2 · Frequency: Weekly · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1vIynb0OwH3lLBwpRECYXlBwWrcffYnRZF0HsNXdbGxA/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Container / Room | room | room | required |
| 3 | Tag Present & Original | bool |  |  |
| 4 | Deficiency Noted | text |  |  |
| 5 | Corrective Action Taken | text |  |  |
| 6 | Date Corrected | date |  |  |
| 7 | Auditor Signature | signature | employee | e-signature (performed) |

### 4-B · Inventory Count Audit

- Finding: 4 · Frequency: Weekly · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1-wlabDHwaGl3qItoIvfXcsn9czGw47rgJM24vCsgDmI/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Count Type | select |  | required |
| 2 | Count Date | date |  | required |
| 3 | Room / Area | room | room |  |
| 4 | Package UID | metrc | metrc_uid | required; exactly 24 letters/numbers |
| 5 | Measured in | select |  |  |
| 6 | Expected Qty | number |  |  |
| 7 | Counted Qty | number |  |  |
| 8 | Variance | number |  |  |
| 9 | Counted By | employee | employee |  |
| 10 | Reviewed By | employee | employee |  |
| 11 | TAG MISSING? | bool |  |  |

### 4-A · Package Reconciliation

- Finding: 4 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1-wlabDHwaGl3qItoIvfXcsn9czGw47rgJM24vCsgDmI/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Reconciled On | date |  | required |
| 2 | Package UID (Metrc) | metrc | metrc_uid | required; exactly 24 letters/numbers |
| 3 | Expected Location | room | room |  |
| 4 | Actual Location | room | room |  |
| 5 | Expected Qty | text |  |  |
| 6 | Actual Qty | text |  |  |
| 7 | Adjustment Reason Entered | text |  |  |
| 8 | Entered by | employee | employee | NEW (not in sheet) |

## Production & Batch Records

### FR · Formulation Record

- Finding: none · Frequency: Per batch · Fields: Partial (only some headers mapped)
- Drive: https://docs.google.com/spreadsheets/d/1N5suxDU-ugzcv_B2tq2Vl8yV_8_McXG7Ig9feJEI6d4/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Batch # | batch | batch | required |
| 2 | Brand Description | text |  |  |
| 3 | Company | text |  |  |
| 4 | Order Quantity | text |  |  |
| 5 | PRD # / Work Order # | text |  |  |
| 6 | Document # | text |  |  |
| 7 | Document Revision # | text |  |  |
| 8 | Doc. Approval Date | date |  |  |
| 9 | Incubator Type | equipment | equipment |  |
| 10 | S/N | text |  |  |
| 11 | Sanitation Date | date |  |  |
| 12 | Set Temperature | text |  |  |

### 9-A BPR · Batch Production Record Log

- Finding: 9 · Frequency: Per batch · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1qO_g0SNgqNiImVFUiJ0kiCALvbilWlZIrVzL8vY0oEc/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Batch / lot # | batch | batch | required |
| 2 | Product name | text |  |  |
| 3 | MMP number / version | text |  |  |
| 4 | Production date(s) | text |  |  |
| 5 | Metrc package tags / cannabis IDs used | textarea |  |  |
| 6 | Equipment / line | equipment | equipment |  |
| 7 | Units packaged (expected) | number |  |  |
| 8 | Units packaged (actual) | number |  |  |
| 9 | Labels used (expected) | number |  |  |
| 10 | Labels used (actual) | number |  |  |
| 11 | Line cleared / equipment clean / scale checked / current MMP | bool |  |  |
| 12 | All steps complete and within limits | bool |  |  |
| 13 | Deviation? (Y/N) | yn |  |  |
| 14 | Deviations closed | bool |  |  |
| 15 | Lab COA passed | bool |  |  |
| 16 | Retained samples taken | bool |  |  |

### 5-A · Retained Samples

- Finding: 5 · Frequency: Per batch · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1UsCByLZujSw1HHXjwLC7brv9ivksy8VC3BabzRgVObw/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date Pulled | date |  | required |
| 2 | Package UID | metrc | metrc_uid | required; exactly 24 letters/numbers |
| 3 | Product Name | text |  |  |
| 4 | Lot / Batch # | batch | batch | required |
| 5 | Qty Retained | number |  |  |
| 6 | Storage Location | room | room |  |
| 7 | Expiration Date | date |  |  |
| 8 | Retain Until (Exp. + 30 Days) | date |  |  |
| 9 | Pulled By | employee | employee |  |

### LBL · Label Print Log

- Finding: none · Frequency: Per batch · Fields: **UNCONFIRMED (placeholder fields)**
- Drive: none found

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Batch # | batch | batch | required |
| 3 | Product | text |  |  |
| 4 | Label type | text |  |  |
| 5 | Labels printed (qty) | number |  |  |
| 6 | Printed by | employee | employee |  |
| 7 | Second-person proof check by | employee | employee |  |
| 8 | Notes | textarea |  |  |

## Ingredients & Suppliers

### 6-D · Supplier Ingredient Attestation

- Finding: 6 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1K1Ldv5JzNphPNJE6UYM66z1UhK-c9PYio8LrQXb_RFs/edit
- Note: Merged: the Apollo and Bodega Boyz 6-D tabs are one log here; Brand tells them apart.

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Brand | select |  | NEW (not in sheet) |
| 2 | Supplier Name | text |  | required |
| 3 | Ingredient Name | text |  | required |
| 4 | Product / Lot # | text |  |  |
| 5 | Date | date |  | required |
| 6 | The ingredient is botanically derived; no synthetic terpenes are included. | bool |  |  |
| 7 | The complete ingredient composition has been disclosed to Cannacure NY Inc. | bool |  |  |
| 8 | A current Certificate of Analysis (COA) is attached. | bool |  |  |
| 9 | A current Safety Data Sheet (SDS) is attached. | bool |  |  |
| 10 | Terpene source records are attached. | bool |  |  |
| 11 | The ingredient contains no polyethylene glycol, vitamin E acetate, MCT oil, medicinal compounds, illegal or controlled substances, artificial food coloring, benzoic acid, or diketones. | bool |  |  |
| 12 | To the supplier's knowledge, the ingredient meets the requirements of 9 NYCRR §123.6(f)(9) for use in vaporized or inhaled cannabis products. | bool |  |  |
| 13 | Supplier Signature | text |  |  |
| 14 | Printed Name / Title | text |  |  |
| 15 | Date: (supplier signed) | date |  |  |
| 16 | Compliance Lead Review | textarea |  |  |

### 6-E · Approved Ingredient List

- Finding: 6 · Frequency: Quarterly · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1K1Ldv5JzNphPNJE6UYM66z1UhK-c9PYio8LrQXb_RFs/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Ingredient Name | text |  | required |
| 2 | Supplier | text |  |  |
| 3 | Type | text |  |  |
| 4 | COA on File | bool |  |  |
| 5 | SDS on File | bool |  |  |
| 6 | Approval Date | date |  |  |
| 7 | Approved By | employee | employee |  |
| 8 | Status | select |  |  |
| 9 | product data sheet | url |  |  |
| 10 | COA | url |  |  |
| 11 | SDS | url |  |  |
| 12 | Notes | textarea |  |  |

### 6-C · Terpene Documentation & Ingredients

- Finding: 6 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1K1Ldv5JzNphPNJE6UYM66z1UhK-c9PYio8LrQXb_RFs/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Brand | text |  | required |
| 2 | Category | text |  |  |
| 3 | Product Description | text |  |  |
| 4 | Strain | text |  |  |
| 5 | Lot Number | text |  |  |
| 6 | Vendor | text |  |  |
| 7 | Vendor Description | text |  |  |
| 8 | Product Data Sheet | url |  |  |
| 9 | COA | url |  |  |
| 10 | SDS | url |  |  |
| 11 | Artificial Ingredients | text |  |  |

## Equipment, Calibration & Sanitation

### EQ-REG · Equipment Register

- Finding: none · Frequency: Monthly · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1g8LLhd05_2tbJ51l7CTnMSMTNUI991DLmQAAhMuqIPY/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Equipment ID | equipment | equipment | required |
| 2 | Equipment name | text |  | required |
| 3 | Type / category | text |  |  |
| 4 | Make | text |  |  |
| 5 | Model | text |  |  |
| 6 | Serial # | text |  |  |
| 7 | Vendor | text |  |  |
| 8 | Location / room | room | room |  |
| 9 | Product-contact (Y/N) | yn |  |  |
| 10 | In-service date | date |  |  |
| 11 | Status | select |  |  |
| 12 | Manual on file | bool |  |  |
| 13 | IQ/OQ / installation record | bool |  |  |
| 14 | Calibration SOP | bool |  |  |
| 15 | Calibration log current | bool |  |  |
| 16 | Cleaning SOP | bool |  |  |
| 17 | Cleaning log current | bool |  |  |
| 18 | Preventive maintenance schedule | bool |  |  |
| 19 | Maintenance log current | bool |  |  |
| 20 | Staff trained on equipment | bool |  |  |

### EQ-CM · Calibration & Maintenance

- Finding: none · Frequency: Per event · Fields: **UNCONFIRMED (placeholder fields)**
- Drive: none found

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Equipment ID | equipment | equipment | required |
| 3 | Activity | select |  |  |
| 4 | Description of work | textarea |  |  |
| 5 | Result | select |  |  |
| 6 | Performed by | employee | employee |  |
| 7 | Vendor / technician | text |  |  |
| 8 | Next due | date |  |  |
| 9 | Verified by | employee | employee |  |
| 10 | Notes | textarea |  |  |

### 3-C · Scale Calibration

- Finding: 3 · Frequency: Monthly · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1diWt_Iij5qKp_TMvM_6aijbqhCRGjBeDPkSvtc1eUEc/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Scale ID / Location | equipment | equipment | required |
| 3 | Calibration Weight Used | text |  |  |
| 4 | Pass / Fail | select |  | required |
| 5 | Calibrated By | employee | employee |  |
| 6 | Next Calibration Due | date |  |  |
| 7 | Signature | signature | employee | e-signature (performed) |
| 8 | QM sign-off | signature | employee | NEW (not in sheet); e-signature (approved), must differ from signature |

### BAL · Balance Check

- Finding: 3 · Frequency: Weekly · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1diWt_Iij5qKp_TMvM_6aijbqhCRGjBeDPkSvtc1eUEc/edit
- Note: One Drive tab per scale (e.g. "PB004 Ohaus Scout"); here the scale is a field.

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Scale | equipment | equipment | required; NEW (not in sheet) |
| 2 | Date / Time | datetime |  | required |
| 3 | Analyst | employee | employee |  |
| 4 | Weight Set ID | text |  |  |
| 5 | Zero | bool |  |  |
| 6 | Place 20g (19.8 - 20.2) | number |  |  |
| 7 | Add 2g (21.78 - 22.22) | number |  |  |
| 8 | Remove Weights (-0.1 - +0.1) | number |  |  |
| 9 | Place 100g (99.0 - 101.0) | number |  |  |
| 10 | Pass? | bool |  |  |
| 11 | Fail? | bool |  |  |
| 12 | Comments | text |  |  |

### CLN · Cleaning & Sanitation Log

- Finding: none · Frequency: Daily · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1Il5pCv_kjLuqgDfSD9wO6Bdoa4v7dyDcN_B2M7hEKns/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Time | time |  |  |
| 3 | Area / room / equipment | room | room | required |
| 4 | Task (clean / sanitize) | select |  |  |
| 5 | Agent / sanitizer used | text |  |  |
| 6 | Concentration / contact time | text |  |  |
| 7 | Completed (Y/N) | yn |  |  |
| 8 | Performed by | employee | employee |  |
| 9 | Signature | signature | employee | e-signature (performed) |
| 10 | Verified by | employee | employee |  |
| 11 | Verification date / signature | signature | employee | e-signature (verified), must differ from signature |
| 12 | Notes | textarea |  |  |

### T&H · Temperature & Humidity Log

- Finding: none · Frequency: Daily · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1khWawaO_YdVW7smiKOlJs7IIFtkrzIyUioKMI52EqDE/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Time | time |  |  |
| 3 | Area / room | room | room | required |
| 4 | Temperature (°F) | number |  |  |
| 5 | Humidity (% RH) | number |  |  |
| 6 | In range (Y/N) | yn |  |  |
| 7 | Corrective action | text |  |  |
| 8 | Recorded by | employee | employee |  |
| 9 | Signature | signature | employee | e-signature (performed) |
| 10 | Notes | textarea |  |  |

### PEST · Pest Control Log

- Finding: none · Frequency: Monthly · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1VB2cWxuQafzCp1EPEdzC0hkZ5yc2QBqmdvZeR3LAsA0/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Time | time |  |  |
| 3 | Area / room | room | room |  |
| 4 | Activity (inspection / trap check / treatment / exclusion / sighting) | select |  | required |
| 5 | Pest type / evidence | text |  |  |
| 6 | Trap ID | text |  |  |
| 7 | Product / pesticide used | text |  |  |
| 8 | EPA reg. # | text |  |  |
| 9 | Quantity used | text |  |  |
| 10 | Vendor / technician | text |  |  |
| 11 | Follow-up needed | text |  |  |
| 12 | Next scheduled service | date |  |  |
| 13 | Recorded by | employee | employee |  |
| 14 | Signature | signature | employee | e-signature (performed) |
| 15 | Notes | textarea |  |  |

## Personnel & Training

### ONB · New-Hire Onboarding Checklist (RWT Gate)

- Finding: 11 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/document/d/1oWgurZ7VFInVW5A9tHcYCWz77STpylBYJ6_ilWkU-QM/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Employee name | employee | employee | required |
| 2 | Job title / role | text |  |  |
| 3 | Hire date | date |  | required |
| 4 | Scheduled first shift (date) | date |  |  |
| 5 | Supervisor | employee | employee |  |
| 6 | Work area(s) | text |  |  |
| 7 | 1. RWT completion date(s) | text |  |  |
| 8 | 2. Training verification form submission date / confirmation | text |  |  |
| 9 | 3. Attestation date | date |  |  |
| 10 | 4. Date entered in Metrc | date |  |  |
| 11 | 5. Date entered in payroll | date |  |  |
| 12 | 6. Date added to Monthly Roster Reconciliation Log | date |  |  |
| 13 | Compliance Lead sign-off | signature | employee | e-signature (approved) |
| 14 | First shift date actually worked | date |  |  |

### RWT-R · Monthly Roster Reconciliation (RWT / Payroll / Metrc)

- Finding: 11 · Frequency: Monthly · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1AUGaDHWNaC4CBDAXsDL8BZavJPcX2-tMCarX9Yv8GXk/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Employee name | employee | employee | required |
| 2 | Payroll active (Y/N) | yn |  |  |
| 3 | Metrc listed (Y/N) | yn |  |  |
| 4 | RWT complete date | date |  |  |
| 5 | Attestation on file (Y/N) | yn |  |  |
| 6 | Discrepancies | text |  |  |
| 7 | Action taken / required | text |  |  |
| 8 | Reviewer | employee | employee |  |
| 9 | Review date | date |  | required |
| 10 | Source / notes | textarea |  |  |

### RWT-A · RWT Attestation of Completion

- Finding: 11 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1xWuS0NW1b9QNQ0jyjhDuJzTeVtd3B5OgBvHLQeBWAvQ/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Employee name (print) | employee | employee | required |
| 2 | Job title | text |  |  |
| 3 | Hire date | date |  |  |
| 4 | First shift date | date |  |  |
| 5 | Cannabis Product Safety and Responsibility — date completed | date |  |  |
| 6 | Cannabis Product Safety and Responsibility — employee initials | text |  |  |
| 7 | Cannabis Workforce Responsibility — date completed | date |  |  |
| 8 | Cannabis Workforce Responsibility — employee initials | text |  |  |
| 9 | Licensee-provided training (min. 2 hours) — date completed | date |  |  |
| 10 | Licensee-provided training — employee initials | text |  |  |
| 11 | Implicit bias or cultural competency training (min. 1 hour) — provider | text |  |  |
| 12 | Implicit bias or cultural competency training — date completed | date |  |  |
| 13 | Implicit bias or cultural competency training — employee initials | text |  |  |
| 14 | Training verification form submitted (cannabis.ny.gov/workforce) — date | date |  |  |
| 15 | OCM confirmation saved to file (Y/N) | yn |  |  |
| 16 | Employee attestation — print | text |  |  |
| 17 | Employee attestation — signature | text |  |  |
| 18 | Employee attestation — date | date |  |  |
| 19 | Licensee verification — Compliance Lead | employee | employee |  |
| 20 | Licensee verification — signature | signature | employee | e-signature (approved) |
| 21 | Licensee verification — date | date |  |  |
| 22 | Personnel file / notes | textarea |  |  |

### T-1 · SOP Training Sign-off

- Finding: T-1 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1vIynb0OwH3lLBwpRECYXlBwWrcffYnRZF0HsNXdbGxA/edit
- Note: One Drive tab per SOP (e.g. "T-1 SOP-INV-02", "T-1 SOP-REC-01").

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | SOP Number | text |  | required |
| 2 | Title | text |  |  |
| 3 | Effective Date | date |  |  |
| 4 | Trainer | employee | employee |  |
| 5 | Employee Name | employee | employee | required |
| 6 | Position | text |  |  |
| 7 | Signature | signature | employee | e-signature (completed) |
| 8 | Date | date |  | required |

### T-1 SI · Training Sign-In

- Finding: T-1 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1qO_g0SNgqNiImVFUiJ0kiCALvbilWlZIrVzL8vY0oEc/edit
- Note: Same layout on the "T-1 7/8/9/10 Training Sign-In" tabs of Findings 7-10 and the Finding 13 ergonomics sign-in.

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Document(s) trained on | text |  | required |
| 2 | Version / effective date | text |  |  |
| 3 | Training date | date |  | required |
| 4 | Trainer | employee | employee |  |
| 5 | Location | room | room |  |
| 6 | Employee name | employee | employee | required |
| 7 | Position | text |  |  |
| 8 | Signature | signature | employee | e-signature (completed) |
| 9 | Date | date |  |  |
| 10 | Trainer initials | text |  |  |
| 11 | Notes | textarea |  |  |

### ROT · Daily Rotation Record

- Finding: 13 · Frequency: Daily · Fields: Confirmed from Drive
- Drive: https://docs.google.com/document/d/1SX14pqQuDXWuMZCFmhS6pUitV6zJ8HS3XJLp1iYkAgY/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Employee | employee | employee | required |
| 3 | 08:00–10:00 | text |  |  |
| 4 | 10:00–12:00 | text |  |  |
| 5 | 12:30–14:30 | text |  |  |
| 6 | 14:30–16:30 | text |  |  |
| 7 | Supervisor initials | text |  |  |

### 13-B · Repetitive Motion Hazard Analysis

- Finding: 13 · Frequency: Annual · Fields: Confirmed from Drive
- Drive: https://docs.google.com/document/d/1f622g4MjANXoDtz2-OYzXtmtmeGQLXCf7l06G8cfYy4/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Task | text |  | required |
| 2 | Description of motion | textarea |  |  |
| 3 | Hazard / potential injury | textarea |  |  |
| 4 | Risk level | select |  |  |
| 5 | Controls | textarea |  |  |
| 6 | Responsible person | employee | employee |  |
| 7 | Review frequency | text |  |  |

## Safety, Security & Facility

### VIS · Visitor Log

- Finding: none · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1IKzI9RjIKLQk4eMpqIiI5R6zGNlaSw9-reRrygxFpX0/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Visitor full name | text |  | required |
| 3 | Company | text |  | required |
| 4 | Time of arrival | time |  | required |
| 5 | Time of departure | time |  |  |
| 6 | Purpose of the visit | text |  | required |
| 7 | Areas accessed | text |  |  |
| 8 | Escort name | employee | employee |  |
| 9 | ID checked (Y/N) | yn |  |  |
| 10 | Badge # | text |  |  |
| 11 | Visitor signature | text |  |  |
| 12 | Notes | textarea |  |  |

### 12-A · Monthly Safety Signage Inspection

- Finding: 12 · Frequency: Monthly · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1jxQmwNeJC-DjuE2NszP8QqE2H7ToAV64Mg1uEoXz1sM/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Location | room | room | required |
| 2 | Sign | text |  | required |
| 3 | Source (purchase list item) | text |  |  |
| 4 | Present (Y/N) | yn |  |  |
| 5 | Legible & undamaged (Y/N) | yn |  |  |
| 6 | Action needed (replace / repost / none) | select |  |  |
| 7 | Action completed (date) | date |  |  |
| 8 | Inspector | employee | employee |  |
| 9 | Inspection date | date |  | required |

### 12-B · Signage Photo Log

- Finding: 12 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/document/d/1llWSW5fPEhYqzKFm8hnlDpdpcUJd8pEAswhRKRBKWQ8/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Sign | text |  | required |
| 2 | Location (room # per floor plan) | room | room | required |
| 3 | Photo filename | text |  |  |
| 4 | Date posted | date |  | required |
| 5 | Posted by | employee | employee |  |
| 6 | Photo | file |  | NEW (not in sheet) |

### 14-A · Site Plan Room Legend

- Finding: 14 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1mfNQGi3klvlz5qAPo85v4VNQJ9rh9w_U6mr5H_d51kM/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Room # | room | room | required |
| 2 | Area shown on plan (m²) | text |  |  |
| 3 | Room name / use | text |  |  |
| 4 | Metrc location name (confirmed) | text |  |  |
| 5 | Possible Metrc match from Distru (suggestion only) | text |  |  |
| 6 | Limited access (Y/N) | yn |  |  |
| 7 | Cameras (per Floorplan+Security_Cam) | text |  |  |
| 8 | Fire extinguishers | text |  |  |
| 9 | Exits | text |  |  |
| 10 | Flags / notes for Andy | textarea |  |  |

### HAZ · Hazardous Materials Register

- Finding: none · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1eE5A5XtJmD9dDJW6GQgFc-OpSEBYWT94amWb2D7K4nE/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Item # | text |  | required |
| 2 | Material / Product name | text |  | required |
| 3 | Hazard category | text |  |  |
| 4 | GHS pictograms / signal word | text |  |  |
| 5 | Where used | text |  |  |
| 6 | Storage location | room | room |  |
| 7 | Quantity on hand / unit | text |  |  |
| 8 | Vendor | text |  |  |
| 9 | Vendor product / SKU | text |  |  |
| 10 | SDS on file | bool |  |  |
| 11 | SDS reviewed <3 yrs | bool |  |  |
| 12 | COA / spec sheet | bool |  |  |
| 13 | Label / secondary container label | bool |  |  |
| 14 | Chemical inventory entry | bool |  |  |
| 15 | Staff HazCom training | bool |  |  |
| 16 | Spill / disposal procedure | bool |  |  |
| 17 | Locked / segregated storage confirmed | bool |  |  |
| 18 | SDS link | url |  |  |
| 19 | Date SDS received | date |  |  |
| 20 | Source document(s) (evidence) | textarea |  |  |

### CHEM · Chemical Inventory

- Finding: none · Frequency: Monthly · Fields: Partial (only some headers mapped)
- Drive: https://docs.google.com/spreadsheets/d/1XwfTVS4oUpM-64kEwcMP0HAYOz3W2povT-6ycyl06dY/edit
- Note: Headers from the "Simple View" tab; the "Inventory" tab has more SDS detail not mapped yet.

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | ID | text |  | required |
| 2 | Product | text |  | required |
| 3 | Where used | text |  |  |
| 4 | Hazard (plain words) | textarea |  |  |
| 5 | Storage rule | textarea |  |  |
| 6 | SDS | text |  |  |

### SEC · Security Equipment Test

- Finding: none · Frequency: Monthly · Fields: **UNCONFIRMED (placeholder fields)**
- Drive: none found

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Equipment / system | select |  | required |
| 3 | Location | room | room |  |
| 4 | Test performed | text |  |  |
| 5 | Result | select |  | required |
| 6 | Issue found | text |  |  |
| 7 | Corrective action | text |  |  |
| 8 | Tested by | employee | employee |  |
| 9 | Notes | textarea |  |  |

## Waste, Deviations & Recalls

### 1-A · Destruction Log

- Finding: 1 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1knTYrWIPozHEpXpVJT5SGkzG-9OCJSRoaIvty3o8E3k/edit
- Note: Sheet column "Package Tag" is renamed "Metrc tag" (it holds the Metrc package UID).

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Item | text |  | required |
| 3 | Metrc tag | metrc | metrc_uid | required; renamed from "Package Tag"; exactly 24 letters/numbers |
| 4 | Weight | number |  | required |
| 5 | Unit | select |  | required; NEW (not in sheet) |
| 6 | Reason | text |  | required |
| 7 | Method | select |  | NEW (not in sheet) |
| 8 | Performed by | employee | employee | required; NEW (not in sheet) |
| 9 | Witness | employee | employee | required; NEW (not in sheet) |
| 10 | Performed by signature | signature | employee | NEW (not in sheet); e-signature (performed) |
| 11 | Witness signature | signature | employee | NEW (not in sheet); e-signature (verified), must differ from performed_by_signature |
| 12 | Photo | file |  | NEW (not in sheet) |

### WST · Waste Log (non-cannabis)

- Finding: none · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1j7oPOn4rXmmd0aMJvAqK7jb2qtjSnx51M_h30Jm5nLc/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Time | time |  |  |
| 3 | Waste type (trash / recyclable / used PPE / hazardous non-cannabis / other) | select |  | required |
| 4 | Description | text |  |  |
| 5 | Quantity / weight | text |  |  |
| 6 | Unit | text |  |  |
| 7 | Container / location | room | room |  |
| 8 | Disposal method | text |  |  |
| 9 | Hauler / vendor | text |  |  |
| 10 | Manifest or ticket # | text |  |  |
| 11 | Handled by | employee | employee |  |
| 12 | Signature | signature | employee | e-signature (performed) |
| 13 | Notes | textarea |  |  |

### 9-A DEV · Deviation Log

- Finding: 9 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1qO_g0SNgqNiImVFUiJ0kiCALvbilWlZIrVzL8vY0oEc/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date / time | datetime |  | required |
| 2 | Batch / lot # | batch | batch |  |
| 3 | MMP step # | text |  |  |
| 4 | What happened | textarea |  | required |
| 5 | Investigation / root cause | textarea |  |  |
| 6 | Impact assessment | textarea |  |  |
| 7 | Action taken / disposition | textarea |  |  |
| 8 | Initials | text |  |  |
| 9 | Reviewed by (QC Reviewer: Leo) | employee | employee |  |
| 10 | Closed | bool |  |  |
| 11 | Notes | textarea |  |  |

### CAPA · CAPA Log

- Finding: none · Frequency: Per event · Fields: **UNCONFIRMED (placeholder fields)**
- Drive: none found

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date opened | date |  | required |
| 2 | Source | select |  | required |
| 3 | Batch / lot # | batch | batch |  |
| 4 | Problem description | textarea |  | required |
| 5 | Root cause | textarea |  |  |
| 6 | Corrective action | textarea |  |  |
| 7 | Preventive action | textarea |  |  |
| 8 | Owner | employee | employee |  |
| 9 | Due date | date |  |  |
| 10 | Effectiveness check | textarea |  |  |
| 11 | Closed by | employee | employee |  |
| 12 | Date closed | date |  |  |

### CMP · Complaint Log

- Finding: none · Frequency: Per event · Fields: **UNCONFIRMED (placeholder fields)**
- Drive: none found

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date received | date |  | required |
| 2 | Complainant / source | text |  | required |
| 3 | Product name | text |  |  |
| 4 | Batch / lot # | batch | batch |  |
| 5 | Package UID | metrc | metrc_uid | exactly 24 letters/numbers |
| 6 | Complaint description | textarea |  | required |
| 7 | Adverse event (Y/N) | yn |  |  |
| 8 | Investigation | textarea |  |  |
| 9 | Outcome / response | textarea |  |  |
| 10 | Reported to OCM (Y/N) | yn |  |  |
| 11 | Handled by | employee | employee |  |
| 12 | Date closed | date |  |  |

### RCL · Mock Recall

- Finding: none · Frequency: Annual · Fields: **UNCONFIRMED (placeholder fields)**
- Drive: none found

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date | date |  | required |
| 2 | Product | text |  | required |
| 3 | Batch / lot # | batch | batch | required |
| 4 | Package UID | metrc | metrc_uid | exactly 24 letters/numbers |
| 5 | Units distributed | number |  |  |
| 6 | Units located | number |  |  |
| 7 | Time to complete (hours) | number |  |  |
| 8 | Gaps found | textarea |  |  |
| 9 | Corrective actions | textarea |  |  |
| 10 | Performed by | employee | employee |  |
| 11 | Reviewed by | employee | employee |  |

## Quality System & Document Control (managers only)

### 7-A · PQP Tracker

- Finding: 7 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1sYiDidT09yYeUyV7Vp7QjVIpipGQR1T9tOYEhhHS2-M/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Product form | text |  | required |
| 2 | Source of product form | text |  |  |
| 3 | Confirmed by Andy? | select |  |  |
| 4 | Products covered | textarea |  |  |
| 5 | PQP number / version | text |  |  |
| 6 | Drafted by | employee | employee |  |
| 7 | Whole process mapped | bool |  |  |
| 8 | P/B/M/C hazards per step | bool |  |  |
| 9 | Preventive measures + criteria | bool |  |  |
| 10 | Effectiveness checks | bool |  |  |
| 11 | Corrective actions | bool |  |  |
| 12 | Approved ingredient list | bool |  |  |
| 13 | Approved by (QC Reviewer: Leo) | employee | employee |  |
| 14 | Approval date | date |  |  |
| 15 | Effective date | date |  |  |
| 16 | Next annual review | date |  |  |

### 8-A · MMP Register

- Finding: 8 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1lhrx4VO8DMp3N2QKaELtm4xmou_zv0TlpRXVKpMI7XE/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Product name | text |  | required |
| 2 | Product form | text |  |  |
| 3 | Why listed (source) | text |  |  |
| 4 | Confirmed by Andy? | select |  |  |
| 5 | Target date (per CAP) | date |  |  |
| 6 | Related PQP # | text |  |  |
| 7 | MMP number / version | text |  |  |
| 8 | Drafted by | employee | employee |  |
| 9 | Name + intended cannabinoid content | bool |  |  |
| 10 | Ingredients with weights | bool |  |  |
| 11 | Step instructions + equipment + in-process checks | bool |  |  |
| 12 | Expected yield + range + deviation thresholds | bool |  |  |
| 13 | Packaging description | bool |  |  |
| 14 | Representative label | bool |  |  |
| 15 | PQP risk mitigations mapped | bool |  |  |
| 16 | Approved by (QC Reviewer: Leo) | employee | employee |  |
| 17 | Approval date | date |  |  |
| 18 | Effective date | date |  |  |
| 19 | Staff trained (T-1) | bool |  |  |
| 20 | MMP link | url |  |  |

### 9-A AUD · Monthly BPR Completeness Audit

- Finding: 9 · Frequency: Monthly · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1qO_g0SNgqNiImVFUiJ0kiCALvbilWlZIrVzL8vY0oEc/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Audit month | text |  | required |
| 2 | Batch / lot # | batch | batch | required |
| 3 | Product name | text |  |  |
| 4 | MMP # / version + each step | bool |  |  |
| 5 | Lot #, Metrc tags, cannabis IDs | bool |  |  |
| 6 | Equipment / lines + cleaning status | bool |  |  |
| 7 | Ingredient identity, lot, weight | bool |  |  |
| 8 | PQP monitoring results | bool |  |  |
| 9 | Date, time, doer + verifier initials | bool |  |  |
| 10 | Actual / representative label | bool |  |  |
| 11 | Packaging & label reconciliation | bool |  |  |
| 12 | Deviations investigated | bool |  |  |
| 13 | QC approve / reject signed + dated | bool |  |  |
| 14 | Batch-history checklist complete | bool |  |  |
| 15 | Missing items | textarea |  |  |
| 16 | Corrective action | textarea |  |  |

### 10-A · Master List of Controlled Documents

- Finding: 10 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1-66ua2JC3fDjdrzgkMZIe1MePY81mIwaJsKMS5uKa7g/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Doc ID | text |  | required |
| 2 | Document title | text |  | required |
| 3 | Type | text |  |  |
| 4 | Product form | text |  |  |
| 5 | Version | text |  |  |
| 6 | Effective date | date |  |  |
| 7 | Approved by | employee | employee |  |
| 8 | Electronic location (link) | url |  |  |
| 9 | Binder location (production area) | room | room |  |
| 10 | Superseded copies removed | bool |  |  |
| 11 | Next review date | date |  |  |
| 12 | Notes | textarea |  |  |

### 10-B · Production-Area Binder Check

- Finding: 10 · Frequency: Monthly · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1-66ua2JC3fDjdrzgkMZIe1MePY81mIwaJsKMS5uKa7g/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Check month | text |  | required |
| 2 | Production area / room | room | room | required |
| 3 | Document (Doc ID) | text |  |  |
| 4 | Version in binder | text |  |  |
| 5 | Current approved version | text |  |  |
| 6 | Matches current | bool |  |  |
| 7 | Superseded copy removed | bool |  |  |
| 8 | Every completed batch has a filed BPR | bool |  |  |
| 9 | Checked by | employee | employee |  |
| 10 | Check date | date |  | required |
| 11 | Notes | textarea |  |  |

### 10-C · Records Request Log

- Finding: 10 · Frequency: Per event · Fields: Confirmed from Drive
- Drive: https://docs.google.com/spreadsheets/d/1-66ua2JC3fDjdrzgkMZIe1MePY81mIwaJsKMS5uKa7g/edit

| # | Header (form label) | Type | Link key | Notes |
|---|---|---|---|---|
| 1 | Date / time request received | datetime |  | required |
| 2 | Requested by | text |  | required |
| 3 | Records requested | textarea |  | required |
| 4 | Date / time provided | datetime |  |  |
| 5 | Within one business day | bool |  |  |
| 6 | Provided by | employee | employee |  |
| 7 | Method (email / portal / on site) | select |  |  |
| 8 | Notes | textarea |  |  |

## Nov 19, 2026 — retired / merged (Andy)

- **LBL Label Print Log** — retired (duplicate: the Labels tab keeps the print record).
- **WST Waste Log (non-cannabis)** — retired; **1-A Destruction Log** gained a required *Waste type* (Cannabis / Chemical / hazardous / Other). Metrc tag is required only for cannabis waste.
- **CAPA Log** — retired; merged into the **Deviations** tab (Source + reference, Preventive action, Effectiveness check). CMP entries link to "Open a deviation / CAPA" pre-filled with the complaint.
- Retired logs keep their definition and any past entries (inactive, readable by admins/supervisors at /logs/{code}); the hub now lists 45 logs.
- Checklists templates retired as duplicates: daily_room_clean, weekly_deep_clean (→ CLN), scale_check_daily (→ BAL; the production scale gate now needs today's BAL entry for each scale), monthly_security_test (→ SEC), monthly_calibration_review (→ EQ-CM). Kept: changeover_clean (Production step 2), weekly_equipment, daily_security_walk.
