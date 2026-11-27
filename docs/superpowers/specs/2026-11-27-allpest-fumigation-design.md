# All Pest Fumigation Application — Design

Date: 2026-11-27 · Status: draft for approval · Platform: Nuvis StudioX (one install for All Pest) · Invoicing: Nuvis ERPX

Notation: **[Decided]** = the owner decided or stated it. **[My call]** = proposed by the designer for the owner to correct. **[Builder]** = left to whoever plans and builds.

## 1. Intent and why

All Pest Hygiene Solutions (Fiji) does methyl bromide fumigations for export cargo. Everything is manual today: requests, Records of Fumigation (ROF), certificates, gas stock, equipment records and invoices. The owner wants one application that:

- takes customer requests from a public link, with no login;
- guides technicians through each fumigation, checking readings against the published standards and doing the arithmetic;
- issues certificates automatically once signed and passed;
- raises the invoice in Nuvis ERPX;
- records **every technician activity** so the company can be audited under AFAS by the Biosecurity Authority of Fiji (BAF) and shown in a joint system review.

Phase 2 extends the same application to All Pest's **pest control and hygiene division**, so the base must not be fumigation-only. **[Decided]**

Reference documents (Australian Department of Agriculture, Fisheries and Forestry): *Methyl bromide fumigation methodology v3.0* (the "methodology"), and the fact sheets *How to complete a Record of Fumigation* and *How to complete an MB fumigation certificate*. v3.0 applies to fumigations starting on or after 1 May 2025.

## 2. Goals, non-goals, anti-goals

**Goals**
- Replace the manual process end to end: request → job → ROF → certificate → invoice.
- Make a non-compliant reading impossible to miss, and compliant arithmetic automatic.
- Provide a complete, tamper-evident record per treatment that can be handed to an auditor.
- Keep the fumigation rules in tested code so they can be shown to be correct.

**Non-goals (this phase)**
- Pest control and hygiene job types (phase 2; the base must allow them).
- BMSB-specific certificate and seasonal measures. **[My call]**
- Automatic upload to the Australian certificate portal that AFAS providers use; staff upload the generated ROF and certificate to it manually. **[Decided]**
- Customer login to the StudioX app. Customers see invoices in the ERPX portal only.
- Any login for the supervising Biosecurity officer.

**Anti-goals** (a failure even if it technically works)
- A certificate reaching a customer without a pass result and both signatures.
- A ROF that can be silently edited after signing.
- Prices maintained in two places.
- Uploaded files that quickly exhaust the shared hosting disk.

## 3. Constraints

- StudioX, PHP 8.1+/MySQL, hosted on A2 Hosting shared hosting; no hosting upgrade for this project; Redis is not available. **[Decided]**
- Installed users use UUID ids (never cast to integers). Exports ship with blank email configuration. **[Decided, from StudioX]**
- ERPX never stores PDFs on its server; documents are generated on demand. **[Decided]**
- ERPX price list is the only source of prices; All Pest keeps at most a read-only cache. **[Decided]**
- The supervising officer signs by drawing on the technician's tablet; they have no account. **[Decided]**
- All uploads are compressed before storage to save space. **[Decided]**
- Records must be kept at least two years (methodology 12.1) and at least 5 years under AFAS/BAF requirements. Failed treatments are recorded too.
- Times are stored in UTC and shown in Fiji time (UTC+12). **[My call]**

## 4. Approaches considered

| | Approach | Trade-off |
|---|---|---|
| A | Low-code only in the form builder | Fastest; rules end up as untestable code strings in form metadata; poor audit story; hard to reuse |
| **B** | **Plugin/module with a tested rules engine; StudioX forms and workflow for simple parts** | **More up-front code; tested, versioned, reusable for phase 2** |
| C | A first-class module inside the StudioX repo | Best tablet UX and ships via the updater; puts one customer's rules in the shared platform |

**B chosen by the owner.** Risk: the existing plugin mechanism looks thin (the sample plugin exposes only an `init` callable). If it cannot register screens, APIs and menus, fall back to C's packaging (a module in the StudioX tree) while keeping the same code layout, and tell the owner. **[Builder to verify first]**

## 5. The design

### 5.1 Actors
- **Customer** (public, no login): lodges a request and uploads bills of lading; later views invoices in the ERPX portal and receives certificates by email.
- **Admin** (All Pest office): reviews requests, schedules treatments, assigns technician and supervising officer, maintains technicians, equipment, schedules and stock, releases invoices when review mode is on.
- **Technician** (logged in, tablet): performs treatments, enters readings, uploads photos, signs as fumigator-in-charge.
- **Supervising Biosecurity officer** (no account): signs on the technician's tablet.
- **Auditor** (BAF, no live access): receives an exported audit pack.

### 5.2 Core flow
```
Public request form (+BOL uploads)
  → Admin review: confirm, set service type, schedule date/time, assign technician + supervising officer
  → Treatment (one per sheeted enclosure / shipment)
      → Pre-checks: schedule chosen, forecast temperature fetched, equipment chosen, dose calculated
      → Dosing → distribution readings → start point → exposure readings → (top-up) → end readings
      → Ventilation + final TLV readings per container
      → Result per container: pass / fail
      → Fumigator e-signs; supervising officer signs on tablet
  → Pass + both signatures → certificate generated and emailed
  → All containers resolved → invoice sent to ERPX
```

### 5.3 Data model (logical)
- **Request** → many **Consignments** (a request may include several containers; each has a bill of lading, container number, seal number, commodity, origin, destination, quantity).
- **Treatment** = one fumigation of one enclosure. It holds the shared ROF fields and has many **Containers** (the consignments it covers). A request can have several treatments if, for example, containers are done on different days. **[My call]**
- **ROF** = the treatment's record, type *General* or *Perishable*. Structure follows the fact sheet: Section A fumigator, B job, C fumigation details and dose calculation, D concentration readings, E declaration. Readings are per container, per monitoring location, per stage.
- **Reading set**: stage (distribution, start point, interval, end, post-top-up), time recorded (time the last reading in the set is completed), initials of who took them, per-location values exactly as displayed, photo(s).
- **Certificate**: one per passing container **[Decided]**, with a unique sequential number from a non-reusable log. A failed container gets none.
- **Treatment Schedule** (library, admin-maintained): name, type (General/Perishable), dose rate g/m³, exposure hours, minimum temperature, dose compensation allowed, top-ups allowed, retention class (70% standard / 80% per Table 5), notes. Entered and updated manually by the admin; not seeded. **[Decided]** The standards tables themselves are built in and are not user-editable.
- **Technician**: name, registration/accreditation number and expiry, uploaded certificates, signature image, status.
- **Equipment item**: type, serial number, status (in service / in repair / discarded), calibration or audit due date, expiry (stain tubes), and a **log** of audits, discards, replacements.
- **Gas stock**: lots/cylinders/cans received (quantity, chloropicrin %), movements (receipt, use by ROF, stocktake adjustment with reason), balance.
- **Invoice link**: ERPX invoice id and number for each request, status of sync.

### 5.4 Rules engine (the tested core)
All figures come from methodology v3.0. Implemented as pure functions with unit tests whose expected values are the published Tables 2, 3, 4, 5 and the Table 6 ready reckoner.

1. **Forecast temperature (ambient, 6.1).** Fetch the hourly forecast for the site coordinates; use the lowest forecast minimum over the exposure window; store the source, time retrieved, and hourly values. Must be sourced no earlier than the day before the exposure starts. Window = dosing time to scheduled start + exposure hours + 2 h. **[Decided: lowest minimum across the 24 h window; the 2 h margin is My call]** If the forecast minimum is 10 °C or below, an ambient treatment is blocked and the controlled-temperature path is required. Technician may override with a manually entered figure and source note. Re-check if the real start point slips beyond a set margin.
2. **Dose compensation (8.1).** If the schedule allows it: add 8 g/m³ for each 5 °C, or part thereof, below 21 °C.
3. **Dose (8.2).** Volume (m³) = external height × depth × length for sheeted enclosures (internal for chambers). Dose (g) = volume × dose rate used. With 2 % chloropicrin supply: ÷ 0.98. Round up to the next increment the equipment can measure, or the next full can. The working is shown and stored.
4. **Equilibrium (8.5).** (highest − lowest) ÷ lowest × 100 ≤ 15 %. Not required again once achieved, and not required after top-ups (10.3).
5. **Start point (8.6).** All readings ≥ the retention standard for the elapsed time since injection (15–30 min 85 %, 30 min–1 h 75 %, > 1 h 70 %; 80 % class per Table 5) **and** equilibrium achieved. If more gas is added before the start point, the injection time resets. Exposure is timed from the start point; planned end time computed.
6. **Standards during exposure (Tables 3/4).** Standard = initial dose × retention % for the hour *preceding* the reading (a 2½ h reading uses the 2 h standard); minimum-to-allow-top-up = standard − 5 (doses 32–56) or − 8 (doses 64–152); maximum top-up = standard + 5 or + 8.
7. **Alerts.** Any reading below standard is flagged immediately on entry, with the standard, and whether a top-up is permitted (reading ≥ minimum-to-allow-top-up and schedule allows it).
8. **Top-up (10).** Amount (g) = volume × (maximum top-up − lowest reading), with the chloropicrin and rounding rules; records time and the post-top-up reading from the lowest location. During exposure: multiple allowed, no extension. At end of exposure: one only, minimum 4 h extension (or as the schedule states), then all readings must meet the original standard or the treatment fails.
9. **Reading frequency (9.1).** Start and end of exposure plus every 24 h when exposure exceeds 24 h; fans off before readings; warn when a reading set is overdue.
10. **Monitoring locations (5.3/5.4).** General: < 30 m³ one location (top-centre), ≥ 30 m³ three locations; **each sea container in a sheeted stack needs at least three**. Perishables: < 5 m³ one, ≥ 5 m³ three (more if mixed packaging types). Import conditions may require more. The form generates the required number of reading fields per container.
11. **Perishables (6.3).** Dose is based on the schedule's core temperature; core/pulp temperature readings required at the same positions as the gas locations, and must be at or above the schedule temperature before dosing; no temperature compensation unless the schedule allows it.
12. **Single failed container (9.2).** Within a sheeted stack of sea containers, one container may fail and the rest pass if its readings fell below standard and a compliant top-up was not or could not be done and all others stayed above standard. The app computes result per container and applies this rule; any other shortfall fails the whole treatment.
13. **Ventilation (11).** TLV 5 ppm unless lower is imposed; each container checked individually; if the first reading is below 5 ppm only that one is needed; TLV instrument must read to at least 1 ppm. Records ventilation start time and each TLV reading with time. A consignment is released from the fumigator's control only after TLV is verified, including for failed treatments.

### 5.5 Signing and certificate release
- **Fumigator-in-charge:** signs the ROF and certificate after re-entering password (or MFA code). Only the named fumigator-in-charge, with an unexpired registration, can sign.
- **Supervising officer:** types name and designation and draws signature on the technician's tablet, on each ROF and each certificate. Stored with timestamp, technician session, and device.
- **Release rule:** certificate is emailed to the customer when (a) the container's result is pass, (b) fumigator has signed, (c) officer has signed, and (d) if the optional admin verification is switched on, the admin has verified. Otherwise the treatment shows which step is outstanding and reminders go to the responsible person.
- Failed containers produce a failed ROF and no certificate.
- **After signing, the ROF is read-only.** A correction is a new version with a reason; the original stays visible and the audit trail links them.
- **Certificate fields:** follow the fact sheet (letterhead, issue date, treatment provider ID, unique certificate number, consignment link, seals, client, commodity, origin, destination, quantity, target, enclosure, schedule vs applied dose/exposure/temperature, place, start/end times, final TLV reading, declaration, signatures). Certificates are generated at send time as PDF attachments.
- **Documents are generated on demand, not stored as files [My call]**, consistent with the ERPX rule and to save space. The signed data, signature images, readings and a hash of the issued certificate content are the retained record.

### 5.6 Invoicing into ERPX
- All Pest is its own tenant in ERPX. StudioX holds an ERPX API key with product-read, invoice-write and customer-write scopes. **[Builder]**
- **One invoice per request, one line per container**, whether the container passed or failed. **[Decided]** Raised when every treatment of the request is resolved.
- **Prices:** each service type maps to an ERPX product code; unit price is read from ERPX `selling_price` at invoice time. Local cache is read-only. **[Decided]**
- **Customer record:** ERPX has no customer endpoint, so add a small endpoint that finds a customer by email or TIN or creates one, and returns the party id. **[Builder, change in ERPX]**
- **Idempotency:** send the request number as a unique reference so a retry cannot duplicate; if ERPX is unreachable, queue and retry and show "invoice pending". The invoice API's random invoice number is not relied on. **[Builder, change in ERPX]**
- **Release mode per install [Decided]:** *auto* (final invoice created immediately) or *reviewed* (arrives as draft; admin verifies before the customer sees it in the ERPX portal). Default: reviewed. **[My call]**

### 5.7 Equipment, technicians, gas stock
- **Technicians:** alert 30 days before registration expiry; expired registration blocks assignment and signing. **[My call]**
- **Equipment:** ROF equipment picker offers only in-service items not overdue for audit/calibration; ROF records the last four digits of each monitoring device serial (methodology fact sheet), and warns on expired stain tubes. Discard/replacement keeps history.
- **Gas stock:** each ROF deducts the amount applied (including top-ups and failed treatments). Stocktake adjustments require a reason. Low-stock alert and usage report by period. **[My call]**

### 5.8 Uploads and compression **[Decided; details My call]**
- **Images** (reading photos, BOL photos, equipment photos): compressed in the browser before upload to a maximum long edge of about 1600 px at about 75 % quality, with a server-side re-encode as a fallback so nothing oversized is stored.
- **Legibility check:** reading photos must stay legible (instrument display readable). The size/quality setting is verified on real photos from the tablet and is adjustable.
- **EXIF is lost in compression**, so capture time, user, device and (if permitted) location are recorded by the server at upload, together with a SHA-256 of the stored file.
- **PDFs** (BOLs submitted as PDF): size cap per file; compress if a tool is available on the host, else reject oversize with a message asking for a photo or smaller file. **[Builder]**
- Files are served through the authenticated file endpoint only (StudioX default).

### 5.9 Audit and AFAS
- Every technician and admin action (create, edit, reading, photo, signature, release, correction) is written to the StudioX audit trail, which is hash-chained with an integrity check.
- Per treatment, an **audit pack** can be exported (ROF, readings, standards applied, photos, signatures, forecast source, equipment and technician details, gas used, activity log, integrity result) for BAF or a joint system review. **[Decided that activity must be traceable and available; the pack format is My call]**
- AFAS/BAF require records to be kept **longer than two years** **[Decided]**; the owner set it at **at least 5 years**. Retention is a setting (minimum years, default 5), and fumigation records, photos and signatures are never purged before it elapses; purging after that is an admin action, never automatic.

### 5.10 Phase 2 readiness
Keep a generic **Service Request → Job → Invoice** backbone, with *fumigation treatment* as one job type and its ROF engine separate from the backbone, so pest control and hygiene jobs can add their own job types and forms later. Technician, equipment, stock, signing, audit and ERPX components must not assume methyl bromide. **[Decided that phase 2 follows; structure is My call]**

## 6. Left to the builder
Screen layout and tablet UX; exact field names and tables; how the plugin or module registers menus and APIs (verify first); signature pad implementation; forecast provider client and caching; queue and retry mechanics for ERPX; PDF layout of ROF and certificate; reminder scheduling (cron on shared hosting); test plan.

## 7. Open questions for the owner
None. Resolved: certificates are issued per passing container; treatment schedules are entered manually by the admin; AFAS portal upload stays manual; records are kept at least 5 years.
